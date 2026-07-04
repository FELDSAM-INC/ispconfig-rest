<?php

namespace App\Services;

use App\Models\MailDomain;
use Illuminate\Support\Facades\DB;

/**
 * Business logic and legacy side effects for mail domains, mirroring
 * source_code/interface/web/mail/mail_domain_edit.php and
 * mail_domain_del.php. Every table write goes through DatalogService
 * (constitution Principle II) so ISPConfig server daemons pick the
 * changes up.
 */
class MailDomainService
{
    public function __construct(protected DatalogService $datalog)
    {
    }

    /**
     * Derive dkim_public from dkim_private, mirroring legacy onSubmit():
     * "Extract the dkim public key if not submitted". The API never accepts
     * dkim_public as input, so the public key is (re)derived whenever the
     * private key is new or changed, or the stored public key is empty.
     */
    public function applyDkimKeys(MailDomain $domain): void
    {
        $private = $domain->getAttributes()['dkim_private'] ?? null;

        if (blank($private)) {
            return;
        }

        $privateChanged = ! $domain->exists || $domain->isDirty('dkim_private');
        $publicEmpty = blank($domain->getAttributes()['dkim_public'] ?? null);

        if ($privateChanged || $publicEmpty) {
            $public = MailDomain::derivePublicKey($private);

            if ($public !== null) {
                $domain->dkim_public = $public;
            }
        }
    }

    /**
     * DNS side effect after insert (legacy onAfterInsert()): when the new
     * domain is active with DKIM enabled and a hosted DNS zone encloses it,
     * publish the DKIM TXT record and bump the zone serial.
     *
     * Legacy onAfterInsert() additionally upserts a spamfilter_users row for
     * '@domain' from the form's `policy` field — the API contract has no
     * policy field, so that side effect is intentionally not implemented
     * (spec 003 gap G04).
     */
    public function syncDnsAfterInsert(MailDomain $domain): void
    {
        $record = $domain->getAttributes();

        if (($record['active'] ?? 'n') !== 'y' || ($record['dkim'] ?? 'n') !== 'y') {
            return;
        }

        $soa = $this->findSoaZone($record['domain']);

        if ($soa !== null) {
            $this->updateDkimDns($record, $soa);
        }
    }

    /**
     * DNS side effects after update (legacy onAfterUpdate(), DNS portion —
     * the rename cascades never apply because the API rejects renames):
     * for an active domain, refresh the DKIM TXT record (purging the old
     * one) when DKIM is enabled, or downgrade an existing DMARC policy to
     * 'none' when DKIM is disabled.
     *
     * @param  array<string, mixed>  $oldRecord  raw attributes before the update
     */
    public function syncDnsAfterUpdate(MailDomain $domain, array $oldRecord): void
    {
        $record = $domain->getAttributes();

        // Legacy guards: only for active domains and unchanged domain names.
        if (($record['active'] ?? 'n') !== 'y' || $record['domain'] !== ($oldRecord['domain'] ?? null)) {
            return;
        }

        $soa = $this->findSoaZone($record['domain']);

        if (($record['dkim'] ?? 'n') === 'y') {
            // Legacy condition ($selector || $dkim_private || $dkim_active)
            // && $dkim_active reduces to: DKIM enabled -> refresh the record.
            if ($soa !== null) {
                $this->updateDkimDns($record, $soa, $oldRecord);
            }

            return;
        }

        // DKIM disabled: downgrade an existing DMARC record to p=none.
        $dmarc = DB::table('dns_rr')
            ->where('name', '_dmarc.'.$record['domain'].'.')
            ->where('data', 'like', 'v=DMARC1%')
            ->first();

        if ($dmarc !== null && strpos($dmarc->data, 'p=none=') === false) {
            $data = (array) $dmarc;
            $data['data'] = str_replace(['quarantine', 'reject'], 'none', $data['data']);
            // Legacy passes the full record back into datalogUpdate.
            $this->datalog->updateRecord('dns_rr', 'id', $dmarc->id, $data);

            // Legacy bumps the enclosing zone's serial here; with no zone
            // found its datalogUpdate on id 0 is a no-op, so guard instead.
            if ($soa !== null) {
                $this->bumpZoneSerial((int) $soa['zone']);
            }
        }
    }

    /**
     * Delete the domain with the legacy cascade
     * (mail_domain_del.php::onBeforeDelete()), everything datalogged:
     *
     *  1. mail_forwarding rows whose source belongs to the domain, or whose
     *     destination does for every type except 'forward' — this covers
     *     aliases, catchalls and alias domains referencing the domain;
     *  2. mail_get rows fetching into the domain;
     *  3. mail_user mailboxes of the domain;
     *  4. spamfilter_users of the domain, each preceded by its
     *     spamfilter_wblist entries;
     *  5. finally the mail_domain row itself.
     *
     * Legacy also loops over mail_mailinglist rows, but reads the id from a
     * column its SELECT never returns ($rec['id'] vs mailinglist_id), so
     * that branch is a no-op upstream; the contract does not promise
     * mailing-list deletion, so it is intentionally not replicated.
     */
    public function deleteWithCascade(MailDomain $domain): void
    {
        $domainName = $domain->getAttributes()['domain'];
        $like = '%@'.$domainName; // legacy uses the raw LIKE pattern, unescaped

        $forwardings = DB::table('mail_forwarding')
            ->where('source', 'like', $like)
            ->orWhere(function ($query) use ($like) {
                $query->where('destination', 'like', $like)
                    ->where('type', '!=', 'forward');
            })
            ->pluck('forwarding_id');

        foreach ($forwardings as $id) {
            $this->datalog->deleteRecord('mail_forwarding', 'forwarding_id', $id);
        }

        $fetchers = DB::table('mail_get')->where('destination', 'like', $like)->pluck('mailget_id');

        foreach ($fetchers as $id) {
            $this->datalog->deleteRecord('mail_get', 'mailget_id', $id);
        }

        $mailboxes = DB::table('mail_user')->where('email', 'like', $like)->pluck('mailuser_id');

        foreach ($mailboxes as $id) {
            $this->datalog->deleteRecord('mail_user', 'mailuser_id', $id);
        }

        $spamfilterUsers = DB::table('spamfilter_users')->where('email', 'like', $like)->pluck('id');

        foreach ($spamfilterUsers as $id) {
            $wblists = DB::table('spamfilter_wblist')->where('rid', $id)->pluck('wblist_id');

            foreach ($wblists as $wblistId) {
                $this->datalog->deleteRecord('spamfilter_wblist', 'wblist_id', $wblistId);
            }

            $this->datalog->deleteRecord('spamfilter_users', 'id', $id);
        }

        $domain->delete();
    }

    /**
     * Walk up the domain's labels looking for a hosted, active DNS zone
     * (legacy find_soa_domain()).
     *
     * @return array<string, mixed>|null keys: zone, sys_userid, sys_groupid,
     *                                   sys_perm_*, server_id, ttl, serial
     */
    protected function findSoaZone(string $domain): ?array
    {
        $soaDomain = $domain.'.';

        while (substr_count($soaDomain, '.') > 1) {
            $soa = DB::table('dns_soa')
                ->where('active', 'Y')
                ->where('origin', $soaDomain)
                ->selectRaw('id as zone, sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, server_id, ttl, serial')
                ->first();

            if ($soa !== null) {
                return (array) $soa;
            }

            $soaDomain = preg_replace('/^[^\.]+\./', '', $soaDomain);
        }

        return null;
    }

    /**
     * Publish the domain's DKIM TXT record (legacy update_dns()): purge old
     * v=DKIM1 records for the previous selector/domain, insert the new TXT
     * record inheriting the zone's sys fields/server/ttl, then bump the SOA
     * serial. All writes datalogged.
     *
     * @param  array<string, mixed>  $record  raw mail_domain attributes
     * @param  array<string, mixed>  $soa  zone row from findSoaZone()
     * @param  array<string, mixed>|null  $oldRecord  previous attributes (update only)
     */
    protected function updateDkimDns(array $record, array $soa, ?array $oldRecord = null): void
    {
        if ($oldRecord !== null) {
            $staleIds = DB::table('dns_rr')
                ->where('name', 'like', $oldRecord['dkim_selector'].'._domainkey.'.$oldRecord['domain'].'.')
                ->where('data', 'like', 'v=DKIM1%')
                ->orderByDesc('serial')
                ->pluck('id');

            foreach ($staleIds as $id) {
                $this->datalog->deleteRecord('dns_rr', 'id', $id);
            }
        }

        $publicKey = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"],
            '',
            (string) ($record['dkim_public'] ?? '')
        );

        $rr = $soa; // zone id + inherited sys fields, server_id, ttl, serial
        $rr['name'] = $record['dkim_selector'].'._domainkey.'.$record['domain'].'.';
        $rr['type'] = 'TXT';
        $rr['data'] = 'v=DKIM1; t=s; p='.$publicKey;
        $rr['aux'] = 0;
        $rr['active'] = 'Y';
        $rr['stamp'] = date('Y-m-d H:i:s');
        $rr['serial'] = $this->increaseSerial($soa['serial']);

        $this->datalog->insertRecord('dns_rr', 'id', $rr);

        $this->bumpZoneSerial((int) $soa['zone']);
    }

    /**
     * Increment a zone's SOA serial via datalog (legacy bumps dns_soa after
     * touching dns_rr so slaves pick up the change).
     */
    protected function bumpZoneSerial(int $zoneId): void
    {
        $zone = DB::table('dns_soa')
            ->where('active', 'Y')
            ->where('id', $zoneId)
            ->first(['id', 'serial']);

        if ($zone !== null) {
            $this->datalog->updateRecord('dns_soa', 'id', $zone->id, [
                'serial' => $this->increaseSerial($zone->serial),
            ]);
        }
    }

    /**
     * Exact port of legacy validate_dns::increase_serial() (YYYYMMDDnn with
     * two-digit counter rollover). Kept local so the mail module does not
     * depend on the DNS module's service.
     */
    protected function increaseSerial(int|string $serial): string
    {
        $serial = (string) $serial;
        $serialDate = (int) substr($serial, 0, 8);
        $count = (int) substr($serial, 8, 2);
        $currentDate = date('Ymd');

        if ($serialDate >= (int) $currentDate) {
            $count += 1;

            if ($count > 99) {
                $serialDate += 1;
                $count = 0;
            }

            return $serialDate.str_pad((string) $count, 2, '0', STR_PAD_LEFT);
        }

        return $currentDate.'01';
    }
}
