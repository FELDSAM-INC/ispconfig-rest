<?php

namespace App\Services;

use App\Exceptions\InvalidZoneTemplateException;
use App\Models\DnsRecord;
use App\Models\DnsSoa;
use App\Models\DnsTemplate;
use App\Support\IspContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The DNS zone wizard (spec 029): expands a `dns_template` into a zone and
 * its resource records.
 *
 * Legacy: `lib/classes/dns_wizard.inc.php::create()` replaces the
 * placeholders in the template text, parses the `[ZONE]` / `[DNS_RECORDS]`
 * sections, inserts the zone with `active = 'N'`, inserts one `dns_rr` row per
 * template record carrying the zone's `server_id` and `sys_groupid`, and
 * finally activates the zone. Same sequence here, inside one transaction so a
 * refusal or failure leaves nothing behind — legacy can leave a zone stuck
 * inactive.
 */
class DnsZoneWizardService
{
    /** `[ZONE]` keys that map to dns_soa columns; anything else is ignored. */
    public const ZONE_COLUMNS = [
        'origin', 'ns', 'mbox', 'refresh', 'retry', 'expire', 'minimum', 'ttl',
        'xfer', 'also_notify', 'update_acl', 'dnssec_wanted', 'dnssec_algo',
    ];

    /** Keys legacy refuses to create a zone without. */
    public const ZONE_REQUIRED = ['origin', 'ns', 'mbox', 'refresh', 'retry', 'expire', 'minimum', 'ttl'];

    /** The values the `dns_rr.type` enum accepts (ispconfig3.sql). */
    public const RECORD_TYPES = [
        'A', 'AAAA', 'ALIAS', 'CNAME', 'DNAME', 'CAA', 'DS', 'HINFO', 'LOC', 'MX',
        'NAPTR', 'NS', 'PTR', 'RP', 'SRV', 'SSHFP', 'TXT', 'TLSA', 'DNSKEY',
    ];

    /** Placeholder token => request field holding its value. */
    public const PLACEHOLDERS = [
        '{DOMAIN}' => 'domain',
        '{IP}' => 'ip',
        '{IPV6}' => 'ipv6',
        '{NS1}' => 'ns1',
        '{NS2}' => 'ns2',
        '{EMAIL}' => 'email',
    ];

    public function __construct(protected DnsSerialService $serial) {}

    /**
     * Parse a template's text. Throws InvalidZoneTemplateException for
     * anything the legacy parser would choke on.
     *
     * @return array{zone: array<string, string>, records: array<int, array<string, mixed>>}
     */
    public function parse(string $template): array
    {
        $section = null;
        $zone = [];
        $records = [];

        foreach (preg_split('/\r\n|\r|\n/', $template) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '[')) {
                $section = match ($line) {
                    '[ZONE]' => 'zone',
                    '[DNS_RECORDS]' => 'records',
                    default => throw new InvalidZoneTemplateException(
                        'The zone template contains an unknown section '.$line.'.'
                    ),
                };

                continue;
            }

            if ($section === null) {
                throw new InvalidZoneTemplateException('The zone template has content before its first section.');
            }

            if ($section === 'zone') {
                if (! str_contains($line, '=')) {
                    throw new InvalidZoneTemplateException('The zone template has a setting without a value.');
                }

                [$key, $value] = explode('=', $line, 2);
                $zone[trim($key)] = trim($value);

                continue;
            }

            $records[] = $this->parseRecord($line);
        }

        foreach (self::ZONE_REQUIRED as $key) {
            if (($zone[$key] ?? '') === '') {
                throw new InvalidZoneTemplateException("The zone template does not set '{$key}'.");
            }
        }

        return ['zone' => $zone, 'records' => $records];
    }

    /**
     * Expand a template with the wizard values (placeholders replaced) and
     * return the zone attributes and the records to create.
     *
     * @param  array<string, mixed>  $values
     * @return array{zone: array<string, mixed>, records: array<int, array<string, mixed>>}
     */
    public function expand(DnsTemplate $template, array $values): array
    {
        $parsed = $this->parse($this->replace((string) $template->template, $values));

        $zone = $this->zoneAttributes($parsed['zone'], $values);
        $ttl = (int) $zone['ttl'];

        $records = [];

        foreach ($parsed['records'] as $record) {
            $record['ttl'] = $record['ttl'] ?? $ttl;

            if ($record['name'] === '' || $record['data'] === '') {
                throw new InvalidZoneTemplateException('The zone template produced a record without a name or value.');
            }

            $records[] = $record;
        }

        if (! empty($values['dkim'])) {
            $dkim = $this->dkimRecord((string) $values['domain'], $ttl);

            if ($dkim !== null) {
                $records[] = $dkim;
            }
        }

        return ['zone' => $zone, 'records' => $records];
    }

    /**
     * Create the zone and its records: zone inactive, records, activation —
     * one transaction, one change set (AttachChangeSetId).
     *
     * @param  array<string, mixed>  $values
     */
    public function create(DnsTemplate $template, array $values, int $serverId, ?int $ownerGroupId = null): DnsSoa
    {
        $expanded = $this->expand($template, $values);
        $origin = (string) $expanded['zone']['origin'];

        // The dns_soa origin UNIQUE key, reported as a 409 like POST /dns/soa.
        if (DnsSoa::query()->where('origin', $origin)->exists()) {
            throw new ConflictHttpException("A DNS zone with origin '{$origin}' already exists.");
        }

        return DB::transaction(function () use ($expanded, $serverId, $ownerGroupId): DnsSoa {
            $zone = new DnsSoa($expanded['zone']);
            $zone->server_id = $serverId;

            if ($ownerGroupId !== null) {
                $zone->setAttribute('sys_groupid', $ownerGroupId);
            }

            // Legacy inserts the zone inactive and activates it once every
            // record exists, so the name server never sees a half-built zone.
            $zone->active = false;
            $zone->serial = $this->serial->increaseSerial(null);
            $zone->save();

            $raw = $zone->getRawOriginal();

            foreach ($expanded['records'] as $attributes) {
                $record = new DnsRecord([
                    'zone' => (int) $zone->getKey(),
                    'name' => $attributes['name'],
                    'type' => $attributes['type'],
                    'data' => $attributes['data'],
                    'aux' => $attributes['aux'],
                    'ttl' => $attributes['ttl'],
                    'active' => true,
                ]);

                // Legacy: records always carry the zone's server and owner.
                $record->forceFill([
                    'server_id' => (int) $raw['server_id'],
                    'sys_groupid' => (int) $raw['sys_groupid'],
                    'stamp' => $this->serial->timestamp(),
                    'serial' => $this->serial->increaseSerial(null),
                ]);

                $record->save();
            }

            $zone->active = true;
            $zone->save();

            return $zone->refresh();
        });
    }

    /**
     * Replace the placeholders legacy replaces. Empty values are left alone
     * here; the request has already required every placeholder the template
     * declares, so no literal `{IP}` can survive (spec 029 deviation 2).
     *
     * @param  array<string, mixed>  $values
     */
    protected function replace(string $template, array $values): string
    {
        foreach (self::PLACEHOLDERS as $token => $field) {
            $value = $values[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $template = str_replace($token, $value, $template);
            }
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseRecord(string $line): array
    {
        $parts = explode('|', $line);

        if (count($parts) < 3) {
            throw new InvalidZoneTemplateException('The zone template has a record row the wizard cannot read.');
        }

        $type = strtoupper(trim($parts[0]));

        if (! in_array($type, self::RECORD_TYPES, true)) {
            throw new InvalidZoneTemplateException("The zone template uses the unsupported record type '{$type}'.");
        }

        return [
            'type' => $type,
            'name' => trim($parts[1]),
            'data' => trim($parts[2]),
            'aux' => isset($parts[3]) && trim($parts[3]) !== '' ? (int) trim($parts[3]) : 0,
            // Legacy stores TTL 0 for a short row (its own appended DKIM row
            // is one); the zone's TTL is used instead (spec 029 deviation 5).
            'ttl' => isset($parts[4]) && trim($parts[4]) !== '' ? (int) trim($parts[4]) : null,
        ];
    }

    /**
     * `[ZONE]` values mapped onto dns_soa columns.
     *
     * @param  array<string, string>  $zone
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function zoneAttributes(array $zone, array $values): array
    {
        $attributes = [];

        foreach (self::ZONE_COLUMNS as $column) {
            if (! array_key_exists($column, $zone)) {
                continue;
            }

            $attributes[$column] = match ($column) {
                'refresh', 'retry', 'expire', 'minimum', 'ttl' => (int) $zone[$column],
                'dnssec_wanted' => strtoupper($zone[$column]) === 'Y',
                default => $zone[$column],
            };
        }

        // Legacy onSubmit: '@' is not allowed in the SOA mailbox.
        $attributes['mbox'] = str_replace('@', '.', (string) $attributes['mbox']);

        if (isset($attributes['dnssec_algo']) && ! in_array($attributes['dnssec_algo'], ['NSEC3RSASHA1', 'ECDSAP256SHA256'], true)) {
            unset($attributes['dnssec_algo']);
        }

        // The DNSSEC flag is applied after parsing so it wins: legacy injects
        // `dnssec_wanted=Y` after `[ZONE]`, where the template's own later
        // `dnssec_wanted=N` overwrites it again — its checkbox has no effect
        // with the shipped template (spec 029 deviation 7).
        if (array_key_exists('dnssec', $values) && $values['dnssec'] !== null) {
            $attributes['dnssec_wanted'] = (bool) $values['dnssec'];
        }

        return $attributes;
    }

    /**
     * The published DKIM record of the domain's mail domain, when the acting
     * key can read one with DKIM enabled (legacy reads it under
     * getAuthSQL('r'); spec 024 scoping). No match → no record, no error.
     *
     * @return array<string, mixed>|null
     */
    protected function dkimRecord(string $domain, int $ttl): ?array
    {
        $query = DB::table('mail_domain')->where('domain', $domain)->where('dkim', 'y');
        app(IspContext::class)->authScope()->applyReadPredicate($query, 'r');

        $row = $query->first(['dkim_public', 'dkim_selector']);

        if ($row === null || ($row->dkim_public ?? '') === '') {
            return null;
        }

        $selector = ($row->dkim_selector ?? '') !== '' ? (string) $row->dkim_selector : 'default';

        $key = str_replace(
            ["\r\n", "\n", "\r", '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'],
            '',
            (string) $row->dkim_public
        );

        return [
            'type' => 'TXT',
            'name' => $selector.'._domainkey.'.$domain.'.',
            'data' => 'v=DKIM1; t=s; p='.$key,
            'aux' => 0,
            'ttl' => $ttl,
        ];
    }
}
