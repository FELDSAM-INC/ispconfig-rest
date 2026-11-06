<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait DnsJournalReplay
{
    /**
     * Replays the journal like an ISPConfig DNS server (server/lib/classes/modules.inc.php processDatalog() with
     * plugins-available/bind_plugin.inc.php): one entry at a time into a local copy, the zone file written and
     * checked after each one. Returns the datalog ids at which named-checkzone would reject $origin for having no
     * NS record at its apex.
     *
     * bind_plugin writes the zone on every dns_soa insert/update and dns_rr update, and on a dns_rr insert/delete
     * only while the local zone is active; a zone without active records is skipped ("has no records yet").
     */
    protected function rejectedZoneWrites(string $origin): array
    {
        $local = ['dns_soa' => [], 'dns_rr' => []];
        $rejected = [];
        foreach (DB::table('sys_datalog')->whereIn('dbtable', ['dns_soa', 'dns_rr'])->orderBy('datalog_id')->get() as $entry) {
            $data = unserialize($entry->data);
            $row = $entry->action === 'd' ? $data['old'] : $data['new'];
            $id = (int) $row['id'];
            if ($entry->action === 'd') {
                unset($local[$entry->dbtable][$id]);
            } else {
                $local[$entry->dbtable][$id] = $row + ($local[$entry->dbtable][$id] ?? []);
            }
            $zone = $local['dns_soa'][$entry->dbtable === 'dns_soa' ? $id : (int) $row['zone']] ?? null;
            if ($zone === null || $zone['origin'] !== $origin) {
                continue;
            }
            $writes = $entry->dbtable === 'dns_soa' ? $entry->action !== 'd' : ($entry->action === 'u' || $zone['active'] === 'Y');
            $records = array_filter($local['dns_rr'], fn ($rr) => (int) $rr['zone'] === (int) $zone['id'] && $rr['active'] === 'Y');
            if (! $writes || $records === []) {
                continue;
            }
            $apexNs = array_filter($records, fn ($rr) => $rr['type'] === 'NS' && in_array($rr['name'], [$origin, '@', ''], true));
            if ($apexNs === []) {
                $rejected[] = (int) $entry->datalog_id;
            }
        }

        return $rejected;
    }
}
