<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-owned cgroup limits of one account (spec 053). The account is a
 * systemd slice on each webserver and every isolated PHP-FPM pool a service in it;
 * the php-limits worker applies the stored revision. Nothing native is written.
 */
final class ClientResourceLimitsService
{
    public const LEVELS = ['account', 'website'];

    public const FIELDS = ['cpu_percent', 'memory_mb', 'tasks'];

    /** Bounds: 1 % of one CPU, 64 MiB, and room for a master plus a few workers. */
    private const BOUNDS = ['cpu_percent' => [1, 100000], 'memory_mb' => [64, 16777216], 'tasks' => [8, 4194304]];

    /**
     * @param  array<string, mixed>|null  $phpPolicy  the stored or submitted product PHP policy
     * @return array{account: array<string, int|null>, website: array<string, int|null>}
     */
    public static function validate(array $limits, ?array $phpPolicy = null): array
    {
        $rules = ['limits' => ['required', 'array:'.implode(',', self::LEVELS)]];
        foreach (self::LEVELS as $level) {
            $rules["limits.$level"] = ['present', 'array:'.implode(',', self::FIELDS)];
            foreach (self::BOUNDS as $field => [$min, $max]) {
                $rules["limits.$level.$field"] = ['present', 'nullable', 'integer', "min:$min", "max:$max"];
            }
        }
        Validator::make(['limits' => $limits], $rules, [], ['limits' => 'web_resource_limits'])->validate();
        $normalized = [];
        foreach (self::LEVELS as $level) {
            foreach (self::FIELDS as $field) {
                $value = $limits[$level][$field];
                $normalized[$level][$field] = $value === null ? null : (int) $value;
            }
        }
        foreach (self::FIELDS as $field) {
            $account = $normalized['account'][$field];
            $website = $normalized['website'][$field];
            if ($account !== null && $website !== null && $website > $account) {
                throw ValidationException::withMessages(['web_resource_limits' => "website.$field must not exceed account.$field."]);
            }
        }
        $children = (int) ($phpPolicy['pm_max_children'] ?? 0);
        $tasks = array_filter([$normalized['account']['tasks'], $normalized['website']['tasks']], fn (?int $value): bool => $value !== null);
        if ($children > 0 && $tasks !== [] && min($tasks) < $children + 1) {
            throw ValidationException::withMessages(['web_resource_limits' => 'The tasks limit must allow pm_max_children ('.$children.') workers plus the pool master.']);
        }

        return $normalized;
    }

    /** @return array{account: array<string, int|null>, website: array<string, int|null>}|null */
    public function limits(int $clientId): ?array
    {
        $row = $this->row($clientId);

        return $row === null ? null : json_decode($row->settings, true, 8, JSON_THROW_ON_ERROR);
    }

    public function revision(int $clientId): ?int
    {
        $row = $this->row($clientId);

        return $row === null ? null : (int) $row->revision;
    }

    /** Called in the client transaction. */
    public function store(int $clientId, ?array $limits, ?array $phpPolicy): void
    {
        if (! Schema::hasTable('api_client_resource_limits')) {
            if ($limits === null) {
                return;
            }
            throw ValidationException::withMessages(['web_resource_limits' => 'Run the PHP resource limits migration first.']);
        }
        $old = DB::table('api_client_resource_limits')->where('client_id', $clientId)->lockForUpdate()->first();
        if ($limits === null) {
            DB::table('api_client_resource_limits')->where('client_id', $clientId)->delete();

            return;
        }
        $limits = self::validate($limits, $phpPolicy);
        $settings = json_encode($limits, JSON_THROW_ON_ERROR);
        if ($old !== null && $old->settings === $settings) {
            return;
        }
        // Millisecond revisions stay monotonic across delete and re-create.
        $revision = max((int) floor(microtime(true) * 1000), (int) ($old->revision ?? 0) + 1);
        DB::table('api_client_resource_limits')->updateOrInsert(['client_id' => $clientId], ['settings' => $settings, 'revision' => $revision]);
    }

    /** Re-check the stored limits when only the PHP policy changed. */
    public function assertCompatible(int $clientId, ?array $phpPolicy): void
    {
        $limits = $this->limits($clientId);
        if ($limits !== null) {
            self::validate($limits, $phpPolicy);
        }
    }

    public function forgetClient(int $clientId): void
    {
        if (Schema::hasTable('api_client_resource_limits')) {
            DB::table('api_client_resource_limits')->where('client_id', $clientId)->delete();
        }
        if (Schema::hasTable('api_php_limits_usage')) {
            DB::table('api_php_limits_usage')->where('client_id', $clientId)->delete();
        }
    }

    private function row(int $clientId): ?object
    {
        if ($clientId < 1 || ! Schema::hasTable('api_client_resource_limits')) {
            return null;
        }

        return DB::table('api_client_resource_limits')->where('client_id', $clientId)->first();
    }
}
