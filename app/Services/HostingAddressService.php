<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hosting addresses and name servers of an account (spec 031, contract
 * api/modules/me/hosting-addresses.yaml).
 *
 * - Servers per role: the valid assigned servers in list order (spec 016, the
 *   first is the default), then the non-mirror servers of the role hosting the
 *   client's web_domain / mail_domain (spec 025) / dns_soa rows, by id.
 * - Addresses: server_ip rows with client_id 0 or the client (legacy
 *   web_vhost_domain_edit.php:209/225 without its virtualhost filter), valid
 *   for their ip_type and public (research R2, R3).
 * - Name servers: the zone-import list (dns_import.php:272-287) — the DNS
 *   server and its mirror servers ordered by server_name, then the
 *   dns_external_slave_fqdn names (research R4).
 */
class HostingAddressService
{
    public function __construct(
        private readonly ServerAssignmentService $assignments,
        private readonly AccountMailService $mail,
        private readonly SystemConfigService $config,
    ) {}

    /**
     * @return array{client_id: int, web: array<int, array<string, mixed>>, mail: array<int, array<string, mixed>>, dns: array<int, array<string, mixed>>}
     */
    public function addresses(int $clientId): array
    {
        // Lookup scope for the assignment columns of the client row.
        $scope = new AuthScope(0, 0, [], false, $clientId);

        $lists = [
            'web' => $this->merge(
                $this->assignments->assignedServerIds($scope, 'web'),
                $this->hostingServerIds($clientId, 'web_domain', 'web_server')
            ),
            'mail' => $this->mail->accountMailServers($clientId),
            'dns' => $this->merge(
                $this->assignments->assignedServerIds($scope, 'dns'),
                $this->hostingServerIds($clientId, 'dns_soa', 'dns_server')
            ),
        ];

        $ids = array_values(array_unique(array_merge(...array_values($lists))));

        $servers = $ids === [] ? [] : DB::table('server')
            ->whereIn('server_id', $ids)
            ->get(['server_id', 'server_name'])
            ->keyBy(fn (object $server): int => (int) $server->server_id)
            ->all();

        $mirrors = $lists['dns'] === [] ? collect() : DB::table('server')
            ->whereIn('mirror_server_id', $lists['dns'])
            ->get(['server_id', 'server_name', 'mirror_server_id']);

        $addresses = $this->publicAddresses(
            array_merge($ids, $mirrors->pluck('server_id')->map(fn ($id): int => (int) $id)->all()),
            $clientId
        );

        $view = ['client_id' => $clientId];

        foreach ($lists as $role => $serverIds) {
            $default = $this->assignments->defaultServerId($scope, $role);
            $view[$role] = [];

            foreach ($serverIds as $id) {
                $entry = [
                    'server_id' => $id,
                    'server_name' => (string) $servers[$id]->server_name,
                    'is_default' => $id === $default,
                ];

                $view[$role][] = $role === 'dns'
                    ? $entry + ['nameservers' => $this->nameServers($servers[$id], $mirrors, $addresses)]
                    : $entry + $this->addressLists($addresses, $id);
            }
        }

        return $view;
    }

    /**
     * The zone-import name servers of one DNS server: the server and its
     * mirrors ordered by name (MySQL's case-insensitive ORDER BY server_name),
     * then the external DNS servers; trailing dots removed, duplicates dropped.
     *
     * @param  Collection<int, object>  $mirrors
     * @param  array<int, array{ipv4?: array<int, string>, ipv6?: array<int, string>}>  $addresses
     * @return array<int, array{name: string, ipv4: array<int, string>, ipv6: array<int, string>}>
     */
    protected function nameServers(object $server, Collection $mirrors, array $addresses): array
    {
        $members = collect([$server])
            ->merge($mirrors->filter(fn (object $mirror): bool => (int) $mirror->mirror_server_id === (int) $server->server_id))
            ->sortBy(fn (object $member): string => strtolower((string) $member->server_name), SORT_STRING)
            ->values();

        $entries = [];

        foreach ($members as $member) {
            $entries[] = ['name' => rtrim((string) $member->server_name, '.')] + $this->addressLists($addresses, (int) $member->server_id);
        }

        foreach ($this->externalNameServers() as $name) {
            $entries[] = ['name' => $name, 'ipv4' => [], 'ipv6' => []];
        }

        $seen = [];

        return array_values(array_filter($entries, function (array $entry) use (&$seen): bool {
            $key = strtolower($entry['name']);

            if ($key === '' || isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }

    /**
     * System setting "External DNS servers" split as dns_import.php:283-286.
     *
     * @return array<int, string>
     */
    protected function externalNameServers(): array
    {
        $setting = trim((string) ($this->config->rawSection('dns')['dns_external_slave_fqdn'] ?? ''));

        if ($setting === '') {
            return [];
        }

        return array_map(
            fn (string $name): string => rtrim($name, '.'),
            preg_split('/[\s,]+/', $setting, -1, PREG_SPLIT_NO_EMPTY) ?: []
        );
    }

    /**
     * Public addresses by server: rows shared (client_id 0) or dedicated to the
     * client, valid for their ip_type, outside private and reserved ranges,
     * deduplicated by binary address in server_ip_id order.
     *
     * @param  array<int, int>  $serverIds
     * @return array<int, array{ipv4?: array<int, string>, ipv6?: array<int, string>}>
     */
    protected function publicAddresses(array $serverIds, int $clientId): array
    {
        if ($serverIds === [] || ! Schema::hasTable('server_ip')) {
            return [];
        }

        $rows = DB::table('server_ip')
            ->whereIn('server_id', array_values(array_unique($serverIds)))
            ->whereIn('client_id', [0, $clientId])
            ->orderBy('server_ip_id')
            ->get(['server_id', 'ip_type', 'ip_address']);

        $result = [];
        $seen = [];

        foreach ($rows as $row) {
            [$family, $flag] = match ((string) $row->ip_type) {
                'IPv4' => ['ipv4', FILTER_FLAG_IPV4],
                'IPv6' => ['ipv6', FILTER_FLAG_IPV6],
                default => [null, 0],
            };

            $address = trim((string) $row->ip_address);

            if ($family === null
                || filter_var($address, FILTER_VALIDATE_IP, $flag | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                continue;
            }

            $key = $row->server_id.'|'.inet_pton($address);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[(int) $row->server_id][$family][] = $address;
        }

        return $result;
    }

    /**
     * @param  array<int, array{ipv4?: array<int, string>, ipv6?: array<int, string>}>  $addresses
     * @return array{ipv4: array<int, string>, ipv6: array<int, string>}
     */
    protected function addressLists(array $addresses, int $serverId): array
    {
        return [
            'ipv4' => $addresses[$serverId]['ipv4'] ?? [],
            'ipv6' => $addresses[$serverId]['ipv6'] ?? [],
        ];
    }

    /**
     * Non-mirror servers with the role flag hosting the client's rows of a
     * resource table, by id.
     *
     * @return array<int, int>
     */
    protected function hostingServerIds(int $clientId, string $table, string $roleFlag): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->join('sys_group', 'sys_group.groupid', '=', $table.'.sys_groupid')
            ->join('server', 'server.server_id', '=', $table.'.server_id')
            ->where('sys_group.client_id', $clientId)
            ->where('server.'.$roleFlag, 1)
            ->where('server.mirror_server_id', 0)
            ->distinct()
            ->orderBy($table.'.server_id')
            ->pluck($table.'.server_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $assigned
     * @param  array<int, int>  $hosting
     * @return array<int, int>
     */
    protected function merge(array $assigned, array $hosting): array
    {
        return array_values(array_unique(array_merge($assigned, $hosting)));
    }
}
