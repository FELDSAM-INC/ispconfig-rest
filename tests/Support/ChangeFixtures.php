<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Journal and server fixtures for change status tests (spec 015).
 *
 * Seeds `server` rows with the processing columns (active, mirror_server_id,
 * updated watermark) and `sys_datalog` entries directly. Call after
 * MonitorSchema::create() (and any module/tenant schema).
 */
trait ChangeFixtures
{
    protected function addServer(int $id, bool $active = true, int $mirrorOf = 0, int $updated = 0): void
    {
        DB::table('server')->updateOrInsert(
            ['server_id' => $id],
            [
                'server_name' => 'server'.$id,
                'active' => $active ? 1 : 0,
                'mirror_server_id' => $mirrorOf,
                'updated' => $updated,
            ]
        );
    }

    protected function setWatermark(int $serverId, int $updated): void
    {
        DB::table('server')->where('server_id', $serverId)->update(['updated' => $updated]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function journalEntry(array $overrides = []): int
    {
        return (int) DB::table('sys_datalog')->insertGetId(array_merge([
            'server_id' => 1,
            'dbtable' => 'mail_domain',
            'dbidx' => 'domain_id:1',
            'action' => 'i',
            'tstamp' => 1700000000,
            'user' => 'admin',
            'data' => serialize(['new' => ['domain' => 'example.com'], 'old' => ['domain' => null]]),
            'status' => 'ok',
            'error' => '',
            'session_id' => 'set-1',
        ], $overrides), 'datalog_id');
    }
}
