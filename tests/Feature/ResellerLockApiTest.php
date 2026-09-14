<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;
use Tests\Support\LockRecordFixtures;
use Tests\Support\SitesSchema;

/**
 * Spec 019 US3 — reseller lock/cancel through /resellers/{id}
 * (legacy reseller_edit.php inline lock: own group only, lock owner = acting
 * user, unlock owner = the reseller's control-panel user).
 */
class ResellerLockApiTest extends ClientApiTestCase
{
    use LockRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
    }

    /**
     * @return array{reseller: int, resellerUser: int, client: int, clientUser: int, ownSite: int, clientSite: int}
     */
    protected function seedResellerWithClient(): array
    {
        $reseller = $this->seedClient(['username' => 'rick', 'email' => 'rick@hosting.tld', 'limit_client' => 10]);
        ['groupId' => $resellerGroup, 'userId' => $resellerUser] = $this->seedClientLogin($reseller, 'rick');

        $client = $this->seedClient(['username' => 'cust', 'email' => 'cust@hosting.tld', 'parent_client_id' => $reseller]);
        ['groupId' => $clientGroup, 'userId' => $clientUser] = $this->seedClientLogin($client, 'cust');

        return [
            'reseller' => $reseller,
            'resellerUser' => $resellerUser,
            'client' => $client,
            'clientUser' => $clientUser,
            'ownSite' => $this->seedLockRecord('web_domain', 'domain_id', $resellerGroup, $this->siteAttrs('rick.test', 'y'), $resellerUser),
            'clientSite' => $this->seedLockRecord('web_domain', 'domain_id', $clientGroup, $this->siteAttrs('cust.test', 'y'), $clientUser),
        ];
    }

    protected function site(int $id, string $column): string
    {
        return (string) DB::table('web_domain')->where('domain_id', $id)->value($column);
    }

    public function test_reseller_lock_affects_only_own_records_with_legacy_owner_attribution(): void
    {
        $seed = $this->seedResellerWithClient();

        $this->putJson('/api/v1/resellers/'.$seed['reseller'], ['locked' => true], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('locked', true);

        // Lock writes the acting key's user (dev admin key = 1).
        $this->assertSame('n', $this->site($seed['ownSite'], 'active'));
        $this->assertSame('1', $this->site($seed['ownSite'], 'sys_userid'));
        $this->assertSame('y', $this->site($seed['clientSite'], 'active'));
        $this->assertSame((string) $seed['clientUser'], $this->site($seed['clientSite'], 'sys_userid'));

        $rows = DB::table('sys_datalog')->where('dbtable', 'web_domain')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('domain_id:'.$seed['ownSite'], $rows[0]->dbidx);
        $this->assertSame('1', unserialize($rows[0]->data)['new']['sys_userid']);

        // Unlock writes the reseller's control-panel user.
        $this->putJson('/api/v1/resellers/'.$seed['reseller'], ['locked' => false], $this->authHeaders())->assertOk();

        $this->assertSame('y', $this->site($seed['ownSite'], 'active'));
        $this->assertSame((string) $seed['resellerUser'], $this->site($seed['ownSite'], 'sys_userid'));
        $this->assertSame('y', $this->site($seed['clientSite'], 'active'));
    }

    public function test_reseller_cancel_changes_only_the_reseller_login(): void
    {
        $seed = $this->seedResellerWithClient();

        $this->putJson('/api/v1/resellers/'.$seed['reseller'], ['canceled' => true], $this->authHeaders())->assertOk();

        $this->assertSame(0, (int) DB::table('sys_user')->where('userid', $seed['resellerUser'])->value('active'));
        $this->assertSame(1, (int) DB::table('sys_user')->where('userid', $seed['clientUser'])->value('active'));
    }

    public function test_create_reseller_with_canceled_creates_an_inactive_login(): void
    {
        $id = $this->postJson('/api/v1/resellers', [
            'company_name' => 'Hosting Ltd',
            'contact_name' => 'Rick Seller',
            'email' => 'rick@hosting.tld',
            'username' => 'rickseller',
            'password' => 'res3ller-Pass1',
            'limit_client' => 10,
            'canceled' => true,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->json('id');

        $this->assertSame(0, (int) DB::table('sys_user')->where('client_id', $id)->value('active'));
    }
}
