<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Mail-module authorization matrix (spec 011 SC-001/SC-002/SC-006, FR-017):
 * four keys (admin / owner A / other-client B / reseller-of-A) over the
 * row-scoped mail resources, plus the write half — forced sys-field
 * stamping, 403 on visible-but-unpermitted rows, admin-only write gates and
 * the limit-access gates (transports / access-rules / spamfilter wblist).
 */
class ScopingMailModuleTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);
    }

    /**
     * Row-scoped mail resources: route => [table, per-owner attribute seeds].
     *
     * @return array<string, array{table: string, rows: array<string, array<string, mixed>>}>
     */
    protected function resources(): array
    {
        return [
            'mail/domains' => [
                'table' => 'mail_domain',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'domain' => 'a-dom.test', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'domain' => 'b-dom.test', 'active' => 'y'],
                ],
            ],
            'mail/users' => [
                'table' => 'mail_user',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'email' => 'a@a-dom.test', 'login' => 'a@a-dom.test'],
                    'clientB' => ['server_id' => 1, 'email' => 'b@b-dom.test', 'login' => 'b@b-dom.test'],
                ],
            ],
            'mail/forwards' => [
                'table' => 'mail_forwarding',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'source' => 'fwd@a-dom.test', 'destination' => 'x@a-dom.test', 'type' => 'forward', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'source' => 'fwd@b-dom.test', 'destination' => 'x@b-dom.test', 'type' => 'forward', 'active' => 'y'],
                ],
            ],
            'mail/alias-domains' => [
                'table' => 'mail_forwarding',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'source' => '@alias-a.test', 'destination' => '@a-dom.test', 'type' => 'aliasdomain', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'source' => '@alias-b.test', 'destination' => '@b-dom.test', 'type' => 'aliasdomain', 'active' => 'y'],
                ],
            ],
            'mail/fetchmail' => [
                'table' => 'mail_get',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'destination' => 'a@a-dom.test', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'destination' => 'b@b-dom.test', 'active' => 'y'],
                ],
            ],
            'mail/spamfilter/wblist' => [
                'table' => 'spamfilter_wblist',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'wb' => 'W', 'rid' => 1, 'email' => 'w@a-dom.test', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'wb' => 'W', 'rid' => 2, 'email' => 'w@b-dom.test', 'active' => 'y'],
                ],
            ],
            'mail/transports' => [
                'table' => 'mail_transport',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'domain' => 'route-a.test', 'transport' => 'smtp:relay-a.test', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'domain' => 'route-b.test', 'transport' => 'smtp:relay-b.test', 'active' => 'y'],
                ],
            ],
            'mail/access-rules' => [
                'table' => 'mail_access',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'source' => 'spam@a.test', 'access' => 'REJECT', 'type' => 'recipient', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'source' => 'spam@b.test', 'access' => 'REJECT', 'type' => 'recipient', 'active' => 'y'],
                ],
            ],
        ];
    }

    public function test_four_key_matrix_over_row_scoped_mail_resources(): void
    {
        foreach ($this->resources() as $route => $definition) {
            $ids = [];

            foreach ($definition['rows'] as $owner => $attrs) {
                $ids[$owner] = (int) DB::table($definition['table'])
                    ->insertGetId($this->ownedBy($owner, $attrs));
            }

            // Lists: silent filtering, meta.total counts visible rows only.
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('admin'))
                ->assertOk()->assertJsonPath('meta.total', 2);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('clientA'))
                ->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('clientB'))
                ->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('reseller'))
                ->assertOk()->assertJsonPath('meta.total', 1);

            // Shows: owner and admin reach the row, others 404.
            $this->getJson("/api/v1/{$route}/{$ids['clientA']}", $this->tenantHeaders('admin'))->assertOk();
            $this->getJson("/api/v1/{$route}/{$ids['clientA']}", $this->tenantHeaders('clientA'))->assertOk();
            $this->getJson("/api/v1/{$route}/{$ids['clientA']}", $this->tenantHeaders('reseller'))->assertOk();
            $this->getJson("/api/v1/{$route}/{$ids['clientA']}", $this->tenantHeaders('clientB'))
                ->assertStatus(404)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->getJson("/api/v1/{$route}/{$ids['clientB']}", $this->tenantHeaders('clientA'))->assertStatus(404);
            $this->getJson("/api/v1/{$route}/{$ids['clientB']}", $this->tenantHeaders('reseller'))->assertStatus(404);
        }
    }

    // ------------------------------------------------------------------
    // Spamfilter policies: world-readable seed convention
    // ------------------------------------------------------------------

    public function test_spamfilter_policies_are_world_readable_but_not_writable(): void
    {
        // Installer seeds policies with sys_perm_other='r', sys_groupid=0
        // (ispconfig3.sql:2523-2527).
        $policy = (int) DB::table('spamfilter_policy')->insertGetId([
            'sys_userid' => 1,
            'sys_groupid' => 0,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => 'r',
            'policy_name' => 'Normal',
        ]);

        foreach (['admin', 'clientA', 'clientB', 'reseller'] as $identity) {
            $this->getJson('/api/v1/mail/spamfilter/policies', $this->tenantHeaders($identity))
                ->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson("/api/v1/mail/spamfilter/policies/{$policy}", $this->tenantHeaders($identity))
                ->assertOk();
        }

        // Writes are admin-only (FR-017 hardening) — 403, and no datalog.
        $datalogCount = DB::table('sys_datalog')->count();

        $this->putJson("/api/v1/mail/spamfilter/policies/{$policy}", ['policy_name' => 'Hacked'], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->deleteJson("/api/v1/mail/spamfilter/policies/{$policy}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(403);
        $this->postJson('/api/v1/mail/spamfilter/policies', ['policy_name' => 'Mine'], $this->tenantHeaders('clientA'))
            ->assertStatus(403);

        $this->assertSame($datalogCount, DB::table('sys_datalog')->count());
        $this->assertDatabaseHas('spamfilter_policy', ['id' => $policy, 'policy_name' => 'Normal']);
    }

    // ------------------------------------------------------------------
    // Write half (SC-002/SC-006)
    // ------------------------------------------------------------------

    public function test_create_forces_sys_fields_from_the_key_despite_forged_payload(): void
    {
        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1,
            'domain' => 'forged.test',
            'active' => true,
            'dkim' => false,
            // Forged ownership: must be ignored (FR-012).
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_other' => 'riud',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->assertDatabaseHas('mail_domain', [
            'domain' => 'forged.test',
            'sys_userid' => $this->tenant('clientA')['userid'],
            'sys_groupid' => $this->tenant('clientA')['groupid'],
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
        ]);
    }

    public function test_visible_but_unpermitted_row_yields_403_not_404_and_no_datalog(): void
    {
        // Readable by everyone (perm_other 'r'), owned by B — A may read it
        // but has no 'u'/'d' anywhere (SC-006).
        $domain = (int) DB::table('mail_domain')->insertGetId($this->ownedBy('clientB', [
            'server_id' => 1,
            'domain' => 'shared.test',
            'active' => 'y',
            'sys_perm_other' => 'r',
        ]), 'domain_id');

        $datalogCount = DB::table('sys_datalog')->count();

        $this->putJson("/api/v1/mail/domains/{$domain}", ['active' => false], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Forbidden', 'status' => 403]);

        $this->deleteJson("/api/v1/mail/domains/{$domain}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(403);

        $this->assertSame($datalogCount, DB::table('sys_datalog')->count());
        $this->assertDatabaseHas('mail_domain', ['domain_id' => $domain, 'active' => 'y']);
    }

    public function test_admin_menu_only_mail_writes_are_admin_gated(): void
    {
        $gated = [
            'mail/relay-domains' => ['domain' => 'relay.test'],
            'mail/relay-recipients' => ['source' => 'r@relay.test'],
            'mail/content-filters' => ['pattern' => '/spam/', 'action' => 'DISCARD'],
            'mail/spamfilter/users' => ['email' => 's@a.test'],
        ];

        foreach ($gated as $route => $payload) {
            $this->postJson("/api/v1/{$route}", $payload, $this->tenantHeaders('clientA'))
                ->assertStatus(403)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->postJson("/api/v1/{$route}", $payload, $this->tenantHeaders('reseller'))
                ->assertStatus(403);
        }

        // Spamfilter config is a server.config blob — admin-only including
        // reads (no row permissions exist for it).
        $this->getJson('/api/v1/mail/spamfilter/config', $this->tenantHeaders('clientA'))->assertStatus(403);
        $this->putJson('/api/v1/mail/spamfilter/config/1', [], $this->tenantHeaders('clientA'))->assertStatus(403);
    }

    public function test_limit_gated_mail_writes_follow_the_client_limits(): void
    {
        // Legacy defaults are 0 = feature not booked (ispconfig3.sql).
        $denied = [
            'mail/transports' => [],
            'mail/access-rules' => [],
            'mail/spamfilter/wblist' => [],
        ];

        $datalogCount = DB::table('sys_datalog')->count();

        foreach ($denied as $route => $payload) {
            $this->postJson("/api/v1/{$route}", $payload, $this->tenantHeaders('clientA'))
                ->assertStatus(403)
                ->assertHeader('Content-Type', 'application/problem+json');
        }

        $this->assertSame($datalogCount, DB::table('sys_datalog')->count());

        // Booked (-1 = unlimited): creates succeed and are stamped with the
        // key's identity.
        $this->setClientLimit('clientA', 'limit_mailrouting', -1);
        $this->setClientLimit('clientA', 'limit_mail_wblist', -1);
        $this->setClientLimit('clientA', 'limit_spamfilter_wblist', -1);

        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1,
            'domain' => 'route.test',
            'transport' => 'smtp:relay.example.com',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->postJson('/api/v1/mail/access-rules', [
            'server_id' => 1,
            'source' => 'blocked@spam.test',
            'access' => 'REJECT',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        // rid must reference an existing spamfilter_users row.
        $spamfilterUser = (int) DB::table('spamfilter_users')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1,
            'email' => '@a-dom.test',
        ]));

        $this->postJson('/api/v1/mail/spamfilter/wblist', [
            'server_id' => 1,
            'rid' => $spamfilterUser,
            'email' => 'friend@else.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->assertDatabaseHas('mail_transport', [
            'domain' => 'route.test',
            'sys_userid' => $this->tenant('clientA')['userid'],
            'sys_groupid' => $this->tenant('clientA')['groupid'],
        ]);

        // Admin keys are never limit-gated.
        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1,
            'domain' => 'admin-route.test',
            'transport' => 'smtp:relay.example.com',
        ], $this->tenantHeaders('admin'))->assertStatus(201);
    }
}
