<?php

namespace App\Services;

use App\Models\DnsRecord;
use App\Models\DnsSoa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * ACME DNS-01 challenge records (spec 057): TXT records `_acme-challenge.<domain>` that certbot, acme.sh and other ACME
 * clients publish through the API. A record goes into the longest zone the acting key may use that hosts the name,
 * through the normal record path (client limits, journal, zone serial), and reports whether the zone's DNS server has
 * applied it, so a client waits exactly as long as needed.
 */
class AcmeChallengeService
{
    public const PREFIX = '_acme-challenge.';

    public const TTL = 60;

    /** Challenge records a zone may hold at once (an ACME order covers at most 100 names). */
    public const ZONE_LIMIT = 100;

    public function __construct(
        private readonly DnsRecordMetaService $meta,
        private readonly DnsSerialService $serial,
    ) {}

    /**
     * The name lower-cased without the final dot, or null when it is not a DNS name starting with `_acme-challenge.`.
     */
    public static function normalizeName(string $name): ?string
    {
        $name = strtolower(rtrim(trim($name), '.'));

        if (! str_starts_with($name, self::PREFIX) || strlen($name) > 253) {
            return null;
        }

        foreach (explode('.', $name) as $label) {
            if (preg_match('/\A[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\z/', $label) !== 1) {
                return null;
            }
        }

        return $name;
    }

    /**
     * A zone origin lower-cased without the final dot (for an ACME key's zone list), or null when it is no DNS name.
     */
    public static function normalizeZone(string $zone): ?string
    {
        $zone = strtolower(rtrim(trim($zone), '.'));

        if ($zone === '' || strlen($zone) > 253) {
            return null;
        }

        foreach (explode('.', $zone) as $label) {
            if (preg_match('/\A[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\z/', $label) !== 1) {
                return null;
            }
        }

        return $zone;
    }

    /**
     * The zone that hosts $name: the longest active primary zone whose origin is $name or a suffix of it, among all
     * zones, so a delegated subzone wins over its parent. Null unless the acting key may read that zone and, with
     * $zones (an ACME key's zone list), it is listed: publishing into a parent zone instead would fail validation.
     *
     * @param  array<int, string>  $zones
     */
    public function zoneFor(string $name, array $zones): ?DnsSoa
    {
        $labels = explode('.', $name);
        $candidates = [];
        for ($index = 0; $index < count($labels); $index++) {
            $candidates[] = implode('.', array_slice($labels, $index)).'.';
        }

        $authoritative = DB::table('dns_soa')
            ->where('active', 'Y')
            ->whereIn('origin', $candidates)
            ->get(['id', 'origin'])
            ->sortByDesc(fn (object $zone): int => strlen((string) $zone->origin))
            ->first();

        if ($authoritative === null) {
            return null;
        }

        $zone = DnsSoa::query()->readable()->whereKey($authoritative->id)->first();

        return $zone !== null && $this->allowed($zone, $zones) ? $zone : null;
    }

    /**
     * Publish the challenge; the same name and value again returns the existing record.
     *
     * @return array{0: DnsRecord, 1: bool} the record and whether it was created
     */
    public function publish(DnsSoa $zone, string $name, string $value): array
    {
        $existing = $this->records($zone, $name)->first(fn (DnsRecord $record): bool => $record->getRawOriginal('data') === $value);
        if ($existing !== null) {
            return [$existing, false];
        }

        $pending = DB::table('dns_rr')
            ->where('zone', $zone->getKey())
            ->where('type', 'TXT')
            ->whereRaw('SUBSTR(name, 1, 15) = ?', ['_acme-challenge'])
            ->count();
        if ($pending >= self::ZONE_LIMIT) {
            throw new ConflictHttpException('The zone already holds '.self::ZONE_LIMIT.' ACME challenge records; remove finished ones first.');
        }

        $zoneAttributes = $zone->getRawOriginal();
        $storage = $this->meta->compose(['data' => $value], 'TXT', $zoneAttributes);

        $record = new DnsRecord(['zone' => $zone->getKey(), 'name' => $name.'.', 'ttl' => self::TTL, 'active' => true]);
        $record->forceFill([
            'type' => $storage['type'],
            'aux' => $storage['aux'],
            'data' => $storage['data'],
            // As DnsRecordController::store: server and group come from the zone.
            'server_id' => (int) $zoneAttributes['server_id'],
            'sys_groupid' => (int) $zoneAttributes['sys_groupid'],
            'stamp' => $this->serial->timestamp(),
            'serial' => $this->serial->increaseSerial(null),
        ]);

        DB::transaction(function () use ($record, $zone): void {
            $record->save();
            $this->serial->bumpZoneSerial((int) $zone->getKey());
        });

        return [$record->refresh(), true];
    }

    /**
     * A challenge record the acting key may read, within its zone list; null for any other record.
     *
     * @param  array<int, string>  $zones
     */
    public function find(int $id, array $zones): ?DnsRecord
    {
        $record = DnsRecord::query()->readable()->whereKey($id)->first();
        if ($record === null) {
            return null;
        }

        $zone = DnsSoa::query()->find((int) $record->getRawOriginal('zone'));
        if ($zone === null || ! $this->allowed($zone, $zones) || ! str_starts_with($this->recordName($record, $zone), self::PREFIX)
            || strtoupper((string) $record->getRawOriginal('type')) !== 'TXT') {
            return null;
        }

        return $record;
    }

    /**
     * Remove the zone's challenge records $name with $value.
     */
    public function remove(DnsSoa $zone, string $name, string $value): int
    {
        $removed = 0;
        foreach ($this->records($zone, $name) as $record) {
            if ($record->getRawOriginal('data') === $value) {
                $this->delete($record);
                $removed++;
            }
        }

        return $removed;
    }

    public function delete(DnsRecord $record): void
    {
        $zoneId = (int) $record->getRawOriginal('zone');

        DB::transaction(function () use ($record, $zoneId): void {
            $record->delete();
            $this->serial->bumpZoneSerial($zoneId);
        });
    }

    /**
     * The API representation (DnsAcmeChallenge.yaml).
     *
     * @return array<string, mixed>
     */
    public function present(DnsRecord $record, ?DnsSoa $zone = null): array
    {
        $zone ??= DnsSoa::query()->findOrFail((int) $record->getRawOriginal('zone'));

        return [
            'id' => (int) $record->getKey(),
            'zone_id' => (int) $zone->getKey(),
            'zone' => rtrim((string) $zone->getRawOriginal('origin'), '.'),
            'name' => $this->recordName($record, $zone),
            'value' => (string) $record->getRawOriginal('data'),
            'ttl' => (int) $record->getRawOriginal('ttl'),
            'status' => $this->status($record),
        ];
    }

    /**
     * Whether the DNS server has applied the record: its latest journal entry and the zone serial update written right
     * after it (spec 015 statuses). A record written outside the journal counts as applied.
     */
    public function status(DnsRecord $record): string
    {
        $entry = DB::table('sys_datalog')
            ->where('dbtable', 'dns_rr')
            ->where('dbidx', 'id:'.$record->getKey())
            ->orderByDesc('datalog_id')
            ->first(['datalog_id', 'server_id', 'error']);

        if ($entry === null) {
            return 'applied';
        }

        $serial = DB::table('sys_datalog')
            ->where('dbtable', 'dns_soa')
            ->where('dbidx', 'id:'.(int) $record->getRawOriginal('zone'))
            ->where('datalog_id', '>', $entry->datalog_id)
            ->orderBy('datalog_id')
            ->first(['datalog_id', 'server_id', 'error']);

        return app(ChangeStatusResolver::class)->statusOf($serial ?? $entry);
    }

    /**
     * TXT records of the zone with the challenge name, stored as an FQDN or relative to the origin.
     *
     * @return Collection<int, DnsRecord>
     */
    private function records(DnsSoa $zone, string $name): Collection
    {
        $origin = rtrim((string) $zone->getRawOriginal('origin'), '.');
        $names = [$name.'.'];
        if ($name === $origin) {
            array_push($names, '', '@');
        } elseif (str_ends_with($name, '.'.$origin)) {
            $names[] = substr($name, 0, -strlen('.'.$origin));
        }

        return DnsRecord::query()
            ->readable()
            ->where('zone', $zone->getKey())
            ->where('type', 'TXT')
            ->whereIn('name', $names)
            ->orderBy('id')
            ->get();
    }

    /**
     * The record's full name without the final dot.
     */
    private function recordName(DnsRecord $record, DnsSoa $zone): string
    {
        $name = strtolower((string) $record->getRawOriginal('name'));
        $origin = strtolower(rtrim((string) $zone->getRawOriginal('origin'), '.'));

        if (str_ends_with($name, '.')) {
            return rtrim($name, '.');
        }

        return in_array($name, ['', '@'], true) ? $origin : $name.'.'.$origin;
    }

    /**
     * @param  array<int, string>  $zones
     */
    private function allowed(DnsSoa $zone, array $zones): bool
    {
        return $zones === [] || in_array(strtolower(rtrim((string) $zone->getRawOriginal('origin'), '.')), $zones, true);
    }
}
