<?php

namespace Tests\Feature;

use Tests\Support\UsageApiTestCase;

/**
 * Database usage (contract: api/modules/usage/databases.yaml, spec 017 US2).
 */
class DatabaseUsageApiTest extends UsageApiTestCase
{
    private const MB = 1048576;

    private int $d1;

    private int $d4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d1 = $this->database('clientA', 'c1a', 512);
        $this->database('clientA', 'c1b', 0);
        $this->database('clientA', 'c1c', null, ['server_id' => 2]);
        $this->d4 = $this->database('clientB', 'c2b', 7);

        $this->blob(1, 'database_size', [
            ['database_name' => 'c1a', 'size' => 5 * self::MB, 'sys_groupid' => '2'],
            ['database_name' => 'c1b', 'size' => 1024, 'sys_groupid' => '2'],
            ['database_name' => 'c2b', 'size' => 99, 'sys_groupid' => '3'],
        ], 60);

        // another server's blob must never be matched to server 1 databases
        $this->blob(2, 'database_size', [
            ['database_name' => 'c1a', 'size' => 777, 'sys_groupid' => '2'],
        ], 60);
    }

    public function test_lists_readable_databases_with_size_from_their_own_server(): void
    {
        $response = $this->getAs('clientA', '/usage/databases')->assertOk();

        $response->assertJsonPath('meta.total', 3);
        $this->assertSame(['c1a', 'c1b', 'c1c'], array_column($response->json('data'), 'database_name'));

        $rows = collect($response->json('data'))->keyBy('database_name');

        $c1a = $rows['c1a'];
        $this->assertSame($this->d1, $c1a['database_id']);
        $this->assertSame('mysql', $c1a['type']);
        $this->assertSame(1, $c1a['server_id']);
        $this->assertSame(0, $c1a['parent_domain_id']);
        $this->assertSame(5 * self::MB, $c1a['size_bytes']);
        $this->assertSame(512 * self::MB, $c1a['quota_bytes']);
        $this->assertEqualsWithDelta(1.0, $c1a['used_percent'], 0.0001);
        $this->assertSame($this->iso(60), $c1a['measured_at']);

        $this->assertSame(1024, $rows['c1b']['size_bytes']);
        $this->assertNull($rows['c1b']['quota_bytes']);
        $this->assertNull($rows['c1b']['used_percent']);

        // on server 2 and absent from that server's blob
        $this->assertNull($rows['c1c']['size_bytes']);
        $this->assertNull($rows['c1c']['measured_at']);
    }

    public function test_filters_and_strict_parameters(): void
    {
        $this->getAs('clientA', '/usage/databases?database_name=c1*')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getAs('clientA', '/usage/databases?database_name=c1b')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getAs('clientA', '/usage/databases?sort=size')->assertStatus(400);
        $this->getAs('clientA', '/usage/databases?client_id='.$this->tenant('clientA')['client_id'])->assertStatus(400);
        $this->getAs('admin', '/usage/databases?client_id='.$this->tenant('clientB')['client_id'])->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_detail_is_scoped(): void
    {
        $this->getAs('clientA', '/usage/databases/'.$this->d1)
            ->assertOk()
            ->assertJsonPath('database_name', 'c1a')
            ->assertJsonPath('size_bytes', 5 * self::MB);

        $this->getAs('clientA', '/usage/databases/'.$this->d4)->assertNotFound();
    }
}
