<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The authorization scope of the acting API key (spec 011, FR-001…FR-003).
 *
 * Immutable value object resolved once per request by ApiKeyAuth and parked
 * on IspContext. It is the single source for every permission predicate and
 * module gate this feature introduces — mirroring the legacy session user
 * ($_SESSION['s']['user']) that feeds getAuthSQL()/checkPerm():
 *
 *  - sysUserId / sysGroupId: the key's bound ISPConfig identity;
 *  - groupIds: the sys_user.groups CSV expanded to ints, united with the
 *    key's sys_groupid (legacy predicate parity tform_base.inc.php:1758-1763;
 *    the CSV is how a reseller sees its clients' rows, auth.inc.php:100-121);
 *  - isAdmin: sys_userid == 1 or sys_user.typ == 'admin' (FR-002, parity
 *    tform_base.inc.php:1744 + auth.inc.php:42-49) — bypasses everything;
 *  - clientId: sys_user.client_id (limit gates, reseller parent forcing);
 *  - isReseller(): lazy — client.limit_client != 0 (FR-003, parity
 *    auth.inc.php:60-80 has_clients()).
 */
final class AuthScope
{
    private ?bool $isReseller = null;

    /**
     * @param  array<int, int>  $groupIds  expanded group set (never empty for
     *                                     non-admin scopes with a bound group)
     */
    public function __construct(
        public readonly int $sysUserId,
        public readonly int $sysGroupId,
        public readonly array $groupIds,
        public readonly bool $isAdmin,
        public readonly int $clientId = 0,
    ) {}

    /**
     * Admin scope — no predicate, no gates (dev key, sys_userid 1 keys,
     * typ='admin' users, and the CLI/test default per FR-025).
     */
    public static function admin(int $sysUserId = 1, int $sysGroupId = 1): self
    {
        return new self($sysUserId, $sysGroupId, [$sysGroupId], true);
    }

    /**
     * Reseller detection (legacy auth.inc.php::has_clients — the joined
     * client row's limit_client != 0). Lazy: only the /clients/** module
     * gate needs it. Users without a client row are never resellers.
     */
    public function isReseller(): bool
    {
        if ($this->isReseller === null) {
            $this->isReseller = false;

            if (! $this->isAdmin && $this->clientId > 0 && Schema::hasTable('client')) {
                $limitClient = DB::table('client')
                    ->where('client_id', $this->clientId)
                    ->value('limit_client');

                $this->isReseller = $limitClient !== null && (int) $limitClient !== 0;
            }
        }

        return $this->isReseller;
    }

    /**
     * Apply the legacy getAuthSQL($perm) predicate to a query (parity
     * tform_base.inc.php:1750-1765, letter containment via LIKE '%perm%'):
     *
     *   (sys_userid = :uid AND sys_perm_user LIKE '%p%')
     *   OR (sys_groupid IN (:groupIds) AND sys_perm_group LIKE '%p%')
     *   OR sys_perm_other LIKE '%p%'
     *
     * No-op for admin scopes (legacy returns '1'). The world clause is always
     * appended; the group clause only when the group set is non-empty.
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyReadPredicate($query, string $perm = 'r')
    {
        if ($this->isAdmin) {
            return $query;
        }

        return $query->where(function ($q) use ($perm) {
            $q->where(function ($user) use ($perm) {
                $user->where('sys_userid', $this->sysUserId)
                    ->where('sys_perm_user', 'like', '%'.$perm.'%');
            });

            if ($this->groupIds !== []) {
                $q->orWhere(function ($group) use ($perm) {
                    $group->whereIn('sys_groupid', $this->groupIds)
                        ->where('sys_perm_group', 'like', '%'.$perm.'%');
                });
            }

            $q->orWhere('sys_perm_other', 'like', '%'.$perm.'%');
        });
    }

    /**
     * In-memory checkPerm equivalent (legacy tform.inc.php:81-86 re-selects
     * the row with getAuthSQL($perm) ANDed; the row is already loaded here,
     * so the triplet is evaluated against its raw attributes).
     *
     * @param  array<string, mixed>  $row  raw attributes incl. sys fields
     */
    public function allows(array $row, string $perm): bool
    {
        if ($this->isAdmin) {
            return true;
        }

        if ((int) ($row['sys_userid'] ?? 0) === $this->sysUserId
            && str_contains((string) ($row['sys_perm_user'] ?? ''), $perm)) {
            return true;
        }

        if (in_array((int) ($row['sys_groupid'] ?? 0), $this->groupIds, true)
            && str_contains((string) ($row['sys_perm_group'] ?? ''), $perm)) {
            return true;
        }

        return str_contains((string) ($row['sys_perm_other'] ?? ''), $perm);
    }
}
