<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Support\Facades\DB;

/**
 * Servers a client or reseller key may place new resources on (spec 016).
 *
 * Parity: legacy reads the acting identity's lists from its own client row
 * through the user's default group (web_vhost_domain_edit.php:115,
 * mail_domain_edit.php:134, database_edit.php:79, dns_soa_edit.php:155) and
 * the secondary DNS server from client.default_slave_dnsserver
 * (dns_slave_edit.php:182-193). The API resolves the same row through
 * AuthScope::$clientId (the key's sys_user.client_id).
 *
 * Deviations declared in the spec: list entries that reference deleted,
 * mirror or wrong-role servers are skipped; the first remaining entry is the
 * default for every service; server.active is ignored (legacy parity).
 *
 * Read-only: one client row read per instance and one server query per
 * service. Admin scopes never read the client row.
 */
class ServerAssignmentService
{
    /**
     * @var array<string, array{column: string, flag: string, label: string}>
     */
    public const SERVICES = [
        'web' => ['column' => 'web_servers', 'flag' => 'web_server', 'label' => 'web'],
        'mail' => ['column' => 'mail_servers', 'flag' => 'mail_server', 'label' => 'mail'],
        'db' => ['column' => 'db_servers', 'flag' => 'db_server', 'label' => 'database'],
        'dns' => ['column' => 'dns_servers', 'flag' => 'dns_server', 'label' => 'DNS'],
    ];

    /** @var array<int, array<string, mixed>|null> client rows by client_id */
    private array $clientRows = [];

    /** @var array<string, array<int, int>> resolved ids by "clientId:service" */
    private array $assigned = [];

    /**
     * Valid assigned server ids for the service, in the account's list order.
     *
     * @return array<int, int>
     */
    public function assignedServerIds(AuthScope $scope, string $service): array
    {
        $key = $scope->clientId.':'.$service;

        if (array_key_exists($key, $this->assigned)) {
            return $this->assigned[$key];
        }

        $row = $this->clientRow($scope);
        $ids = $row === null ? [] : self::parseList($row[self::SERVICES[$service]['column']] ?? null);

        if ($ids === []) {
            return $this->assigned[$key] = [];
        }

        $valid = DB::table('server')
            ->whereIn('server_id', $ids)
            ->where(self::SERVICES[$service]['flag'], 1)
            ->where('mirror_server_id', 0)
            ->pluck('server_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->assigned[$key] = array_values(array_filter(
            $ids,
            fn (int $id): bool => in_array($id, $valid, true)
        ));
    }

    /**
     * The server used when a create request omits server_id (first valid
     * list entry), or null when none is assigned.
     */
    public function defaultServerId(AuthScope $scope, string $service): ?int
    {
        return $this->assignedServerIds($scope, $service)[0] ?? null;
    }

    /**
     * The account's secondary DNS server when it references an existing
     * non-mirror DNS server, otherwise null.
     */
    public function slaveDnsServerId(AuthScope $scope): ?int
    {
        $row = $this->clientRow($scope);
        $id = (int) ($row['default_slave_dnsserver'] ?? 0);

        if ($id < 1) {
            return null;
        }

        $exists = DB::table('server')
            ->where('server_id', $id)
            ->where('dns_server', 1)
            ->where('mirror_server_id', 0)
            ->exists();

        return $exists ? $id : null;
    }

    /**
     * Human label of a service key for validation messages.
     */
    public function serviceLabel(string $service): string
    {
        return self::SERVICES[$service]['label'];
    }

    /**
     * Parse a legacy CSV server list: trimmed positive integers, first
     * occurrence of duplicates kept, everything else dropped.
     *
     * @return array<int, int>
     */
    public static function parseList(mixed $csv): array
    {
        if (! is_string($csv) && ! is_int($csv)) {
            return [];
        }

        $ids = [];

        foreach (explode(',', (string) $csv) as $entry) {
            $entry = trim($entry);

            if ($entry === '' || ! ctype_digit($entry)) {
                continue;
            }

            $id = (int) $entry;

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The acting identity's client row (memoized), or null for admin scopes
     * and identities without a client row.
     *
     * @return array<string, mixed>|null
     */
    protected function clientRow(AuthScope $scope): ?array
    {
        if ($scope->isAdmin || $scope->clientId < 1) {
            return null;
        }

        if (! array_key_exists($scope->clientId, $this->clientRows)) {
            $row = DB::table('client')
                ->where('client_id', $scope->clientId)
                ->first(['web_servers', 'mail_servers', 'db_servers', 'dns_servers', 'default_slave_dnsserver']);

            $this->clientRows[$scope->clientId] = $row === null ? null : (array) $row;
        }

        return $this->clientRows[$scope->clientId];
    }
}
