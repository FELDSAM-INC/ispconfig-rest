<?php

namespace App\Services;

use App\Models\DnsSoa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DNSSEC state of one DNS zone (spec 032). Read-only.
 *
 * Legacy (ISPConfig 3.3.1p1): `bind_plugin.inc.php::soa_dnssec_sign()` 178-194
 * writes the `dsset-` file and every public key file into `dns_soa.dnssec_info`
 * as a `DS-Records:` section, a dashed separator and a `DNSKEY-Records:`
 * section, then sets `dnssec_initialized` and `dnssec_last_signed` with a
 * direct UPDATE. `powerdns_plugin.inc.php` 527-560 writes the same column but
 * appends a raw log of the `pdnsutil` commands it ran — which is why only the
 * parsed public records are exposed and the raw column is administrator-only
 * (research R4, R7).
 *
 * `dns_soa_edit.php` 92-102 hides the whole DNSSEC block when the zone's DNS
 * server has mirrors: ISPConfig signs on the master only, so a mirrored zone
 * would be served unsigned (research R3).
 */
class DnssecStatusService
{
    /** Legacy DNSKEY flag values. */
    public const FLAG_KSK = 257;

    public const FLAG_ZSK = 256;

    /**
     * @return array<string, mixed>
     */
    public function status(DnsSoa $zone): array
    {
        $raw = $zone->getRawOriginal();

        $origin = (string) ($raw['origin'] ?? '');
        $available = $this->available((int) ($raw['server_id'] ?? 0));
        $wanted = $this->yes($raw['dnssec_wanted'] ?? null);
        $initialized = $this->yes($raw['dnssec_initialized'] ?? null);

        $state = match (true) {
            ! $available => 'unavailable',
            ! $wanted => 'off',
            $initialized => 'signed',
            default => 'pending',
        };

        // Only a signed zone publishes records; anything else reports empty
        // lists rather than stale notes.
        $records = $state === 'signed'
            ? $this->parse((string) ($raw['dnssec_info'] ?? ''))
            : ['ds' => [], 'dnskey' => []];

        $lastSigned = (int) ($raw['dnssec_last_signed'] ?? 0);

        return [
            'zone_id' => (int) $zone->getKey(),
            'origin' => $origin,
            'state' => $state,
            'available' => $available,
            'wanted' => $wanted,
            'initialized' => $initialized,
            'algorithm' => (string) ($raw['dnssec_algo'] ?? ''),
            'last_signed' => $lastSigned > 0 ? $this->timestamp($lastSigned) : null,
            'ds_records' => $records['ds'],
            'dnskey_records' => $records['dnskey'],
        ];
    }

    /**
     * Legacy dns_soa_edit.php:95-98 — DNSSEC is offered only while the zone's
     * DNS server has no mirrors.
     */
    public function available(int $serverId): bool
    {
        if ($serverId < 1) {
            return false;
        }

        return ! DB::table('server')->where('mirror_server_id', $serverId)->exists();
    }

    /**
     * Parse the server's notes into public DS and DNSKEY records. Comment
     * lines, separators and anything else (a PowerDNS command log) are
     * ignored; unparsable notes simply yield empty lists.
     *
     * @return array{ds: array<int, array<string, mixed>>, dnskey: array<int, array<string, mixed>>}
     */
    public function parse(string $notes): array
    {
        $ds = [];
        $dnskey = [];

        foreach (preg_split('/\r\n|\r|\n/', $notes) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^(\S+)\s+IN\s+DS\s+(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/i', $line, $m) === 1) {
                // The signer wraps long digests; the value is one hex string.
                $digest = strtoupper($this->squeeze($m[5]));

                $ds[] = [
                    'key_tag' => (int) $m[2],
                    'algorithm' => (int) $m[3],
                    'digest_type' => (int) $m[4],
                    'digest' => $digest,
                    'record' => sprintf('%s IN DS %d %d %d %s', $m[1], (int) $m[2], (int) $m[3], (int) $m[4], $digest),
                ];

                continue;
            }

            if (preg_match('/^(\S+)\s+IN\s+DNSKEY\s+(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/i', $line, $m) === 1) {
                $flags = (int) $m[2];
                $key = $this->squeeze($m[5]);

                $dnskey[] = [
                    'flags' => $flags,
                    'protocol' => (int) $m[3],
                    'algorithm' => (int) $m[4],
                    'public_key' => $key,
                    'type' => match ($flags) {
                        self::FLAG_KSK => 'ksk',
                        self::FLAG_ZSK => 'zsk',
                        default => 'other',
                    },
                    'record' => sprintf('%s IN DNSKEY %d %d %d %s', $m[1], $flags, (int) $m[3], (int) $m[4], $key),
                ];
            }
        }

        return ['ds' => $ds, 'dnskey' => $dnskey];
    }

    protected function squeeze(string $value): string
    {
        return (string) preg_replace('/\s+/', '', trim($value));
    }

    protected function timestamp(int $tstamp): string
    {
        return CarbonImmutable::createFromTimestamp($tstamp, config('app.timezone'))->toIso8601String();
    }

    protected function yes(mixed $value): bool
    {
        return is_string($value) && strtoupper($value) === 'Y';
    }
}
