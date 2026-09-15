<?php

namespace App\Services;

use App\Models\WebDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Let's Encrypt issuance outcome of one website (spec 022). Read-only.
 *
 * Legacy (ISPConfig 3.3.1p1): apache2_plugin.inc.php 1305-1330 /
 * nginx_plugin.inc.php 1374-1399 request the certificate while processing a
 * web_domain journal entry that enables ssl + ssl_letsencrypt (or changes the
 * domain / subdomain) and, when issuance fails, switch ssl_letsencrypt (and ssl
 * when it was off before) back off with plain UPDATEs — no journal entry. The
 * outcome is therefore derived from the newest journal entry that requested or
 * switched off Let's Encrypt, its processing status (spec 015) and the current
 * flags (research R1, R2).
 */
class LetsEncryptStatusService
{
    /** Newest journal entries of the website scanned for a relevant change. */
    public const SCAN_LIMIT = 50;

    /** Reason precedence (research R3). */
    public const REASON_ORDER = ['client_unavailable', 'domain_not_reachable', 'issuance_failed', 'certificate_not_found'];

    /** Maximum log rows inspected per request. */
    public const LOG_LIMIT = 100;

    public const REASON_DETAILS = [
        'domain_not_reachable' => 'The domain does not point to this server yet, so the certificate authority could not verify it.',
        'issuance_failed' => "The certificate authority did not issue the certificate. Check the domain's DNS records and try again later.",
        'certificate_not_found' => 'The certificate was requested but could not be installed on the server.',
        'client_unavailable' => 'Free certificates are not available on this server. Contact support.',
        'unknown' => 'The certificate could not be issued. Make sure the domain points to this server and try again.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function status(WebDomain $website): array
    {
        $raw = $website->getAttributes();
        $websiteId = (int) $website->getKey();
        $https = $this->yes($raw['ssl'] ?? null);
        $letsencrypt = $this->yes($raw['ssl_letsencrypt'] ?? null);

        [$kind, $entry] = $this->relevantEntry($websiteId);

        $state = 'none';
        $requestedAt = null;
        $changeSetId = null;
        $changeStatus = null;

        if ($kind === 'request') {
            $changeStatus = app(ChangeStatusResolver::class)->statusOf($entry);
            $requestedAt = $this->timestamp((int) $entry->tstamp);
            $changeSetId = (string) $entry->session_id !== '' ? (string) $entry->session_id : null;

            if (in_array($changeStatus, ['pending', 'stalled'], true)) {
                $state = 'requested';
            } else {
                $state = $letsencrypt ? 'issued' : 'failed';
            }
        } elseif ($kind === null && $https && $letsencrypt) {
            $state = 'issued';
        }

        return [
            'website_id' => $websiteId,
            'domain' => (string) ($raw['domain'] ?? ''),
            'https_enabled' => $https,
            'letsencrypt_enabled' => $letsencrypt,
            'state' => $state,
            'requested_at' => $requestedAt,
            'change_set_id' => $changeSetId,
            'change_status' => $changeStatus,
            'failure' => $state === 'failed' ? $this->failure($raw, $entry) : null,
            'excluded_domains' => $state === 'issued' && $entry !== null ? $this->excludedDomains($raw, $entry) : [],
            'certificate' => $state === 'issued' ? $this->certificate($raw) : null,
        ];
    }

    /**
     * Newest request or off entry of the website (research R2).
     *
     * @return array{0: 'request'|'off'|null, 1: object|null}
     */
    protected function relevantEntry(int $websiteId): array
    {
        $entries = DB::table('sys_datalog')
            ->where('dbtable', 'web_domain')
            ->where('dbidx', 'domain_id:'.$websiteId)
            ->orderByDesc('datalog_id')
            ->limit(self::SCAN_LIMIT)
            ->get(['datalog_id', 'server_id', 'action', 'tstamp', 'data', 'error', 'session_id']);

        foreach ($entries as $entry) {
            $payload = is_string($entry->data) ? @unserialize($entry->data, ['allowed_classes' => false]) : false;

            if (! is_array($payload) || ! isset($payload['new']) || ! is_array($payload['new'])) {
                continue;
            }

            $new = $payload['new'];
            $old = isset($payload['old']) && is_array($payload['old']) ? $payload['old'] : [];

            $switchedOff = ($this->yes($old['ssl'] ?? null) && ! $this->yes($new['ssl'] ?? null))
                || ($this->yes($old['ssl_letsencrypt'] ?? null) && ! $this->yes($new['ssl_letsencrypt'] ?? null));

            if ($switchedOff) {
                return ['off', $entry];
            }

            if (! $this->yes($new['ssl'] ?? null) || ! $this->yes($new['ssl_letsencrypt'] ?? null)) {
                continue;
            }

            $requests = strtolower((string) $entry->action) === 'i'
                || ! $this->yes($old['ssl'] ?? null)
                || ! $this->yes($old['ssl_letsencrypt'] ?? null)
                || (string) ($old['domain'] ?? '') !== (string) ($new['domain'] ?? '')
                || (string) ($old['subdomain'] ?? '') !== (string) ($new['subdomain'] ?? '');

            if ($requests) {
                return ['request', $entry];
            }
        }

        return [null, null];
    }

    /**
     * Why the request failed, from the letsencrypt class warnings the server
     * logged (research R3): rows of the website's server with log level >=
     * warning tied to the request entry or naming the domain afterwards.
     *
     * @param  array<string, mixed>  $raw
     * @return array{reason: string, detail: string, domains: array<int, string>}
     */
    protected function failure(array $raw, ?object $entry): array
    {
        $found = [];

        foreach ($entry !== null ? $this->logRows($raw, $entry) : [] as $message) {
            [$reason, $domain] = $this->classify((string) $message);

            if ($reason === null) {
                continue;
            }

            $found[$reason] ??= [];

            if ($domain !== null) {
                $found[$reason][] = $domain;
            }
        }

        foreach (self::REASON_ORDER as $reason) {
            if (array_key_exists($reason, $found)) {
                return [
                    'reason' => $reason,
                    'detail' => self::REASON_DETAILS[$reason],
                    'domains' => array_values(array_unique($found[$reason])),
                ];
            }
        }

        return ['reason' => 'unknown', 'detail' => self::REASON_DETAILS['unknown'], 'domains' => []];
    }

    /**
     * Domains left out of an issued certificate (letsencrypt.inc.php:390).
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    protected function excludedDomains(array $raw, object $entry): array
    {
        $domains = [];

        foreach ($this->logRows($raw, $entry) as $message) {
            [$reason, $domain] = $this->classify((string) $message);

            if ($reason === 'domain_not_reachable' && $domain !== null) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return Collection<int, string|null>
     */
    protected function logRows(array $raw, object $entry): Collection
    {
        $pattern = '%'.strtr(strtolower((string) ($raw['domain'] ?? '')), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return DB::table('sys_log')
            ->where('server_id', (int) ($raw['server_id'] ?? 0))
            ->where('loglevel', '>=', 1)
            ->where(function ($query) use ($entry, $pattern): void {
                $query->where('datalog_id', (int) $entry->datalog_id)
                    ->orWhere(function ($query) use ($entry, $pattern): void {
                        $query->where('tstamp', '>=', (int) $entry->tstamp)
                            ->whereRaw("LOWER(message) LIKE ? ESCAPE '!'", [$pattern]);
                    });
            })
            ->orderBy('syslog_id')
            ->limit(self::LOG_LIMIT)
            ->pluck('message');
    }

    /**
     * Map one legacy warning to a reason code and the domain it names.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function classify(string $message): array
    {
        $message = trim($message);

        if (stripos($message, "no Let's Encrypt client found") !== false || stripos($message, 'Unable to install acme.sh') !== false) {
            return ['client_unavailable', null];
        }

        if (preg_match("/^Could not verify domain (\\S+), so excluding it from let's encrypt request/i", $message, $match)) {
            return ['domain_not_reachable', $this->hostname($match[1])];
        }

        if (preg_match("/^Let's Encrypt SSL Cert for (\\S+) via \\S+ could not be issued/i", $message, $match)) {
            return ['issuance_failed', $this->hostname($match[1])];
        }

        if (stripos($message, 'could not find the issued certificate') !== false) {
            return ['certificate_not_found', null];
        }

        return [null, null];
    }

    /** Largest certificate file read (bytes). */
    public const CERTIFICATE_MAX_BYTES = 65536;

    /**
     * Validity of the issued certificate from ISPConfig's public certificate
     * file <document_root>/ssl/<domain>-le.crt (letsencrypt.inc.php 294-340,
     * research R4) when the API can read it; the private key is never opened.
     *
     * @param  array<string, mixed>  $raw
     * @return array{valid_from: string, expires_at: string, issuer: string, domains: array<int, string>}|null
     */
    protected function certificate(array $raw): ?array
    {
        $root = (string) ($raw['document_root'] ?? '');
        $domain = $this->hostname((string) ($raw['domain'] ?? ''));

        if ($domain === null || ! str_starts_with($root, '/') || preg_match('#(^|/)\.\.(/|$)#', $root) === 1) {
            return null;
        }

        $path = rtrim($root, '/').'/ssl/'.(str_starts_with($domain, '*.') ? substr($domain, 2) : $domain).'-le.crt';

        if (! @is_file($path) || ! @is_readable($path)) {
            return null;
        }

        $pem = @file_get_contents($path, false, null, 0, self::CERTIFICATE_MAX_BYTES);
        $info = is_string($pem) ? @openssl_x509_parse($pem) : false;

        if (! is_array($info) || ! isset($info['validFrom_time_t'], $info['validTo_time_t'])) {
            return null;
        }

        $issuer = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? '';
        $domains = [];

        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $name) {
            $name = trim($name);

            if (str_starts_with($name, 'DNS:') && ($host = $this->hostname(substr($name, 4))) !== null) {
                $domains[] = $host;
            }
        }

        if ($domains === [] && ($host = $this->hostname((string) (is_array($info['subject']['CN'] ?? null) ? reset($info['subject']['CN']) : ($info['subject']['CN'] ?? '')))) !== null) {
            $domains[] = $host;
        }

        return [
            'valid_from' => $this->timestamp((int) $info['validFrom_time_t']),
            'expires_at' => $this->timestamp((int) $info['validTo_time_t']),
            'issuer' => (string) (is_array($issuer) ? reset($issuer) : $issuer),
            'domains' => array_values(array_unique($domains)),
        ];
    }

    /**
     * A lower-cased hostname (optionally wildcard) or null.
     */
    protected function hostname(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^(\*\.)?([a-z0-9-]{1,63}\.)+[a-z0-9-]{1,63}$/', $value) === 1 ? $value : null;
    }

    protected function yes(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return strtolower(trim((string) $value)) === 'y';
    }

    protected function timestamp(int $tstamp): string
    {
        return CarbonImmutable::createFromTimestamp($tstamp, (string) config('app.timezone'))->toIso8601String();
    }
}
