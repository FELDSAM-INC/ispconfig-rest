<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /system/resync (contract: api/modules/system/resync.yaml; legacy:
 * source_code/interface/web/tools/resync.php::onSubmit).
 *
 * An action, not CRUD: for every matching record of each selected service a
 * *forced* full-record 'u' entry is written to sys_datalog — an entry is
 * emitted even though no column changed (legacy datalogUpdate(..., true)).
 * BaseModel::save() deliberately suppresses no-change datalogs and would
 * also re-write table rows resync must not touch, so this service calls
 * DatalogService::log() directly with force = true — the documented
 * constitution Principle II exception (writes still reach ISPConfig only
 * through sys_datalog; no table row is modified).
 *
 * Legacy parity notes:
 *  - per-service table sets, per-table active-only vs all-rows filters and
 *    the emission order are verbatim from tools/resync.php (see SERVICES);
 *  - mail_user_filter and spamfilter_policy carry no server_id column and
 *    are never server-filtered; client is re-emitted unfiltered;
 *  - `*_server_id = 0` resolves to all servers of that type with
 *    `active = 1 AND mirror_server_id = 0`; an unknown server ID is a 400;
 *  - the active-only filter matches legacy's `active = 'y'` under MySQL's
 *    case-insensitive collation, i.e. both 'y' and 'Y' (dns tables store
 *    uppercase);
 *  - DNS is special: instead of re-emitting records, every active dns_rr of
 *    each matching active zone gets an increased serial datalogged, then the
 *    zone's dns_soa.serial (DnsSerialService, exact legacy order);
 *  - resync_client re-emits every client row; the legacy interface-plugin
 *    event client:client:on_after_update cannot be raised by this API —
 *    documented deviation (datalog re-emission only).
 */
class ResyncService
{
    /**
     * Every resync flag in legacy execution order. Each table entry is
     * [table, index_field, active_only, server_filtered].
     *
     * @var array<string, array{server_id_field: string|null, server_type: string|null, tables: array<int, array{0: string, 1: string, 2: bool, 3: bool}>}>
     */
    protected const SERVICES = [
        'resync_sites' => ['server_id_field' => 'web_server_id', 'server_type' => 'web', 'tables' => [
            ['web_domain', 'domain_id', true, true],
        ]],
        'resync_ftp' => ['server_id_field' => 'ftp_server_id', 'server_type' => 'web', 'tables' => [
            ['ftp_user', 'ftp_user_id', true, true],
        ]],
        'resync_webdav' => ['server_id_field' => 'webdav_server_id', 'server_type' => 'file', 'tables' => [
            ['webdav_user', 'webdav_user_id', true, true],
        ]],
        'resync_shell' => ['server_id_field' => 'shell_server_id', 'server_type' => 'web', 'tables' => [
            ['shell_user', 'shell_user_id', true, true],
        ]],
        'resync_cron' => ['server_id_field' => 'cron_server_id', 'server_type' => 'web', 'tables' => [
            ['cron', 'id', true, true],
        ]],
        'resync_db' => ['server_id_field' => 'db_server_id', 'server_type' => 'db', 'tables' => [
            ['web_database_user', 'database_user_id', false, true],
            ['web_database', 'database_id', true, true],
        ]],
        'resync_mail' => ['server_id_field' => 'mail_server_id', 'server_type' => 'mail', 'tables' => [
            ['mail_domain', 'domain_id', true, true],
            ['spamfilter_policy', 'id', false, false],
        ]],
        'resync_mailget' => ['server_id_field' => 'mail_server_id', 'server_type' => 'mail', 'tables' => [
            ['mail_get', 'mailget_id', true, true],
        ]],
        'resync_mailbox' => ['server_id_field' => 'mailbox_server_id', 'server_type' => 'mail', 'tables' => [
            ['mail_user', 'mailuser_id', false, true],
            ['mail_forwarding', 'forwarding_id', true, true],
        ]],
        'resync_mailfilter' => ['server_id_field' => 'mailbox_server_id', 'server_type' => 'mail', 'tables' => [
            ['mail_access', 'access_id', true, true],
            ['mail_content_filter', 'content_filter_id', true, true],
            ['mail_user_filter', 'filter_id', false, false],
            ['spamfilter_users', 'id', false, true],
            ['spamfilter_wblist', 'wblist_id', true, true],
        ]],
        'resync_mailinglist' => ['server_id_field' => 'mail_server_id', 'server_type' => 'mail', 'tables' => [
            ['mail_mailinglist', 'mailinglist_id', false, true],
        ]],
        'resync_mailtransport' => ['server_id_field' => 'mail_server_id', 'server_type' => 'mail', 'tables' => [
            ['mail_transport', 'transport_id', false, true],
        ]],
        'resync_mailrelay' => ['server_id_field' => 'mail_server_id', 'server_type' => 'mail', 'tables' => [
            ['mail_relay_recipient', 'relay_recipient_id', false, true],
        ]],
        'resync_vserver' => ['server_id_field' => 'vserver_server_id', 'server_type' => 'vserver', 'tables' => [
            ['openvz_vm', 'vm_id', true, true],
        ]],
        // resync_dns (serial bumps) and resync_client (unfiltered) run here,
        // in this position — handled specially in resync().
        'resync_dns' => ['server_id_field' => 'dns_server_id', 'server_type' => 'dns', 'tables' => []],
        'resync_client' => ['server_id_field' => null, 'server_type' => null, 'tables' => []],
    ];

    /**
     * resync_all=1 propagates all_server_id to these per-service IDs
     * (verbatim legacy list; mailget/mailinglist/mailtransport/mailrelay use
     * mail_server_id, mailfilter uses mailbox_server_id).
     *
     * @var array<int, string>
     */
    protected const PROPAGATED_SERVER_IDS = [
        'web_server_id', 'ftp_server_id', 'webdav_server_id', 'shell_server_id',
        'cron_server_id', 'db_server_id', 'mail_server_id', 'mailbox_server_id',
        'vserver_server_id', 'dns_server_id',
    ];

    public function __construct(
        protected DatalogService $datalog,
        protected DnsSerialService $dnsSerial,
    ) {
    }

    /**
     * Run a resync request and return the ResyncResult payload
     * (contract: api/components/schemas/ResyncResult.yaml).
     *
     * @param  array<string, mixed>  $data  validated ResyncRequest fields
     * @return array{total_datalog_entries: int, resynced_tables: array<int, array{table: string, datalog_entries: int}>}
     */
    public function resync(array $data): array
    {
        $data = $this->expandResyncAll($data);

        $resyncedTables = [];

        foreach (self::SERVICES as $flag => $service) {
            if ((int) ($data[$flag] ?? 0) !== 1) {
                continue;
            }

            if ($flag === 'resync_dns') {
                array_push($resyncedTables, ...$this->resyncDns((int) ($data['dns_server_id'] ?? 0)));

                continue;
            }

            if ($flag === 'resync_client') {
                $resyncedTables[] = [
                    'table' => 'client',
                    'datalog_entries' => $this->forceReEmit('client', 'client_id', DB::table('client')->orderBy('client_id')->get()->all()),
                ];

                continue;
            }

            $serverIds = $this->resolveServerIds((int) ($data[$service['server_id_field']] ?? 0), $service['server_type']);

            foreach ($service['tables'] as [$table, $indexField, $activeOnly, $serverFiltered]) {
                $query = DB::table($table)->orderBy($indexField);

                if ($serverFiltered) {
                    $query->whereIn('server_id', $serverIds);
                }

                if ($activeOnly) {
                    // Legacy `active = 'y'` under MySQL's case-insensitive
                    // collation — matches lowercase and uppercase columns.
                    $query->whereIn('active', ['y', 'Y']);
                }

                $resyncedTables[] = [
                    'table' => $table,
                    'datalog_entries' => $this->forceReEmit($table, $indexField, $query->get()->all()),
                ];
            }
        }

        return [
            'total_datalog_entries' => array_sum(array_column($resyncedTables, 'datalog_entries')),
            'resynced_tables' => $resyncedTables,
        ];
    }

    /**
     * Forced full-record re-emission: one sys_datalog 'u' entry per row with
     * identical old/new state (shared with the directive-snippets
     * update_sites side effect — do not duplicate this mechanism).
     *
     * @param  array<int, object|array>  $rows
     * @return int number of datalog entries written
     */
    public function forceReEmit(string $table, string $primaryKey, array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $record = (array) $row;

            if ($record === [] || ! array_key_exists($primaryKey, $record)) {
                continue;
            }

            if ($this->datalog->log($table, $primaryKey, $record[$primaryKey], 'u', $record, $record, true) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Legacy resync_all expansion: every flag enabled, all_server_id
     * propagated to every per-service server ID.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function expandResyncAll(array $data): array
    {
        if ((int) ($data['resync_all'] ?? 0) !== 1) {
            return $data;
        }

        foreach (array_keys(self::SERVICES) as $flag) {
            $data[$flag] = 1;
        }

        foreach (self::PROPAGATED_SERVER_IDS as $field) {
            $data[$field] = (int) ($data['all_server_id'] ?? 0);
        }

        return $data;
    }

    /**
     * DNS resync — no full-record re-emission: per matching active zone,
     * increase and datalog every active dns_rr serial, then the zone's
     * dns_soa.serial (legacy order; datalogUpdate without force — the
     * serial change itself produces the entry).
     *
     * @return array<int, array{table: string, datalog_entries: int}>
     */
    protected function resyncDns(int $requestedServerId): array
    {
        $serverIds = $this->resolveServerIds($requestedServerId, 'dns');

        $zones = DB::table('dns_soa')
            ->whereIn('server_id', $serverIds)
            ->whereIn('active', ['y', 'Y'])
            ->orderBy('id')
            ->get();

        $rrEntries = 0;
        $soaEntries = 0;

        foreach ($zones as $zone) {
            $records = DB::table('dns_rr')
                ->where('server_id', $zone->server_id)
                ->where('zone', $zone->id)
                ->whereIn('active', ['y', 'Y'])
                ->orderBy('id')
                ->get();

            foreach ($records as $record) {
                $this->datalog->updateRecord('dns_rr', 'id', $record->id, [
                    'serial' => $this->dnsSerial->increaseSerial($record->serial),
                ]);
                $rrEntries++;
            }

            $this->datalog->updateRecord('dns_soa', 'id', $zone->id, [
                'serial' => $this->dnsSerial->increaseSerial($zone->serial),
            ]);
            $soaEntries++;
        }

        return [
            ['table' => 'dns_rr', 'datalog_entries' => $rrEntries],
            ['table' => 'dns_soa', 'datalog_entries' => $soaEntries],
        ];
    }

    /**
     * Resolve a requested server ID: 0 = all active, unmirrored servers of
     * the type (legacy candidate rule); a non-zero ID must exist (400).
     *
     * @return array<int, int>
     */
    protected function resolveServerIds(int $serverId, string $serverType): array
    {
        if ($serverId === 0) {
            return DB::table('server')
                ->where($serverType.'_server', 1)
                ->where('active', 1)
                ->where('mirror_server_id', 0)
                ->pluck('server_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (! DB::table('server')->where('server_id', $serverId)->exists()) {
            throw new BadRequestHttpException("Server {$serverId} does not exist.");
        }

        return [$serverId];
    }
}
