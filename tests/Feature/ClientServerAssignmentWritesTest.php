<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 016 US3: secondary DNS zones use the account's secondary DNS server
 * (legacy dns_slave_edit.php:182-193), fetchmail uses the destination
 * mailbox's server (mail_get_edit.php:97) and only readable destinations
 * (FR-014, mail_get.tform.php AUTHSQL datasource), and client and reseller
 * keys cannot move DNS zones or secondary zones. Admin keys are unchanged.
 */
class ClientServerAssignmentWritesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        $servers = [
            [1, 'mail1', 'mail', 0],
            [2, 'mail2', 'mail', 0],
            [3, 'ns1', 'dns', 0],
            [4, 'ns2', 'dns', 0],
            [5, 'ns2-mirror', 'dns', 4],
        ];

        foreach ($servers as [$id, $name, $role, $mirror]) {
            DB::table('server')->insert([
                'server_id' => $id,
                'server_name' => $name,
                'mail_server' => $role === 'mail' ? 1 : 0,
                'dns_server' => $role === 'dns' ? 1 : 0,
                'mirror_server_id' => $mirror,
                'active' => 1,
            ]);
        }

        DB::table('mail_user')->insert($this->ownedBy('clientA', ['server_id' => 2, 'email' => 'a@a-dom.test', 'login' => 'a@a-dom.test']));
        DB::table('mail_user')->insert($this->ownedBy('clientA', ['server_id' => 2, 'email' => 'a2@a-dom.test', 'login' => 'a2@a-dom.test']));
        DB::table('mail_user')->insert($this->ownedBy('clientB', ['server_id' => 1, 'email' => 'b@b-dom.test', 'login' => 'b@b-dom.test']));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function fetchmailPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'pop3',
            'source_server' => 'pop.remote.tld',
            'source_username' => 'remoteuser',
            'source_password' => 'remotepass',
            'destination' => 'a@a-dom.test',
        ], $overrides);
    }

    public function test_secondary_zone_uses_the_account_secondary_dns_server(): void
    {
        $this->assignServers('clientA', ['dns' => [3]], 4);

        $this->postJson('/api/v1/dns/slaves', ['origin' => 'slave-a.test', 'ns' => '203.0.113.1'], $this->tenantHeaders('clientA'))
            ->assertStatus(201);
        $this->assertSame(4, (int) DB::table('dns_slave')->where('origin', 'slave-a.test.')->value('server_id'));

        $this->postJson('/api/v1/dns/slaves', ['origin' => 'slave-b.test', 'ns' => '203.0.113.1', 'server_id' => 4], $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/dns/slaves', ['origin' => 'slave-c.test', 'ns' => '203.0.113.1', 'server_id' => 3], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The selected server is not available for this account.')
            ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
    }

    public function test_secondary_zone_without_valid_secondary_dns_server(): void
    {
        foreach ([0, 5, 1] as $slave) {
            $this->assignServers('clientA', [], $slave);

            $this->postJson('/api/v1/dns/slaves', ['origin' => 'no-slave.test', 'ns' => '203.0.113.1'], $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonPath('errors.server_id.0', 'No secondary DNS server is assigned to this account.')
                ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');

            $this->postJson('/api/v1/dns/slaves', ['origin' => 'no-slave.test', 'ns' => '203.0.113.1', 'server_id' => 4], $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonPath('errors.server_id.0', 'No secondary DNS server is assigned to this account.')
                ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');
        }

        // Admin keys unchanged: server_id required.
        $this->postJson('/api/v1/dns/slaves', ['origin' => 'admin-slave.test', 'ns' => '203.0.113.1'], $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The server id field is required.')
            ->assertJsonMissingPath('error_types');
    }

    public function test_reseller_uses_its_own_secondary_dns_server(): void
    {
        $this->assignServers('reseller', [], 3);
        $this->assignServers('clientA', [], 4);

        $this->postJson('/api/v1/dns/slaves', ['origin' => 'reseller-slave.test', 'ns' => '203.0.113.1'], $this->tenantHeaders('reseller'))
            ->assertStatus(201);

        $this->assertSame(3, (int) DB::table('dns_slave')->where('origin', 'reseller-slave.test.')->value('server_id'));
    }

    public function test_fetchmail_uses_the_destination_mailbox_server(): void
    {
        $this->postJson('/api/v1/mail/fetchmail', $this->fetchmailPayload(), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
        $this->assertSame(2, (int) DB::table('mail_get')->where('destination', 'a@a-dom.test')->value('server_id'));

        $this->postJson('/api/v1/mail/fetchmail', $this->fetchmailPayload(['destination' => 'a2@a-dom.test', 'server_id' => 2]), $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        // The destination mailbox decides the server; a mismatch is not an
        // assignment refusal and stays untyped (spec 023).
        $this->postJson('/api/v1/mail/fetchmail', $this->fetchmailPayload(['server_id' => 1]), $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The selected server is not available for this account.')
            ->assertJsonMissingPath('error_types');
    }

    public function test_fetchmail_destination_must_be_readable_by_non_admin_keys(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $foreign = $this->postJson('/api/v1/mail/fetchmail', $this->fetchmailPayload(['destination' => 'b@b-dom.test']), $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->json('errors');

        $missing = $this->postJson('/api/v1/mail/fetchmail', $this->fetchmailPayload(['destination' => 'nobody@none.test']), $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->json('errors');

        $this->assertSame($missing, $foreign);
        $this->assertSame(['destination'], array_keys($foreign));
        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // Update: changing the destination to another tenant's mailbox is refused.
        $own = (int) DB::table('mail_get')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 2, 'type' => 'pop3', 'source_server' => 'pop.remote.tld',
            'source_username' => 'u', 'source_password' => 'p', 'destination' => 'a@a-dom.test', 'active' => 'y',
        ]), 'mailget_id');

        $this->putJson("/api/v1/mail/fetchmail/{$own}", ['destination' => 'b@b-dom.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.destination', $missing['destination']);

        $this->putJson("/api/v1/mail/fetchmail/{$own}", ['destination' => 'a2@a-dom.test'], $this->tenantHeaders('clientA'))
            ->assertOk();

        // Admin keys may use any existing mailbox.
        $this->postJson('/api/v1/mail/fetchmail', $this->fetchmailPayload(['destination' => 'b@b-dom.test', 'server_id' => 1]), $this->tenantHeaders('admin'))
            ->assertStatus(201);
    }

    public function test_dns_zone_server_cannot_be_moved_by_non_admin_keys(): void
    {
        $zone = (int) DB::table('dns_soa')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 3, 'origin' => 'move-me.test.', 'ns' => 'ns1.move-me.test.',
            'mbox' => 'hostmaster.move-me.test.', 'serial' => '1', 'active' => 'Y',
        ]));

        $this->putJson("/api/v1/dns/soa/{$zone}", ['server_id' => 4], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The server cannot be changed after creation.')
            ->assertJsonMissingPath('error_types');

        $this->putJson("/api/v1/dns/soa/{$zone}", ['server_id' => 3, 'ttl' => 3600], $this->tenantHeaders('clientA'))
            ->assertOk();

        $this->putJson("/api/v1/dns/soa/{$zone}", ['server_id' => 4], $this->tenantHeaders('admin'))
            ->assertOk();

        $this->assertSame(4, (int) DB::table('dns_soa')->where('id', $zone)->value('server_id'));
    }

    public function test_secondary_zone_server_cannot_be_moved_by_non_admin_keys(): void
    {
        $slave = (int) DB::table('dns_slave')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 4, 'origin' => 'move-slave.test.', 'ns' => '203.0.113.1', 'active' => 'Y',
        ]));

        $this->putJson("/api/v1/dns/slaves/{$slave}", ['server_id' => 3], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The server cannot be changed after creation.')
            ->assertJsonMissingPath('error_types');

        $this->putJson("/api/v1/dns/slaves/{$slave}", ['server_id' => 4], $this->tenantHeaders('clientA'))
            ->assertOk();

        $this->putJson("/api/v1/dns/slaves/{$slave}", ['server_id' => 3], $this->tenantHeaders('admin'))
            ->assertOk();

        $this->assertSame(3, (int) DB::table('dns_slave')->where('id', $slave)->value('server_id'));
    }
}
