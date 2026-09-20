<?php

namespace App\Services;

use App\Models\BaseModel;
use App\Models\DnsRecord;
use App\Models\DnsSoa;
use App\Models\MailAliasDomain;
use App\Models\MailDomain;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Coordinated alias services; only relationship metadata lives outside ISPConfig (spec 045). */
class AliasServicesService
{
    private bool $internal = false;

    private ?bool $available = null;

    private ?array $links = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('api_alias_services');
    }

    private function links(): array
    {
        if ($this->links === null) {
            $scope = app(IspContext::class)->authScope();
            $this->links = ! $this->available() ? [] : DB::table('api_alias_services')
                ->when(! $scope->isAdmin, fn ($q) => $q->whereIn('sys_groupid', $scope->groupIds))
                ->get()->keyBy('web_domain_id')->all();
        }

        return $this->links;
    }

    public function websiteMetadata(int $id): ?array
    {
        $link = $this->links()[$id] ?? null;
        if ($link === null) {
            return null;
        }

        // Effective mail state is batch-loaded, including changes made from the Mail API or ISPConfig.
        $mail = $this->mailStates();

        return [
            'dns_zone_id' => (int) $link->dns_zone_id,
            'primary_zone_id' => $link->primary_zone_id === null ? null : (int) $link->primary_zone_id,
            'primary_domain' => $link->primary_domain,
            'dns_sync' => (bool) $link->dns_sync,
            'mail_service' => $mail[(int) $link->mail_alias_id] ?? false,
            'mail_alias_id' => $link->mail_alias_id === null ? null : (int) $link->mail_alias_id,
        ];
    }

    private ?array $mailState = null;

    private function mailStates(): array
    {
        if ($this->mailState === null) {
            $ids = array_filter(array_map(fn ($link) => $link->mail_alias_id, $this->links()));
            $this->mailState = $ids === [] ? [] : DB::table('mail_forwarding')->whereIn('forwarding_id', $ids)
                ->where('type', 'aliasdomain')->get()->mapWithKeys(fn ($row) => [(int) $row->forwarding_id => $row->active === 'y'])->all();
        }

        return $this->mailState;
    }

    private ?array $mailAliases = null;

    /** Mail lists distinguish actual alias routing from standalone mailbox domains in one batch. */
    public function mailDomainMetadata(string $domain): ?array
    {
        if ($this->mailAliases === null) {
            $this->mailAliases = [];
            if (Schema::hasTable('mail_forwarding')) {
                $scope = app(IspContext::class)->authScope();
                $domains = $scope->applyReadPredicate(DB::table('mail_domain'), 'r')->pluck('domain_id', 'domain');
                $aliases = $scope->applyReadPredicate(DB::table('mail_forwarding')->where('type', 'aliasdomain'), 'r')->get();
                $websites = Schema::hasTable('web_domain') ? $scope->applyReadPredicate(DB::table('web_domain'), 'r')
                    ->whereIn('type', ['alias', 'vhostalias'])->get(['domain_id', 'domain', 'type', 'parent_domain_id', 'sys_groupid'])
                    ->groupBy(fn ($row) => $row->sys_groupid.':'.$row->domain) : collect();
                foreach ($aliases as $alias) {
                    $name = ltrim($alias->source, '@');
                    $target = ltrim($alias->destination, '@');
                    $matches = $websites->get($alias->sys_groupid.':'.$name, collect());
                    $website = $matches->count() === 1 ? $matches->first() : null;
                    $this->mailAliases[$name] = ['domain' => $target, 'active' => $alias->active === 'y',
                        'mail_domain_id' => isset($domains[$target]) ? (int) $domains[$target] : null,
                        'website' => $website === null ? null : ['id' => (int) $website->domain_id,
                            'type' => $website->type, 'parent_domain_id' => (int) $website->parent_domain_id]];
                }
            }
        }

        return $this->mailAliases[$domain] ?? null;
    }

    public function zoneMetadata(int $id): ?array
    {
        foreach ($this->links() as $link) {
            if ((int) $link->dns_zone_id === $id) {
                return ['web_domain_id' => (int) $link->web_domain_id,
                    'primary_zone_id' => $link->primary_zone_id === null ? null : (int) $link->primary_zone_id,
                    'primary_domain' => $link->primary_domain, 'enabled' => (bool) $link->dns_sync];
            }
        }

        return null;
    }

    /** Called inside the web-domain transaction. Legacy requests without either switch stay unchanged. */
    public function configure(BaseModel $alias, array $payload): void
    {
        if (! array_key_exists('dns_sync', $payload) && ! array_key_exists('mail_service', $payload)) {
            return;
        }
        if (! in_array($alias->type, ['alias', 'vhostalias'], true)) {
            throw ValidationException::withMessages(['dns_sync' => 'These services apply only to alias domains.']);
        }
        if (! $this->available()) {
            throw new ConflictHttpException('Alias services require the API database migration.');
        }
        $group = (int) $alias->sys_groupid;
        $scope = app(IspContext::class)->authScope();
        $parent = DB::table('web_domain')->where('domain_id', $alias->parent_domain_id)->first();
        if ($parent === null || (int) $parent->sys_groupid !== $group
            || (! $scope->isAdmin && ! in_array($group, $scope->groupIds, true))) {
            throw new AuthorizationException('The parent website is not owned by this account.');
        }
        $link = DB::table('api_alias_services')->where('web_domain_id', $alias->getKey())->lockForUpdate()->first();
        $sync = (bool) ($payload['dns_sync'] ?? $link?->dns_sync ?? false);
        $primary = DnsSoa::query()->where('origin', $parent->domain.'.')->where('sys_groupid', $group)->first();
        if ($sync && ($primary === null || $this->zoneMetadata((int) $primary->getKey()) !== null)) {
            throw ValidationException::withMessages(['dns_sync' => 'Create an independent DNS zone for the primary website before enabling synchronization.']);
        }
        $zone = DnsSoa::query()->where('origin', $alias->domain.'.')->lockForUpdate()->first();
        if ($zone !== null && (int) $zone->sys_groupid !== $group) {
            throw new ConflictHttpException('This DNS domain is already in use.');
        }
        $created = $zone === null;
        $this->internal = true;
        try {
            if ($created) {
                $zone = $this->createZone($alias, $parent, $primary);
            }
            $other = DB::table('api_alias_services')->where('dns_zone_id', $zone->id)->where('web_domain_id', '!=', $alias->getKey())->exists();
            if ($other) {
                throw new ConflictHttpException('This DNS domain is already managed by another alias.');
            }
            $values = ['sys_groupid' => $group, 'dns_zone_id' => $zone->id, 'primary_zone_id' => $primary?->id,
                'primary_domain' => $parent->domain, 'dns_sync' => $sync];
            DB::table('api_alias_services')->updateOrInsert(['web_domain_id' => $alias->getKey()], $values);
            if (($created || $sync) && $primary !== null) {
                $this->copyZone($alias->getKey(), $primary, $zone, $link === null || ! $link->dns_sync);
            }
            if (array_key_exists('mail_service', $payload)) {
                $this->configureMail($alias, $parent, (bool) $payload['mail_service']);
            }
        } finally {
            $this->internal = false;
            $this->links = null;
            $this->mailState = null;
        }
    }

    private function createZone(BaseModel $alias, object $parent, ?DnsSoa $primary): DnsSoa
    {
        $serial = app(DnsSerialService::class);
        if ($primary !== null) {
            $zone = new DnsSoa($this->zoneFields($primary, $alias->domain.'.'));
        } else {
            $client = (int) DB::table('sys_group')->where('groupid', $alias->sys_groupid)->value('client_id');
            $addresses = app(HostingAddressService::class)->addresses($client);
            $dns = $addresses['dns'][0] ?? null;
            if ($dns === null || empty($dns['nameservers'])) {
                throw ValidationException::withMessages(['dns_sync' => 'No DNS server and name servers are available for this account.']);
            }
            $zone = new DnsSoa(['origin' => $alias->domain.'.', 'server_id' => $dns['server_id'],
                'ns' => rtrim($dns['nameservers'][0]['name'], '.').'.', 'mbox' => 'hostmaster.'.$alias->domain.'.']);
        }
        $zone->forceFill(['sys_groupid' => (int) $alias->sys_groupid, 'sys_userid' => (int) $alias->sys_userid,
            'serial' => $serial->increaseSerial(null)]);
        $zone->save();
        if ($primary === null) {
            foreach ($dns['nameservers'] as $ns) {
                $this->newRecord($zone, ['name' => $zone->origin, 'type' => 'NS', 'data' => rtrim($ns['name'], '.').'.']);
            }
            $web = collect($addresses['web'])->firstWhere('server_id', (int) $parent->server_id);
            foreach (['A' => 'ipv4', 'AAAA' => 'ipv6'] as $type => $key) {
                $ips = $web[$key] ?? [];
                $configured = $type === 'A' ? ($parent->ip_address ?? '') : ($parent->ipv6_address ?? '');
                if (filter_var($configured, FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) {
                    $ips = [$configured];
                }
                foreach ($ips as $ip) {
                    foreach ([$zone->origin, 'www'] as $name) {
                        $this->newRecord($zone, ['name' => $name, 'type' => $type, 'data' => $ip]);
                    }
                }
            }
        }

        return $zone;
    }

    private function zoneFields(DnsSoa $primary, string $origin): array
    {
        $fields = array_intersect_key($primary->attributesToArray(), array_flip([
            'server_id', 'ns', 'mbox', 'refresh', 'retry', 'expire', 'minimum', 'ttl', 'active',
            'xfer', 'also_notify', 'update_acl', 'dnssec_wanted', 'dnssec_algo',
        ]));
        $fields['origin'] = $origin;
        foreach (['ns', 'mbox'] as $key) {
            $fields[$key] = $this->dnsName((string) $fields[$key], $primary->origin, $origin);
        }

        return $fields;
    }

    /** Only DNS-name fields are rewritten; opaque TXT/CAA data and external targets retain their exact bytes. */
    private function dnsName(string $name, string $from, string $to): string
    {
        $a = rtrim($from, '.');
        $value = rtrim($name, '.');
        if (strcasecmp($value, $a) === 0 || str_ends_with(strtolower($value), '.'.strtolower($a))) {
            return substr($value, 0, strlen($value) - strlen($a)).rtrim($to, '.').(str_ends_with($name, '.') ? '.' : '');
        }

        return $name;
    }

    private function recordFields(DnsRecord $source, string $from, string $to): array
    {
        $row = array_intersect_key($source->attributesToArray(), array_flip(['name', 'type', 'data', 'aux', 'ttl', 'active']));
        $row['name'] = $this->dnsName($row['name'], $from, $to);
        if (in_array($row['type'], ['CNAME', 'DNAME', 'MX', 'NS', 'PTR'], true)) {
            $row['data'] = $this->dnsName($row['data'], $from, $to);
        } elseif ($row['type'] === 'SRV') {
            $row['data'] = preg_replace_callback('/(\S+)\s*$/', fn ($m) => $this->dnsName($m[1], $from, $to), $row['data']);
        }

        return $row;
    }

    private function newRecord(DnsSoa $zone, array $fields): DnsRecord
    {
        $record = new DnsRecord($fields + ['zone' => $zone->id]);
        $record->forceFill(['server_id' => $zone->server_id, 'sys_groupid' => $zone->sys_groupid, 'sys_userid' => $zone->sys_userid,
            'serial' => app(DnsSerialService::class)->increaseSerial(null), 'stamp' => app(DnsSerialService::class)->timestamp()]);
        $record->save();

        return $record;
    }

    private function copyZone(int $aliasId, DnsSoa $primary, DnsSoa $zone, bool $reset = false): void
    {
        $link = DB::table('api_alias_services')->where('web_domain_id', $aliasId)->lockForUpdate()->first();
        $map = $reset ? [] : (json_decode($link->record_map ?? '{}', true) ?: []);
        $existing = $zone->records()->orderBy('id')->get()->keyBy('id');
        $changed = $reset && $existing->isNotEmpty();
        if ($reset) {
            foreach ($existing as $record) {
                $record->delete();
            }
            $existing = collect();
        }
        $next = [];
        foreach ($primary->records()->orderBy('id')->get() as $source) {
            // Server-generated zone signatures/keys must be generated for the alias itself.
            if (in_array($source->type, ['DNSKEY', 'RRSIG', 'NSEC', 'NSEC3', 'NSEC3PARAM'], true)) {
                continue;
            }
            $fields = $this->recordFields($source, $primary->origin, $zone->origin);
            $target = $existing->get((int) ($map[$source->id] ?? 0));
            if ($target === null) {
                $target = $this->newRecord($zone, $fields);
                $changed = true;
            } else {
                $target->fill($fields);
                if ($target->isDirty()) {
                    $target->forceFill(['serial' => app(DnsSerialService::class)->increaseSerial($target->serial),
                        'stamp' => app(DnsSerialService::class)->timestamp()]);
                    $target->save();
                    $changed = true;
                }
            }
            $next[$source->id] = $target->id;
        }
        foreach ($existing as $record) {
            if (! in_array($record->id, $next, true)) {
                $record->delete();
                $changed = true;
            }
        }
        $zone->fill($this->zoneFields($primary, $zone->origin));
        if ($zone->isDirty() || $changed) {
            $zone->serial = app(DnsSerialService::class)->increaseSerial($zone->serial);
            $zone->save();
        }
        DB::table('api_alias_services')->where('web_domain_id', $aliasId)->update(['record_map' => json_encode($next)]);
    }

    private function configureMail(BaseModel $alias, object $parent, bool $enabled): void
    {
        $link = DB::table('api_alias_services')->where('web_domain_id', $alias->getKey())->first();
        if (! $enabled && $link->mail_alias_id === null) {
            return;
        }
        $group = (int) $alias->sys_groupid;
        $destination = MailDomain::query()->where('domain', $parent->domain)->where('sys_groupid', $group)->first();
        if ($enabled && ($destination === null || ! $destination->active)) {
            throw ValidationException::withMessages(['mail_service' => 'Enable mail for the primary domain before enabling its alias.']);
        }
        $source = MailDomain::query()->where('domain', $alias->domain)->first();
        if ($source !== null && (int) $source->sys_groupid !== $group) {
            throw new ConflictHttpException('This mail domain is already in use.');
        }
        $forward = MailAliasDomain::query()->aliasDomains()->where('source', '@'.$alias->domain)->first();
        if ($forward !== null && ((int) $forward->sys_groupid !== $group || $forward->destination !== '@'.$parent->domain)) {
            throw new ConflictHttpException('This mail alias already has a different destination.');
        }
        if ($enabled && $source !== null && ! $link->created_mail_domain) {
            $suffix = '%@'.str_replace(['%', '_'], ['\\%', '\\_'], $alias->domain);
            if (DB::table('mail_user')->where('email', 'like', $suffix)->exists()
                || DB::table('mail_forwarding')->where('source', 'like', $suffix)->where('type', '!=', 'aliasdomain')->exists()) {
                throw new ConflictHttpException('This mail domain already contains mailboxes or forwarding rules.');
            }
        }
        if ($enabled) {
            if ($source === null) {
                $source = new MailDomain(['domain' => $alias->domain, 'server_id' => $destination->server_id, 'active' => true]);
                $source->forceFill(['sys_groupid' => $group, 'sys_userid' => $alias->sys_userid]);
                $source->save();
                DB::table('api_alias_services')->where('web_domain_id', $alias->getKey())->update(['created_mail_domain' => true]);
            } elseif ((int) $source->server_id !== (int) $destination->server_id || ! $source->active) {
                if (! $link->created_mail_domain || (int) $source->server_id !== (int) $destination->server_id) {
                    throw new ConflictHttpException('The existing mail domain is not active on the primary mail server.');
                }
                $source->active = true;
                $source->save();
            }
            $forward ??= new MailAliasDomain(['source' => '@'.$alias->domain, 'destination' => '@'.$parent->domain]);
            $forward->forceFill(['server_id' => $destination->server_id, 'sys_groupid' => $group, 'sys_userid' => $alias->sys_userid]);
        }
        if ($forward !== null) {
            $forward->active = $enabled;
            $forward->save();
        }
        if (! $enabled && $source !== null && $link->created_mail_domain) {
            $source->active = false;
            $source->save();
        }
        DB::table('api_alias_services')->where('web_domain_id', $alias->getKey())->update([
            'mail_alias_id' => $forward?->id, 'mail_domain_id' => $source?->getKey(),
        ]);
    }

    public function assertWebsiteIdentity(string $table, array $old, array $new): void
    {
        if ($table !== 'web_domain' || $old === [] || ! $this->available()) {
            return;
        }
        if (($old['domain'] ?? '') === ($new['domain'] ?? '')
            && (int) ($old['parent_domain_id'] ?? 0) === (int) ($new['parent_domain_id'] ?? 0)
            && (int) ($old['sys_groupid'] ?? 0) === (int) ($new['sys_groupid'] ?? 0)) {
            return;
        }
        if (DB::table('api_alias_services')->where('web_domain_id', $old['domain_id'])->exists()) {
            throw new ConflictHttpException('A managed alias cannot be renamed, reparented or reassigned. Remove and recreate the website alias.');
        }
    }

    /** Protect both old and new zones of a moved record, before any physical write. */
    public function assertDnsWrite(string $table, array $old, array $new, bool $delete = false): void
    {
        if ($this->internal || ! in_array($table, ['dns_soa', 'dns_rr'], true) || ! $this->available()) {
            return;
        }
        $ids = $table === 'dns_soa' ? [(int) ($old['id'] ?? $new['id'] ?? 0)]
            : [(int) ($old['zone'] ?? 0), (int) ($new['zone'] ?? 0)];
        foreach (DB::table('api_alias_services')->whereIn('dns_zone_id', $ids)->orWhereIn('primary_zone_id', $ids)->get() as $link) {
            if (in_array((int) $link->dns_zone_id, $ids, true) && ($link->dns_sync || ($delete && $table === 'dns_soa'))) {
                throw new ConflictHttpException('This DNS zone is managed by its website alias. Disable DNS synchronization in the alias settings before editing it.');
            }
            if ($delete && $table === 'dns_soa' && $link->dns_sync && in_array((int) $link->primary_zone_id, $ids, true)) {
                throw new ConflictHttpException('Disable DNS synchronization on this domain\'s aliases before deleting its zone.');
            }
        }
    }

    /** All API DNS writers converge in the journal, including DKIM and zone serial side effects. */
    public function changed(string $table, array $old, array $new): void
    {
        if ($this->internal || ! $this->available()) {
            return;
        }
        if ($table === 'web_domain' && $new === []) {
            $id = (int) ($old['domain_id'] ?? 0);
            $link = $this->links()[$id] ?? null;
            if ($link !== null) {
                $this->internal = true;
                try {
                    $forward = $link->mail_alias_id === null ? null : MailAliasDomain::query()->aliasDomains()->find($link->mail_alias_id);
                    if ($forward !== null && (int) $forward->sys_groupid === (int) $link->sys_groupid) {
                        $forward->active = false;
                        $forward->save();
                    }
                    DB::table('api_alias_services')->where('web_domain_id', $id)->delete();
                    $this->links = null;
                } finally {
                    $this->internal = false;
                }
            }
        }
        if (! in_array($table, ['dns_soa', 'dns_rr'], true)) {
            return;
        }
        $ids = $table === 'dns_soa' ? [(int) ($old['id'] ?? $new['id'] ?? 0)]
            : [(int) ($old['zone'] ?? 0), (int) ($new['zone'] ?? 0)];
        foreach ($this->links() as $link) {
            if ($link->dns_sync && in_array((int) $link->primary_zone_id, $ids, true)) {
                $this->reconcile((int) $link->web_domain_id);
            }
        }
    }

    /** Full snapshot comparison also catches external ISPConfig writes without relying on journal retention. */
    public function reconcile(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $link = DB::table('api_alias_services')->where('web_domain_id', $id)->lockForUpdate()->first();
            if ($link === null || ! $link->dns_sync) {
                return;
            }
            $primary = DnsSoa::query()->find($link->primary_zone_id);
            $zone = DnsSoa::query()->lockForUpdate()->find($link->dns_zone_id);
            $alias = DB::table('web_domain')->where('domain_id', $id)->first();
            if ($primary === null || $zone === null || $alias === null
                || (int) $primary->sys_groupid !== (int) $link->sys_groupid
                || (int) $zone->sys_groupid !== (int) $link->sys_groupid
                || (int) $alias->sys_groupid !== (int) $link->sys_groupid
                || $zone->origin !== $alias->domain.'.') {
                throw new ConflictHttpException('Alias DNS synchronization requires its original owned website and zones.');
            }
            $this->internal = true;
            try {
                $this->copyZone($id, $primary, $zone);
            } finally {
                $this->internal = false;
            }
        });
    }
}
