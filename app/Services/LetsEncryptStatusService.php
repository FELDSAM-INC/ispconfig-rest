<?php

namespace App\Services;

use App\Models\WebDomain;
use Carbon\CarbonImmutable;
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
            'excluded_domains' => [],
            'certificate' => null,
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
     * @param  array<string, mixed>  $raw
     * @return array{reason: string, detail: string, domains: array<int, string>}
     */
    protected function failure(array $raw, ?object $entry): array
    {
        return ['reason' => 'unknown', 'detail' => self::REASON_DETAILS['unknown'], 'domains' => []];
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
