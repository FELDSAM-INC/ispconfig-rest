<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only discovery for the separately installed, root-owned CLI manager. */
class ServerToolsInventory extends Command
{
    protected $signature = 'server-tools:inventory';

    protected $description = 'List ISPConfig server roles as JSON for the server tools installer (no credentials)';

    public function handle(): int
    {
        $servers = DB::table('server')->orderBy('server_id')
            ->get(['server_id', 'server_name', 'active', 'web_server', 'db_server', 'mirror_server_id'])
            ->map(static fn ($row) => [
                'id' => (int) $row->server_id,
                'host' => (string) $row->server_name,
                'active' => (bool) $row->active,
                'web' => (bool) $row->web_server,
                'database' => (bool) $row->db_server,
                'mirror_of' => (int) $row->mirror_server_id,
            ])->all();
        $this->line(json_encode(['servers' => $servers], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
