<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\ServerSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me/hosting-addresses (spec 031, api/modules/me/hosting-addresses.yaml):
 * public addresses of the account's web and mail servers visible to the
 * client (server_ip client_id 0 or own) and the zone-import name servers of
 * its DNS servers (server + mirrors by name + dns_external_slave_fqdn).
 */
class MeHostingAddressesApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/v1/me/hosting-addresses';

    protected function setUp(): void
    {
        parent::setUp();

        ServerSchema::create();
        SitesSchema::create();
        MailCompletionSchema::create();
        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        $this->server(1, 'web1.example.com', ['web_server' => 1]);
        $this->server(2, 'web2.example.com', ['web_server' => 1]);
        $this->server(3, 'mail1.example.com', ['mail_server' => 1]);
        $this->server(4, 'ns1.example.com', ['dns_server' => 1]);
        $this->server(5, 'ns2.example.com', ['dns_server' => 1, 'mirror_server_id' => 4]);
        $this->server(6, 'ns0-mirror.example.com', ['dns_server' => 1, 'mirror_server_id' => 4, 'active' => 0]);
        $this->server(7, 'ns7.example.com', ['dns_server' => 1]);
        $this->server(8, 'web8.example.com', ['web_server' => 1]);
        $this->server(9, 'web9-mirror.example.com', ['web_server' => 1, 'mirror_server_id' => 1]);

        $clientA = $this->tenant('clientA')['client_id'];
        $clientB = $this->tenant('clientB')['client_id'];
        $reseller = $this->tenant('reseller')['client_id'];

        // server 1: every visibility and validity case
        $this->ip(1, '192.0.2.10', 'IPv4', 0, 'y');
        $this->ip(1, '192.0.2.11', 'IPv4', $clientA, 'n');     // dedicated to clientA
        $this->ip(1, '192.0.2.12', 'IPv4', $clientB);          // dedicated to another client
        $this->ip(1, '198.51.100.1', 'IPv4', $reseller);       // dedicated to the reseller
        $this->ip(1, '10.0.0.5', 'IPv4');                      // private
        $this->ip(1, '127.0.0.1', 'IPv4');                     // loopback
        $this->ip(1, '2001:db8::10', 'IPv6', 0, 'n');
        $this->ip(1, 'fd00::5', 'IPv6');                       // unique local
        $this->ip(1, 'fe80::1', 'IPv6');                       // link-local
        $this->ip(1, '2001:db8::11', 'IPv4');                  // type mismatch
        $this->ip(1, '192.0.2.10', 'IPv4');                    // duplicate
        $this->ip(1, '2001:0db8:0000::10', 'IPv6');            // duplicate spelling
        $this->ip(3, '192.0.2.30', 'IPv4');
        $this->ip(4, '192.0.2.40', 'IPv4');
        $this->ip(4, '2001:db8::40', 'IPv6');
        $this->ip(5, '198.51.100.50', 'IPv4');
        $this->ip(7, '203.0.113.70', 'IPv4');
        $this->ip(8, '203.0.113.80', 'IPv4');

        // clientA: mirror (9) and missing (99) entries are skipped
        $this->assignServers('clientA', ['web' => [9, 1, 2, 99], 'mail' => [3], 'dns' => [4, 3]]);
        $this->assignServers('reseller', ['web' => [1]]);

        // clientA resources on assigned and unassigned servers
        $this->website('clientA', 'a.test', 1);
        $this->website('clientA', 'b.test', 8);
        $this->zone('clientA', 'a.test.', 4);
        $this->zone('clientA', 'b.test.', 7);
        // another client's website does not add its server to clientA's list
        $this->website('clientB', 'c.test', 2);

        $this->setExternalDns('ns3.example.net, NS1.example.com.  ns4.example.org.');
    }

    public function test_client_key_gets_addresses_and_name_servers_of_its_servers(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson([
                'client_id' => $this->tenant('clientA')['client_id'],
                'web' => [
                    $this->entry(1, 'web1.example.com', true, ['192.0.2.10', '192.0.2.11'], ['2001:db8::10']),
                    $this->entry(2, 'web2.example.com', false, [], []),
                    $this->entry(8, 'web8.example.com', false, ['203.0.113.80'], []),
                ],
                'mail' => [
                    $this->entry(3, 'mail1.example.com', true, ['192.0.2.30'], []),
                ],
                'dns' => [
                    [
                        'server_id' => 4,
                        'server_name' => 'ns1.example.com',
                        'is_default' => true,
                        'nameservers' => [
                            $this->nameserver('ns0-mirror.example.com'),
                            $this->nameserver('ns1.example.com', ['192.0.2.40'], ['2001:db8::40']),
                            $this->nameserver('ns2.example.com', ['198.51.100.50']),
                            $this->nameserver('ns3.example.net'),
                            $this->nameserver('ns4.example.org'),
                        ],
                    ],
                    [
                        'server_id' => 7,
                        'server_name' => 'ns7.example.com',
                        'is_default' => false,
                        'nameservers' => [
                            $this->nameserver('ns7.example.com', ['203.0.113.70']),
                            $this->nameserver('ns3.example.net'),
                            // only a duplicate for the server actually named ns1
                            $this->nameserver('NS1.example.com'),
                            $this->nameserver('ns4.example.org'),
                        ],
                    ],
                ],
            ]);

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
    }

    public function test_mail_servers_hosting_mail_domains_follow_the_assigned_ones(): void
    {
        $this->server(10, 'mail10.example.com', ['mail_server' => 1]);
        $this->ip(10, '203.0.113.100', 'IPv4');
        DB::table('mail_domain')->insert($this->ownedBy('clientA', [
            'server_id' => 10, 'domain' => 'a.test', 'active' => 'y',
        ]));

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('mail', [
                $this->entry(3, 'mail1.example.com', true, ['192.0.2.30'], []),
                $this->entry(10, 'mail10.example.com', false, ['203.0.113.100'], []),
            ]);
    }

    public function test_name_servers_without_external_setting(): void
    {
        DB::table('sys_ini')->delete();

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('dns.1.nameservers', [
                $this->nameserver('ns7.example.com', ['203.0.113.70']),
            ]);
    }

    public function test_account_without_servers_or_resources_gets_empty_lists(): void
    {
        DB::table('web_domain')->where('domain', 'c.test')->delete();

        $this->getJson(self::URL, $this->tenantHeaders('clientB'))
            ->assertOk()
            ->assertExactJson([
                'client_id' => $this->tenant('clientB')['client_id'],
                'web' => [],
                'mail' => [],
                'dns' => [],
            ]);
    }

    public function test_reseller_key_describes_itself_or_a_child_client(): void
    {
        $this->getJson(self::URL, $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertJsonPath('web', [
                $this->entry(1, 'web1.example.com', true, ['192.0.2.10', '198.51.100.1'], ['2001:db8::10']),
            ])
            ->assertJsonPath('dns', []);

        $clientA = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->json();

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientA')['client_id'], $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertExactJson($clientA);

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientB')['client_id'], $this->tenantHeaders('reseller'))
            ->assertNotFound();
    }

    public function test_admin_key_must_name_the_client(): void
    {
        $this->getJson(self::URL, $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['errors' => ['client_id']]);

        $this->getJson(self::URL.'?client_id=999999', $this->tenantHeaders('admin'))->assertNotFound();

        $clientA = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->json();

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientA')['client_id'], $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertExactJson($clientA);
    }

    public function test_client_key_cannot_name_another_client_or_send_unknown_parameters(): void
    {
        $this->getJson(self::URL.'?client_id='.$this->tenant('clientA')['client_id'], $this->tenantHeaders('clientA'))
            ->assertOk();

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientB')['client_id'], $this->tenantHeaders('clientA'))
            ->assertNotFound();

        $this->getJson(self::URL.'?server_id=1', $this->tenantHeaders('clientA'))->assertStatus(400);
        $this->getJson(self::URL.'?client_id=abc', $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The client id must be a positive integer.');
        $this->getJson(self::URL)->assertUnauthorized();
    }

    /**
     * @param  array<string, int>  $flags
     */
    private function server(int $id, string $name, array $flags): void
    {
        DB::table('server')->insert(array_merge([
            'server_id' => $id,
            'server_name' => $name,
            'web_server' => 0,
            'mail_server' => 0,
            'db_server' => 0,
            'dns_server' => 0,
            'mirror_server_id' => 0,
            'active' => 1,
        ], $flags));
    }

    private function ip(int $serverId, string $address, string $type, int $clientId = 0, string $virtualhost = 'y'): void
    {
        DB::table('server_ip')->insert([
            'server_id' => $serverId,
            'client_id' => $clientId,
            'ip_type' => $type,
            'ip_address' => $address,
            'virtualhost' => $virtualhost,
        ]);
    }

    private function website(string $tenant, string $domain, int $serverId): void
    {
        DB::table('web_domain')->insert($this->ownedBy($tenant, [
            'server_id' => $serverId, 'domain' => $domain, 'type' => 'vhost', 'active' => 'y',
        ]));
    }

    private function zone(string $tenant, string $origin, int $serverId): void
    {
        DB::table('dns_soa')->insert($this->ownedBy($tenant, [
            'server_id' => $serverId, 'origin' => $origin, 'ns' => 'ns1.'.$origin,
            'mbox' => 'admin.'.$origin, 'serial' => '1', 'active' => 'Y',
        ]));
    }

    private function setExternalDns(string $value): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => "[dns]\ndefault_dnsserver=4\ndns_external_slave_fqdn={$value}\n",
        ]);
    }

    /**
     * @param  array<int, string>  $ipv4
     * @param  array<int, string>  $ipv6
     * @return array<string, mixed>
     */
    private function entry(int $id, string $name, bool $default, array $ipv4, array $ipv6): array
    {
        return ['server_id' => $id, 'server_name' => $name, 'is_default' => $default, 'ipv4' => $ipv4, 'ipv6' => $ipv6];
    }

    /**
     * @param  array<int, string>  $ipv4
     * @param  array<int, string>  $ipv6
     * @return array<string, mixed>
     */
    private function nameserver(string $name, array $ipv4 = [], array $ipv6 = []): array
    {
        return ['name' => $name, 'ipv4' => $ipv4, 'ipv6' => $ipv6];
    }
}
