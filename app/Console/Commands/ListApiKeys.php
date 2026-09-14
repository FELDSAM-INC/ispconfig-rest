<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class ListApiKeys extends Command
{
    protected $signature = 'api:key:list {--client-id= : Only keys bound to this client\'s control-panel identity}';

    protected $description = 'List API keys with their scope and state (the key and its hash are never shown)';

    public function handle(ApiKeyService $service): int
    {
        $query = ApiKey::query()->orderBy('id');

        $clientId = $this->option('client-id');

        if ($clientId !== null && $clientId !== '') {
            if (filter_var($clientId, FILTER_VALIDATE_INT) === false || (int) $clientId < 1) {
                $this->error('--client-id must be a positive integer.');

                return self::FAILURE;
            }

            $service->whereBoundToClient($query, (int) $clientId);
        }

        $rows = array_map(fn (array $key): array => [
            $key['id'],
            $key['name'],
            $key['scope'],
            $key['client_id'] ?? '-',
            $key['active'] ? 'yes' : 'no',
            $key['last_used_at'] ?? 'never',
        ], $service->present($query->get()));

        if ($rows === []) {
            $this->info('No API keys found.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Scope', 'Client', 'Active', 'Last used'], $rows);

        return self::SUCCESS;
    }
}
