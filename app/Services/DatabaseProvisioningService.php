<?php

namespace App\Services;

use App\Models\WebDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared ISPConfig provisioning for ordinary creation and copy targets. */
class DatabaseProvisioningService
{
    public function __construct(protected SitesService $service, protected SitesConfigService $config) {}

    public function create(array $payload): WebDatabase
    {
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);
        $serverId = (int) $payload['server_id'];

        $this->assertLinkedUsersMatchSiteGroup($payload, $parent);

        $prefix = $this->config->sitesPrefix('dbname_prefix', $payload);
        $fullName = $prefix.$payload['database_name'];
        $this->assertValidDatabaseName($fullName, $prefix);
        $this->assertUniquePerServer($fullName, $serverId);
        $this->assertPostgresUsersUnused($payload, $serverId);

        $database = new WebDatabase($payload);
        $record = $database->getAttributes();
        $this->service->applyRemoteAccessAutoFix($record, $parent);

        $database->forceFill([
            'database_name' => $fullName,
            'database_name_prefix' => $prefix,
            'remote_access' => $record['remote_access'],
            'remote_ips' => $record['remote_ips'] ?? '',
            // Legacy sites_database_plugin: group AND backup_copies come
            // from the parent web domain.
            'sys_groupid' => (int) $parent->sys_groupid,
            'backup_copies' => (int) $parent->backup_copies,
        ]);

        DB::transaction(function () use ($database, $payload, $serverId): void {
            $database->save();
            $this->service->touchLinkedDatabaseUser((int) $payload['database_user_id'], $serverId);
            $this->service->touchLinkedDatabaseUser((int) ($payload['database_ro_user_id'] ?? 0), $serverId);
        });

        return $database->refresh();
    }

    /**
     * Legacy database_client_differs: the linked users' sys_groupid must
     * match the parent domain's group.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertLinkedUsersMatchSiteGroup(array $payload, object $parent): void
    {
        foreach (['database_user_id', 'database_ro_user_id'] as $field) {
            $userId = (int) ($payload[$field] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $groupId = DB::table('web_database_user')
                ->where('database_user_id', $userId)
                ->value('sys_groupid');

            if ($groupId !== null && (int) $groupId !== (int) $parent->sys_groupid) {
                throw ValidationException::withMessages([
                    $field => 'The database user belongs to a different client than the site.',
                ]);
            }
        }
    }

    /**
     * Legacy database_name_error_len + blacklist (the control panel's own
     * database and `mysql`).
     */
    public function assertValidDatabaseName(string $fullName, string $prefix): void
    {
        if (strlen($fullName) > 64) {
            throw ValidationException::withMessages([
                'database_name' => "The database name '{$fullName}' (including the prefix '{$prefix}') must not exceed 64 characters.",
            ]);
        }

        $connection = (string) config('database.default');
        $apiDbName = (string) config("database.connections.{$connection}.database", '');

        if (in_array($fullName, array_filter([$apiDbName, 'mysql']), true)) {
            throw ValidationException::withMessages([
                'database_name' => 'This database name is not allowed.',
            ]);
        }
    }

    /**
     * Legacy per-server duplicate check.
     */
    public function assertUniquePerServer(string $fullName, int $serverId, ?int $excludeId = null): void
    {
        $exists = DB::table('web_database')
            ->where('database_name', $fullName)
            ->where('server_id', $serverId)
            ->when($excludeId !== null, fn ($q) => $q->where('database_id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'database_name' => "The database name '{$fullName}' already exists on this server.",
            ]);
        }
    }

    /**
     * Legacy PostgreSQL constraint: the rw and ro users must not be used
     * by another PostgreSQL database on the same server.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertPostgresUsersUnused(array $payload, int $serverId, ?int $excludeId = null): void
    {
        if (($payload['type'] ?? 'mysql') !== 'postgresql') {
            return;
        }

        $checks = [
            'database_user_id' => (int) ($payload['database_user_id'] ?? 0),
        ];

        $roUserId = (int) ($payload['database_ro_user_id'] ?? 0);
        if ($roUserId !== 0 && $roUserId !== $checks['database_user_id']) {
            $checks['database_ro_user_id'] = $roUserId;
        }

        foreach ($checks as $field => $userId) {
            if ($userId <= 0) {
                continue;
            }

            $inUse = DB::table('web_database')
                ->where('type', 'postgresql')
                ->where('server_id', $serverId)
                ->where(fn ($q) => $q->where('database_user_id', $userId)->orWhere('database_ro_user_id', $userId))
                ->when($excludeId !== null, fn ($q) => $q->where('database_id', '!=', $excludeId))
                ->exists();

            if ($inUse) {
                throw ValidationException::withMessages([
                    $field => 'This database user is already used by another PostgreSQL database on this server.',
                ]);
            }
        }
    }
}
