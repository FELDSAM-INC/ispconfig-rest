<?php

namespace Tests\Feature;

use App\Models\ServerFirewall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

class FirewallAllowCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ServerSchema::create();
        DB::table('server')->insert(['server_id' => 1, 'server_name' => 'host.example.com']);
    }

    private function seedFirewall(string $tcp): void
    {
        DB::table('firewall')->insert([
            'server_id' => 1, 'tcp_port' => $tcp, 'udp_port' => '53', 'active' => 'y',
            'sys_userid' => 1, 'sys_groupid' => 1, 'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud', 'sys_perm_other' => '',
        ]);
    }

    public function test_adds_port_to_the_firewall_via_datalog(): void
    {
        $this->seedFirewall('22,80,443');

        $this->artisan('firewall:allow', ['port' => 8090])->assertExitCode(0);

        $this->assertSame('22,80,443,8090', ServerFirewall::first()->getRawOriginal('tcp_port'));
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'firewall')->where('action', 'u')->count());
    }

    public function test_is_idempotent_when_port_already_present(): void
    {
        $this->seedFirewall('22,80,443,8090');

        $this->artisan('firewall:allow', ['port' => 8090])->assertExitCode(0);

        $this->assertSame('22,80,443,8090', ServerFirewall::first()->getRawOriginal('tcp_port'));
        $this->assertSame(0, DB::table('sys_datalog')->count(), 'no datalog when nothing changed');
    }

    public function test_recognizes_a_port_inside_an_existing_range(): void
    {
        $this->seedFirewall('22,8000:8100');

        $this->artisan('firewall:allow', ['port' => 8090])->assertExitCode(0);

        $this->assertSame('22,8000:8100', ServerFirewall::first()->getRawOriginal('tcp_port'));
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_returns_2_when_no_firewall_record_exists(): void
    {
        // No firewall row seeded — must NOT create one (would restrict the firewall).
        $this->artisan('firewall:allow', ['port' => 8090])->assertExitCode(2);

        $this->assertSame(0, DB::table('firewall')->count());
    }

    public function test_rejects_invalid_port(): void
    {
        $this->seedFirewall('22');
        $this->artisan('firewall:allow', ['port' => 99999])->assertExitCode(1);
    }

    public function test_udp_protocol_targets_the_udp_list(): void
    {
        $this->seedFirewall('22');

        $this->artisan('firewall:allow', ['port' => 1194, '--proto' => 'udp'])->assertExitCode(0);

        $this->assertSame('53,1194', ServerFirewall::first()->getRawOriginal('udp_port'));
    }
}
