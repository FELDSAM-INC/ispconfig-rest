<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Support\AuthScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * AuthScope resolution + predicate semantics (spec 011 FR-001…FR-006):
 * legacy getAuthSQL triplet with letter containment, groups-CSV expansion,
 * admin bypass (userid 1 / typ='admin'), isReseller truth table, and the
 * FR-005 decision (missing sys_user row => 401 fail-closed).
 */
class AuthScopeTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailSchema::create();
        TenantSchema::create();
        $this->seedTenants();
    }

    protected function seedDomain(array $attrs): int
    {
        return (int) DB::table('mail_domain')->insertGetId(array_merge([
            'server_id' => 1,
            'domain' => 'x.test',
            'active' => 'y',
        ], $attrs), 'domain_id');
    }

    // ------------------------------------------------------------------
    // Read predicate (getAuthSQL parity)
    // ------------------------------------------------------------------

    public function test_client_scope_sees_owned_group_and_world_readable_rows_only(): void
    {
        $own = $this->seedDomain($this->ownedBy('clientA', ['domain' => 'own.test']));
        // Group-readable: owned by another user but stamped with A's group.
        $group = $this->seedDomain([
            'domain' => 'group.test',
            'sys_userid' => 999,
            'sys_groupid' => $this->tenant('clientA')['groupid'],
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'r',
            'sys_perm_other' => '',
        ]);
        $world = $this->seedDomain([
            'domain' => 'world.test',
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => 'r',
        ]);
        $foreign = $this->seedDomain($this->ownedBy('clientB', ['domain' => 'foreign.test']));

        $response = $this->getJson('/api/v1/mail/domains', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$own, $group, $world], $ids);
        $this->assertNotContains($foreign, $ids);
    }

    public function test_owner_without_r_letter_cannot_read_own_row(): void
    {
        // Letter containment: sys_perm_user 'ud' has no 'r' (group/other
        // cleared so only the user clause could ever match).
        $this->seedDomain($this->ownedBy('clientA', [
            'domain' => 'unreadable.test',
            'sys_perm_user' => 'ud',
            'sys_perm_group' => '',
            'sys_perm_other' => '',
        ]));

        $this->getJson('/api/v1/mail/domains', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_reseller_groups_csv_expansion_grants_client_rows(): void
    {
        $aRow = $this->seedDomain($this->ownedBy('clientA', ['domain' => 'a.test']));
        $bRow = $this->seedDomain($this->ownedBy('clientB', ['domain' => 'b.test']));

        $response = $this->getJson('/api/v1/mail/domains', $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($aRow, $ids);
        $this->assertNotContains($bRow, $ids);
    }

    // ------------------------------------------------------------------
    // Admin bypass (FR-002/FR-004)
    // ------------------------------------------------------------------

    public function test_userid_one_key_is_admin_and_sees_everything(): void
    {
        $this->seedDomain($this->ownedBy('clientA', ['domain' => 'a.test']));
        $this->seedDomain($this->ownedBy('clientB', ['domain' => 'b.test']));

        $this->getJson('/api/v1/mail/domains', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_typ_admin_user_other_than_userid_one_bypasses_scoping(): void
    {
        $adminUser = (int) DB::table('sys_user')->insertGetId([
            'username' => 'secondadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ], 'userid');
        [, $key] = ApiKey::mint('second admin', $adminUser, 1);

        $this->seedDomain($this->ownedBy('clientA', ['domain' => 'a.test']));
        $this->seedDomain($this->ownedBy('clientB', ['domain' => 'b.test']));

        $this->getJson('/api/v1/mail/domains', ['X-API-Key' => $key])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    // ------------------------------------------------------------------
    // FR-005: missing sys_user row => 401 fail-closed
    // ------------------------------------------------------------------

    public function test_key_bound_to_missing_sys_user_row_is_rejected_with_401(): void
    {
        [, $key] = ApiKey::mint('orphaned key', 424242, 424242);

        $this->getJson('/api/v1/mail/domains', ['X-API-Key' => $key])
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => 'The provided API key is invalid or has been revoked.',
            ]);
    }

    // ------------------------------------------------------------------
    // allows() — in-memory checkPerm parity
    // ------------------------------------------------------------------

    public function test_allows_uses_letter_containment_semantics(): void
    {
        $scope = new AuthScope(5, 7, [7], false);

        $ownRow = ['sys_userid' => 5, 'sys_groupid' => 99, 'sys_perm_user' => 'ru', 'sys_perm_group' => '', 'sys_perm_other' => ''];
        $this->assertTrue($scope->allows($ownRow, 'r'));
        $this->assertTrue($scope->allows($ownRow, 'u'));
        $this->assertFalse($scope->allows($ownRow, 'd'));

        $groupRow = ['sys_userid' => 99, 'sys_groupid' => 7, 'sys_perm_user' => 'riud', 'sys_perm_group' => 'r', 'sys_perm_other' => ''];
        $this->assertTrue($scope->allows($groupRow, 'r'));
        $this->assertFalse($scope->allows($groupRow, 'u'));

        $worldRow = ['sys_userid' => 99, 'sys_groupid' => 99, 'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud', 'sys_perm_other' => 'r'];
        $this->assertTrue($scope->allows($worldRow, 'r'));
        $this->assertFalse($scope->allows($worldRow, 'u'));
        $this->assertFalse($scope->allows($worldRow, 'd'));

        $this->assertTrue(AuthScope::admin()->allows($worldRow, 'd'));
    }

    // ------------------------------------------------------------------
    // isReseller (FR-003)
    // ------------------------------------------------------------------

    public function test_is_reseller_truth_table(): void
    {
        // limit_client = -1 (unlimited resellers) and > 0 are resellers.
        $reseller = new AuthScope(
            $this->tenant('reseller')['userid'],
            $this->tenant('reseller')['groupid'],
            [$this->tenant('reseller')['groupid']],
            false,
            $this->tenant('reseller')['client_id'],
        );
        $this->assertTrue($reseller->isReseller());

        $this->setClientLimit('clientA', 'limit_client', 5);
        $clientA = new AuthScope(
            $this->tenant('clientA')['userid'],
            $this->tenant('clientA')['groupid'],
            [$this->tenant('clientA')['groupid']],
            false,
            $this->tenant('clientA')['client_id'],
        );
        $this->assertTrue($clientA->isReseller());

        // limit_client = 0: plain client.
        $clientB = new AuthScope(
            $this->tenant('clientB')['userid'],
            $this->tenant('clientB')['groupid'],
            [$this->tenant('clientB')['groupid']],
            false,
            $this->tenant('clientB')['client_id'],
        );
        $this->assertFalse($clientB->isReseller());

        // No client row at all: never a reseller.
        $noClient = new AuthScope(77, 88, [88], false, 0);
        $this->assertFalse($noClient->isReseller());

        // Admin scopes short-circuit without touching the client table.
        $this->assertFalse(AuthScope::admin()->isReseller());
    }
}
