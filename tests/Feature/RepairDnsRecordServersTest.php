<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\SitesApiTestCase;

/**
 * Migration 2026_10_17_000001: DNS records left on server 1 move to their zone's server through the datalog.
 */
class RepairDnsRecordServersTest extends SitesApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DnsSchema::create();
        DB::table('dns_soa')->insert([
            ['id' => 10, 'origin' => 'moved.test.', 'server_id' => 4, 'serial' => 2026101701, 'sys_groupid' => 5],
            ['id' => 11, 'origin' => 'fine.test.', 'server_id' => 4, 'serial' => 2026101701, 'sys_groupid' => 5],
        ]);
        DB::table('dns_rr')->insert([
            ['id' => 100, 'zone' => 10, 'server_id' => 1, 'name' => 'moved.test.', 'type' => 'NS', 'data' => 'ns.host.test.', 'sys_groupid' => 5],
            ['id' => 101, 'zone' => 10, 'server_id' => 1, 'name' => 'www', 'type' => 'A', 'data' => '192.0.2.10', 'sys_groupid' => 5],
            ['id' => 102, 'zone' => 11, 'server_id' => 4, 'name' => 'fine.test.', 'type' => 'NS', 'data' => 'ns.host.test.', 'sys_groupid' => 5],
            // A record without its zone is left alone
            ['id' => 103, 'zone' => 99, 'server_id' => 1, 'name' => 'gone.test.', 'type' => 'A', 'data' => '192.0.2.11', 'sys_groupid' => 5],
        ]);
    }

    public function test_records_move_to_their_zone_server_through_the_datalog(): void
    {
        $this->migration()->up();

        $this->assertSame([100 => 4, 101 => 4, 102 => 4, 103 => 1], DB::table('dns_rr')->orderBy('id')->pluck('server_id', 'id')->map(fn ($id) => (int) $id)->all());

        $rows = DB::table('sys_datalog')->orderBy('datalog_id')->get();
        $this->assertSame([['dns_rr', 'id:100', 4, 'u'], ['dns_rr', 'id:101', 4, 'u'], ['dns_soa', 'id:10', 4, 'u']],
            $rows->map(fn ($row) => [$row->dbtable, $row->dbidx, (int) $row->server_id, $row->action])->all());
        // The name server replicates the full record (REPLACE INTO), so the row it never had is created
        $data = unserialize($rows[1]->data);
        $this->assertSame('4', $data['new']['server_id']);
        $this->assertSame('192.0.2.10', $data['new']['data']);
        $this->assertSame('1', $data['old']['server_id']);
        $this->assertGreaterThan(2026101701, (int) DB::table('dns_soa')->where('id', 10)->value('serial'));
        $this->assertSame(2026101701, (int) DB::table('dns_soa')->where('id', 11)->value('serial'));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->migration()->up();
        $count = DB::table('sys_datalog')->count();

        $this->migration()->up();

        $this->assertSame($count, DB::table('sys_datalog')->count());
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_17_000001_repair_dns_record_servers.php');
    }
}
