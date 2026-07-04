<?php

namespace Tests\Support;

use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;

/**
 * Four-identity tenant fixture for the spec 011 authorization matrices:
 *
 *  - admin:    userid 1 / groupid 1 (typ 'admin'), a real minted key —
 *              byte-identical behavior to the dev key;
 *  - clientA:  its own sys_user/sys_group/client triple, groups CSV = own
 *              group only; owned by reseller (parent_client_id = R);
 *  - clientB:  independent tenant, admin-owned;
 *  - reseller: groups CSV = "groupR,groupA" (legacy add_group_to_user
 *              semantics — sees clientA's rows), client.limit_client = -1.
 *
 * Call seedTenants() after TenantSchema::create() (and after the module
 * schema). Keys are real hashed ApiKeys minted via ApiKey::mint — the
 * middleware resolves their AuthScope from these rows.
 */
trait TenantFixtures
{
    /**
     * @var array<string, array{userid: int, groupid: int, client_id: int, key: string}>
     */
    protected array $tenants = [];

    protected function seedTenants(): void
    {
        // Admin login (userid 1) — module test setups may have seeded it.
        if (DB::table('sys_user')->where('userid', 1)->doesntExist()) {
            DB::table('sys_user')->insert([
                'userid' => 1,
                'username' => 'apiadmin',
                'typ' => 'admin',
                'default_group' => 1,
            ]);
        }

        if (DB::table('sys_group')->where('groupid', 1)->doesntExist()) {
            DB::table('sys_group')->insert(['groupid' => 1, 'name' => 'admin', 'client_id' => 0]);
        }

        $clientR = (int) DB::table('client')->insertGetId([
            'username' => 'resellerr',
            'contact_name' => 'Reseller R',
            'limit_client' => -1,
            'parent_client_id' => 0,
        ], 'client_id');

        $clientA = (int) DB::table('client')->insertGetId([
            'username' => 'clienta',
            'contact_name' => 'Client A',
            'limit_client' => 0,
            'parent_client_id' => $clientR,
        ], 'client_id');

        $clientB = (int) DB::table('client')->insertGetId([
            'username' => 'clientb',
            'contact_name' => 'Client B',
            'limit_client' => 0,
            'parent_client_id' => 0,
        ], 'client_id');

        $groupR = (int) DB::table('sys_group')->insertGetId(['name' => 'resellerr', 'client_id' => $clientR], 'groupid');
        $groupA = (int) DB::table('sys_group')->insertGetId(['name' => 'clienta', 'client_id' => $clientA], 'groupid');
        $groupB = (int) DB::table('sys_group')->insertGetId(['name' => 'clientb', 'client_id' => $clientB], 'groupid');

        $userR = (int) DB::table('sys_user')->insertGetId([
            'username' => 'resellerr',
            'typ' => 'user',
            'groups' => $groupR.','.$groupA,
            'default_group' => $groupR,
            'client_id' => $clientR,
        ], 'userid');

        $userA = (int) DB::table('sys_user')->insertGetId([
            'username' => 'clienta',
            'typ' => 'user',
            'groups' => (string) $groupA,
            'default_group' => $groupA,
            'client_id' => $clientA,
        ], 'userid');

        $userB = (int) DB::table('sys_user')->insertGetId([
            'username' => 'clientb',
            'typ' => 'user',
            'groups' => (string) $groupB,
            'default_group' => $groupB,
            'client_id' => $clientB,
        ], 'userid');

        // Ownership stamps on the client rows themselves: the reseller owns
        // clientA's record (legacy stamps the creating reseller); admin owns
        // clientB and the reseller.
        DB::table('client')->where('client_id', $clientA)->update($this->stamp($userR, $groupR));
        DB::table('client')->where('client_id', $clientB)->update($this->stamp(1, 1));
        DB::table('client')->where('client_id', $clientR)->update($this->stamp(1, 1));

        [, $adminKey] = ApiKey::mint('admin key', 1, 1);
        [, $keyR] = ApiKey::mint('reseller key', $userR, $groupR);
        [, $keyA] = ApiKey::mint('client A key', $userA, $groupA);
        [, $keyB] = ApiKey::mint('client B key', $userB, $groupB);

        $this->tenants = [
            'admin' => ['userid' => 1, 'groupid' => 1, 'client_id' => 0, 'key' => $adminKey],
            'reseller' => ['userid' => $userR, 'groupid' => $groupR, 'client_id' => $clientR, 'key' => $keyR],
            'clientA' => ['userid' => $userA, 'groupid' => $groupA, 'client_id' => $clientA, 'key' => $keyA],
            'clientB' => ['userid' => $userB, 'groupid' => $groupB, 'client_id' => $clientB, 'key' => $keyB],
        ];
    }

    /**
     * @return array{userid: int, groupid: int, client_id: int, key: string}
     */
    protected function tenant(string $name): array
    {
        return $this->tenants[$name];
    }

    /**
     * @return array<string, string>
     */
    protected function tenantHeaders(string $name): array
    {
        return ['X-API-Key' => $this->tenants[$name]['key']];
    }

    /**
     * Sys-field stamp for seeding a row owned by the given identity
     * (legacy auth_preset riud/riud/'').
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    protected function ownedBy(string $name, array $attrs = []): array
    {
        $tenant = $this->tenants[$name];

        return array_merge($this->stamp($tenant['userid'], $tenant['groupid']), $attrs);
    }

    protected function setClientLimit(string $name, string $column, int $value): void
    {
        DB::table('client')
            ->where('client_id', $this->tenants[$name]['client_id'])
            ->update([$column => $value]);
    }

    /**
     * Seed N limit-consuming rows on $table owned by $owner (stamped with the
     * tenant's sys identity), so the next create sees exactly N existing rows.
     * $attrs receives the 0-based index and returns that row's column values
     * (keep unique columns distinct across rows).
     *
     * @param  callable(int): array<string, mixed>  $attrs
     * @return array<int, int> the inserted primary-key ids
     */
    protected function seedOwnedRows(string $owner, string $table, int $count, callable $attrs, string $pk = 'id'): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int) DB::table($table)->insertGetId($this->ownedBy($owner, $attrs($i)), $pk);
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function stamp(int $userid, int $groupid): array
    {
        return [
            'sys_userid' => $userid,
            'sys_groupid' => $groupid,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
        ];
    }
}
