<?php

namespace App\Services;

use App\Models\BaseModel;
use App\Support\AuthScope;
use App\Support\IspContext;
use App\Support\LimitSpec;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client resource-limit checks (spec 011 access gate + spec 012 counting).
 *
 * P2 (spec 011) shipped the security-critical access gate only (FR-017):
 * resource types whose legacy limit column defaults to 0 — mail routing
 * (transports), mail access rules and the spamfilter wblist — are DISABLED
 * for a client until the operator books them (limit != 0). Legacy "enforces"
 * this by hiding the menu item; the API hardens it into a hard 403
 * (resourceEnabled below, used by App\Http\Middleware\RequireClientLimit).
 *
 * Spec 012 adds the full checkClientLimit()/checkResellerLimit() counting
 * parity (tform.inc.php:183-250 — -1 unlimited / 0 disabled / n counted with
 * the 'u' predicate, plus the reseller cap) as checkCreate(), and the three
 * SUM-of-quota caps (P3, mail_user_edit.php:216-219, web_vhost:1120-1142,
 * database_edit.php:280-281) as checkQuotaSum(). Both are invoked from the one
 * chokepoint every write flows through — App\Models\BaseModel::save() — so no
 * controller needs to know about limits (plan.md Structure Decision).
 *
 * The resource map (countSpecsFor / quotaSpecsFor) is the single source of
 * truth and encodes the spec's classification table verbatim; unmapped tables
 * are simply not limited.
 */
class ClientLimitService
{
    /**
     * Whether the acting scope may use a limit-gated resource type at all.
     *
     * Rules (parity auth.inc.php:124-146 get_client_limit):
     *  - admin scopes: always allowed;
     *  - keys bound to users without a client row: unlimited (-1), allowed;
     *  - otherwise: allowed iff the client's limit column is non-zero
     *    (-1 = unlimited, n > 0 = booked; 0 = feature not booked).
     */
    public function resourceEnabled(AuthScope $scope, string $limitColumn): bool
    {
        if ($scope->isAdmin) {
            return true;
        }

        $limit = $this->limitValue($scope, $limitColumn);

        return $limit === null || $limit !== 0;
    }

    /**
     * Row-count enforcement on create (spec 012 FR-001…FR-005; parity
     * checkClientLimit tform.inc.php:197-205 + checkResellerLimit :211-250).
     *
     * Admin scopes, keys without a client row, and unmapped tables pass
     * unconditionally. For every mapped spec the acting client's cap is
     * enforced (count under the 'u' or bespoke 'grp' predicate + the type
     * filter, deny when count >= limit), then — for the 'u' specs — the parent
     * reseller's cap. Throws before any DB write, so a denial writes no
     * sys_datalog row (the caller invokes this before parent::save()).
     */
    public function checkCreate(BaseModel $model): void
    {
        $scope = $this->scope();

        if ($scope->isAdmin) {
            return;
        }

        $specs = $this->countSpecsFor($model);

        if ($specs === []) {
            return;
        }

        $client = $this->clientRow($scope);

        if ($client === null) {
            return; // no client row = unlimited (get_client_limit -> -1)
        }

        foreach ($specs as $spec) {
            $this->enforceClientCount($scope, $client, $spec);

            if ($spec->resellerCap) {
                $this->enforceResellerCount($client, $spec);
            }
        }
    }

    /**
     * Quota-SUM enforcement on create AND update (spec 012 FR-022…FR-024).
     *
     * Sums the resource's quota column over the client's existing rows
     * (excluding the row being updated), adds the model's requested quota
     * (converted to MB), and denies when the sum exceeds the limit or when the
     * requested quota is "unlimited" while the account has a finite cap. The
     * parent reseller's SUM is enforced too. Admin/unmapped/no-client short
     * circuit. Parity: mail_user_edit.php:216-219, web_vhost:1120-1142,
     * database_edit.php:280-281.
     */
    public function checkQuotaSum(BaseModel $model): void
    {
        $scope = $this->scope();

        if ($scope->isAdmin) {
            return;
        }

        $specs = $this->quotaSpecsFor($model);

        if ($specs === []) {
            return;
        }

        $client = $this->clientRow($scope);

        if ($client === null) {
            return;
        }

        $excludeId = $model->exists ? (int) $model->getKey() : null;

        foreach ($specs as $spec) {
            $newQuota = $this->quotaToMb($model->getAttribute($spec->quotaColumn), $spec);

            $this->enforceClientQuota($scope, $client, $spec, $newQuota, $excludeId);

            if ($spec->resellerCap) {
                $this->enforceResellerQuota($client, $spec, $newQuota, $excludeId);
            }
        }
    }

    // ------------------------------------------------------------------
    // Client cap
    // ------------------------------------------------------------------

    /**
     * @param  object  $client  the acting client row (limit columns + parent_client_id)
     */
    protected function enforceClientCount(AuthScope $scope, object $client, LimitSpec $spec): void
    {
        $limit = $this->columnValue($client, $spec->limitColumn);

        if ($limit === null || $limit < 0) {
            return; // -1 = unlimited (tform.inc.php:197 `if number >= 0`)
        }

        $query = DB::table($spec->table);

        if ($spec->predicate === 'grp') {
            $query->where('sys_groupid', $scope->sysGroupId);
        } else {
            $scope->applyReadPredicate($query, 'u');
        }

        $this->applyTypeFilter($query, $spec);

        if ($query->count() >= $limit) {
            $this->deny($spec->label, false); // 0 -> count >= 0 always (disabled)
        }
    }

    protected function enforceClientQuota(AuthScope $scope, object $client, LimitSpec $spec, int $newQuota, ?int $excludeId): void
    {
        $limit = $this->columnValue($client, $spec->limitColumn);

        if ($limit === null || $limit < 0) {
            return; // -1 = unlimited
        }

        $query = DB::table($spec->table);

        if ($spec->predicate === 'grp') {
            $query->where('sys_groupid', $scope->sysGroupId);
        } else {
            $scope->applyReadPredicate($query, 'u');
        }

        $this->applyTypeFilter($query, $spec);

        if ($excludeId !== null) {
            $query->where($spec->pk, '!=', $excludeId);
        }

        $used = $this->sumToMb($query->sum($spec->quotaColumn), $spec);

        $this->denyQuotaIfExceeded($used, $newQuota, $limit, $spec, false);
    }

    // ------------------------------------------------------------------
    // Reseller cap (parity checkResellerLimit tform.inc.php:211-250)
    // ------------------------------------------------------------------

    protected function enforceResellerCount(object $client, LimitSpec $spec): void
    {
        $reseller = $this->resellerContext($client);

        if ($reseller === null) {
            return;
        }

        $limit = $reseller['limit'][$spec->limitColumn] ?? null;

        if ($limit === null || $limit < 0) {
            return;
        }

        $query = DB::table($spec->table);
        $this->applyResellerPredicate($query, $reseller);
        $this->applyTypeFilter($query, $spec);

        if ($query->count() >= $limit) {
            $this->deny($spec->label, true);
        }
    }

    protected function enforceResellerQuota(object $client, LimitSpec $spec, int $newQuota, ?int $excludeId): void
    {
        $reseller = $this->resellerContext($client);

        if ($reseller === null) {
            return;
        }

        $limit = $reseller['limit'][$spec->limitColumn] ?? null;

        if ($limit === null || $limit < 0) {
            return;
        }

        $query = DB::table($spec->table);
        $this->applyResellerPredicate($query, $reseller);
        $this->applyTypeFilter($query, $spec);

        if ($excludeId !== null) {
            $query->where($spec->pk, '!=', $excludeId);
        }

        $used = $this->sumToMb($query->sum($spec->quotaColumn), $spec);

        $this->denyQuotaIfExceeded($used, $newQuota, $limit, $spec, true);
    }

    /**
     * Resolve (and memoize per checkCreate/checkQuotaSum call chain) the parent
     * reseller's userid, group set and per-column limits. Returns null when the
     * acting client has no reseller (parent_client_id == 0) or the reseller's
     * sys_user is missing.
     *
     * @return array{userid: int, groupIds: array<int, int>, limit: array<string, int|null>}|null
     */
    protected function resellerContext(object $client): ?array
    {
        $parentId = (int) ($client->parent_client_id ?? 0);

        if ($parentId <= 0 || ! Schema::hasTable('client') || ! Schema::hasTable('sys_user')) {
            return null;
        }

        $user = DB::table('sys_user')->where('client_id', $parentId)->first(['userid', 'groups']);

        if ($user === null) {
            return null;
        }

        $groupIds = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($user->groups ?? ''))
        ), fn (int $g): bool => $g > 0));

        $limitRow = DB::table('client')->where('client_id', $parentId)->first();

        $limits = [];

        if ($limitRow !== null) {
            foreach ((array) $limitRow as $column => $value) {
                $limits[$column] = $value === null ? null : (int) $value;
            }
        }

        return [
            'userid' => (int) $user->userid,
            'groupIds' => $groupIds,
            'limit' => $limits,
        ];
    }

    /**
     * The raw reseller count/sum predicate (tform.inc.php:238-242) — NOT the
     * 'u' perm-letter triplet: `(sys_groupid IN (reseller.groups) OR sys_userid
     * = reseller.userid)`.
     *
     * @param  Builder  $query
     * @param  array{userid: int, groupIds: array<int, int>, limit: array<string, int|null>}  $reseller
     */
    protected function applyResellerPredicate($query, array $reseller): void
    {
        $query->where(function ($q) use ($reseller): void {
            if ($reseller['groupIds'] !== []) {
                $q->whereIn('sys_groupid', $reseller['groupIds']);
                $q->orWhere('sys_userid', $reseller['userid']);
            } else {
                $q->where('sys_userid', $reseller['userid']);
            }
        });
    }

    // ------------------------------------------------------------------
    // Resource map — the spec 012 classification table (single source)
    // ------------------------------------------------------------------

    /**
     * Row-count LimitSpecs for a model's create, resolved by table and (for the
     * type-discriminated tables) the row's `type` attribute. Returns [] for
     * unmapped tables — that is how dns_rr (no limit_dns_record), the
     * admin-only spamfilter policy/user tables, /clients/domains (NC-1, a
     * behavioral toggle, not a count) and every non-scoped table are excluded
     * by construction.
     *
     * @return array<int, LimitSpec>
     */
    protected function countSpecsFor(BaseModel $model): array
    {
        $table = $model->getTable();
        $type = (string) ($model->getAttribute('type') ?? '');

        return match ($table) {
            // --- P1: high-value counts (all 'u' predicate) ---
            'mail_domain' => [$this->count('limit_maildomain', 'mail_domain', 'domain_id', null, 'mail domains')],
            'mail_user' => [$this->count('limit_mailbox', 'mail_user', 'mailuser_id', null, 'mailboxes')],
            'web_database' => $this->databaseCountSpecs($type),
            'ftp_user' => [$this->count('limit_ftp_user', 'ftp_user', 'ftp_user_id', null, 'FTP users')],
            'shell_user' => [$this->count('limit_shell_user', 'shell_user', 'shell_user_id', null, 'shell users')],
            'dns_soa' => [$this->count('limit_dns_zone', 'dns_soa', 'id', null, 'DNS zones')],
            // web_domain covers both WebDomain (vhost*) and WebChildDomain
            // (subdomain/alias) — resolved by the row's type (P1 vhost + P2 child)
            'web_domain' => $this->webDomainCountSpecs($type),

            // --- P2: remaining counts ---
            // mail_forwarding is one table with four type-selected limits
            'mail_forwarding' => $this->mailForwardingCountSpecs($type),
            'mail_user_filter' => [$this->count('limit_mailfilter', 'mail_user_filter', 'filter_id', null, 'mail filters')],
            'mail_get' => [$this->count('limit_fetchmail', 'mail_get', 'mailget_id', null, 'fetchmail accounts')],
            'webdav_user' => [$this->count('limit_webdav_user', 'webdav_user', 'webdav_user_id', null, 'WebDAV users')],
            'cron' => [$this->count('limit_cron', 'cron', 'id', null, 'cron jobs')],
            'web_database_user' => [$this->count('limit_database_user', 'web_database_user', 'database_user_id', null, 'database users')],
            'dns_slave' => [$this->count('limit_dns_slave_zone', 'dns_slave', 'id', null, 'DNS slave zones')],
            // access-gated in 011 (limit == 0 -> RequireClientLimit 403); the
            // count layer here enforces the booked n > 0 cap
            'mail_transport' => [$this->count('limit_mailrouting', 'mail_transport', 'transport_id', null, 'mail transports')],
            'mail_access' => [$this->count('limit_mail_wblist', 'mail_access', 'access_id', null, 'mail access rules')],
            'spamfilter_wblist' => [$this->count('limit_spamfilter_wblist', 'spamfilter_wblist', 'wblist_id', null, 'spamfilter whitelist/blacklist entries')],
            // bespoke: reseller creating a client — sys_groupid predicate, no
            // reseller cap (client_edit.php:68); limit_client cap only
            'client' => [new LimitSpec('limit_client', 'client', 'client_id', null, 'grp', false, 'clients')],

            // NOT wired (documented, spec Edge Cases / T021):
            //  - dns_rr: no checkClientLimit('limit_dns_record') call site anywhere
            //  - spamfilter_users / spamfilter_policy: admin-only writes (011 FR-017)
            //  - domain (/clients/domains): limit_domainmodule is a behavioral
            //    toggle, not a count (owner decision NC-1)
            default => [],
        };
    }

    /**
     * Quota-SUM LimitSpecs for a model, resolved by table (P3). web_domain
     * quota caps apply only to the top-level vhost (legacy checks hd_quota only
     * when _vhostdomain_type == 'domain', i.e. type == 'vhost'); child domains
     * carry no user-set quota and are excluded here.
     *
     * @return array<int, LimitSpec>
     */
    protected function quotaSpecsFor(BaseModel $model): array
    {
        $table = $model->getTable();
        $type = (string) ($model->getAttribute('type') ?? '');

        return match ($table) {
            // mail_user.quota is stored in BYTES; limit_mailquota is MB -> /1024/1024
            'mail_user' => [
                new LimitSpec('limit_mailquota', 'mail_user', 'mailuser_id', null, 'u', true, 'mailbox quota', 'quota', 1024 * 1024),
            ],
            'web_domain' => $type === 'vhost' ? [
                new LimitSpec('limit_web_quota', 'web_domain', 'domain_id', ['vhost'], 'u', true, 'web disk quota', 'hd_quota', 1),
                new LimitSpec('limit_traffic_quota', 'web_domain', 'domain_id', null, 'u', true, 'traffic quota', 'traffic_quota', 1),
            ] : [],
            // limit_database_quota uses the bespoke sys_groupid predicate
            'web_database' => [
                new LimitSpec('limit_database_quota', 'web_database', 'database_id', null, 'grp', true, 'database quota', 'database_quota', 1),
            ],
            default => [],
        };
    }

    /**
     * web_domain row-count spec by row kind (spec classification table):
     *  - vhost              -> limit_web_domain      (count type='vhost')
     *  - subdomain/vhostsub -> limit_web_subdomain   (count both)
     *  - alias/vhostalias   -> limit_web_aliasdomain (count both)
     *
     * @return array<int, LimitSpec>
     */
    protected function webDomainCountSpecs(string $type): array
    {
        return match ($type) {
            'vhost' => [$this->count('limit_web_domain', 'web_domain', 'domain_id', ['vhost'], 'websites')],
            'subdomain', 'vhostsubdomain' => [$this->count('limit_web_subdomain', 'web_domain', 'domain_id', ['subdomain', 'vhostsubdomain'], 'subdomains')],
            'alias', 'vhostalias' => [$this->count('limit_web_aliasdomain', 'web_domain', 'domain_id', ['alias', 'vhostalias'], 'alias domains')],
            default => [],
        };
    }

    /**
     * mail_forwarding row-count spec by type (spec classification table).
     *
     * @return array<int, LimitSpec>
     */
    protected function mailForwardingCountSpecs(string $type): array
    {
        return match ($type) {
            'alias' => [$this->count('limit_mailalias', 'mail_forwarding', 'forwarding_id', ['alias'], 'mail aliases')],
            'forward' => [$this->count('limit_mailforward', 'mail_forwarding', 'forwarding_id', ['forward'], 'mail forwards')],
            'catchall' => [$this->count('limit_mailcatchall', 'mail_forwarding', 'forwarding_id', ['catchall'], 'catchall addresses')],
            'aliasdomain' => [$this->count('limit_mailaliasdomain', 'mail_forwarding', 'forwarding_id', ['aliasdomain'], 'mail alias domains')],
            default => [],
        };
    }

    /**
     * web_database row-count specs (spec FR-010 + FR-020). limit_database caps
     * every database the client owns ('u', no type filter, reseller cap); a
     * PostgreSQL create is ADDITIONALLY capped by limit_database_postgresql via
     * the bespoke sys_groupid predicate (database_edit.php:272-274).
     *
     * @return array<int, LimitSpec>
     */
    protected function databaseCountSpecs(string $type): array
    {
        $specs = [$this->count('limit_database', 'web_database', 'database_id', null, 'databases')];

        if ($type === 'postgresql') {
            $specs[] = new LimitSpec('limit_database_postgresql', 'web_database', 'database_id', ['postgresql'], 'grp', false, 'PostgreSQL databases');
        }

        return $specs;
    }

    /**
     * Build a standard 'u'-predicate, reseller-capped row-count spec.
     *
     * @param  array<int, string>|null  $typeValues
     */
    protected function count(string $limitColumn, string $table, string $pk, ?array $typeValues, string $label): LimitSpec
    {
        return new LimitSpec($limitColumn, $table, $pk, $typeValues, 'u', true, $label);
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * @param  Builder  $query
     */
    protected function applyTypeFilter($query, LimitSpec $spec): void
    {
        if ($spec->typeValues !== null) {
            $query->whereIn('type', $spec->typeValues);
        }
    }

    /**
     * Deny quota when the requested value pushes the SUM over the cap, or when
     * it is "unlimited" while the account is finite. Mail treats quota==0 as
     * unlimited; web/database treat quota<=0 as unlimited (parity: the sum
     * check's `new < 0` plus the "not 0 when capped" rule combine to <= 0).
     */
    protected function denyQuotaIfExceeded(int $used, int $newQuota, int $limit, LimitSpec $spec, bool $reseller): void
    {
        $unlimitedRequested = $spec->quotaDivisor > 1
            ? ($newQuota === 0)      // mail: 0 = unlimited
            : ($newQuota <= 0);      // web/db: <= 0 = unlimited

        if (($used + $newQuota > $limit) || $unlimitedRequested) {
            $this->deny($spec->label, $reseller);
        }
    }

    /**
     * Convert a model's requested quota attribute to whole MB.
     */
    protected function quotaToMb(mixed $value, LimitSpec $spec): int
    {
        return (int) ((int) $value / $spec->quotaDivisor);
    }

    /**
     * Convert an existing-rows SUM to whole MB.
     */
    protected function sumToMb(mixed $sum, LimitSpec $spec): int
    {
        return (int) ((int) $sum / $spec->quotaDivisor);
    }

    /**
     * The 403 over-limit denial (spec FR-006). Reuses AuthorizationException —
     * already mapped to 403 application/problem+json by App\Support\Problem,
     * with the message as the human `detail`. The reseller variant is prefixed
     * "Reseller:" (parity mail_domain_edit.php:62).
     */
    protected function deny(string $label, bool $reseller): void
    {
        $detail = "You have reached the maximum number of {$label} allowed for your account.";

        if ($reseller) {
            $detail = 'Reseller: '.$detail;
        }

        throw new AuthorizationException($detail);
    }

    /**
     * The acting client's row (limit columns + parent_client_id), or null when
     * the key's user has no client row (legacy get_client_limit -> -1).
     */
    protected function clientRow(AuthScope $scope): ?object
    {
        if ($scope->clientId <= 0 || ! Schema::hasTable('client')) {
            return null;
        }

        return DB::table('client')->where('client_id', $scope->clientId)->first();
    }

    /**
     * @return int|null the column value, or null when absent
     */
    protected function columnValue(object $row, string $column): ?int
    {
        if (! property_exists($row, $column) && ! isset($row->{$column})) {
            return null;
        }

        $value = $row->{$column} ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * The acting client's value for a limit column, or null when the key's
     * user has no client row (legacy get_client_limit returns -1 there —
     * unlimited). Retained for RequireClientLimit's access gate.
     */
    protected function limitValue(AuthScope $scope, string $limitColumn): ?int
    {
        if ($scope->clientId <= 0 || ! Schema::hasTable('client')) {
            return null;
        }

        $value = DB::table('client')
            ->where('client_id', $scope->clientId)
            ->value($limitColumn);

        return $value === null ? null : (int) $value;
    }

    protected function scope(): AuthScope
    {
        return App::make(IspContext::class)->authScope();
    }
}
