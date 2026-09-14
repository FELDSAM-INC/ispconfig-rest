<?php

namespace App\Services;

use App\Models\ApiKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * API key lifecycle shared by the HTTP endpoints (spec 014) and the CLI
 * commands: client identity resolution (spec 011 FR-019), minting, and the
 * read-side presentation of keys.
 *
 * api_keys is an API-owned table (constitution Code Boundaries), so nothing
 * here is journaled to sys_datalog. The plaintext key is returned only by
 * mint(); present() never includes the plaintext or the stored hash.
 */
class ApiKeyService
{
    /**
     * The client's control-panel group (sys_group.groupid by client_id), or
     * null when the client has no group.
     */
    public function resolveClientGroupId(int $clientId): ?int
    {
        if ($clientId < 1) {
            return null;
        }

        $groupId = DB::table('sys_group')->where('client_id', $clientId)->value('groupid');

        return $groupId === null ? null : (int) $groupId;
    }

    /**
     * The control-panel user of a client group (sys_user.userid by
     * default_group), or null when the group has no user.
     */
    public function resolveGroupUserId(int $groupId): ?int
    {
        $userId = DB::table('sys_user')->where('default_group', $groupId)->value('userid');

        return $userId === null ? null : (int) $userId;
    }

    /**
     * Resolve a client's control-panel identity — the pair ISPConfig created
     * for the client (spec 011 FR-019).
     *
     * @return array{0: int, 1: int}|null [sys_userid, sys_groupid]
     */
    public function resolveClientIdentity(int $clientId): ?array
    {
        $groupId = $this->resolveClientGroupId($clientId);

        if ($groupId === null) {
            return null;
        }

        $userId = $this->resolveGroupUserId($groupId);

        return $userId === null ? null : [$userId, $groupId];
    }

    /**
     * Mint a key bound to a client's identity, or to the ISPConfig admin
     * (1/1) without a client. Returns [model, plaintext].
     *
     * @return array{0: ApiKey, 1: string}
     */
    public function mint(string $name, ?int $clientId = null): array
    {
        [$sysUserId, $sysGroupId] = [1, 1];

        if ($clientId !== null) {
            $identity = $this->resolveClientIdentity($clientId);

            if ($identity === null) {
                throw new InvalidArgumentException("Client {$clientId} has no control-panel identity.");
            }

            [$sysUserId, $sysGroupId] = $identity;
        }

        return DB::transaction(fn (): array => ApiKey::mint($name, $sysUserId, $sysGroupId));
    }

    /**
     * Public representation of keys (ApiKey schema). scope and client_id are
     * derived for the whole set with one batched sys_user read and one
     * batched client read, mirroring ApiKeyAuth::resolveScope and
     * AuthScope::isReseller:
     *
     *  - sys_userid 1 or sys_user.typ = 'admin' → admin (client_id null);
     *  - no sys_user row → unbound (client_id null, the key fails closed);
     *  - client.limit_client != 0 → reseller; otherwise client.
     *
     * @param  iterable<ApiKey>  $keys
     * @return array<int, array<string, mixed>>
     */
    public function present(iterable $keys): array
    {
        $keys = Collection::make($keys)->values();

        $userIds = $keys->map(fn (ApiKey $key): int => (int) $key->sys_userid)
            ->reject(fn (int $id): bool => $id === 1)
            ->unique()
            ->values()
            ->all();

        $users = $userIds === []
            ? Collection::make()
            : DB::table('sys_user')->whereIn('userid', $userIds)->get(['userid', 'typ', 'client_id'])->keyBy('userid');

        $clientIds = $users->map(fn ($user): int => (int) $user->client_id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $limits = $clientIds === [] || ! Schema::hasTable('client')
            ? Collection::make()
            : DB::table('client')->whereIn('client_id', $clientIds)->pluck('limit_client', 'client_id');

        return $keys->map(function (ApiKey $key) use ($users, $limits): array {
            [$scope, $clientId] = $this->scopeOf($key, $users, $limits);

            return [
                'id' => (int) $key->id,
                'name' => $key->name,
                'scope' => $scope,
                'client_id' => $clientId,
                'active' => (bool) $key->active,
                'created_at' => $key->created_at?->toJSON(),
                'last_used_at' => $key->last_used_at?->toJSON(),
            ];
        })->all();
    }

    /**
     * @param  Collection<int|string, object>  $users  sys_user rows keyed by userid
     * @param  Collection<int|string, mixed>  $limits  client.limit_client keyed by client_id
     * @return array{0: string, 1: int|null}
     */
    protected function scopeOf(ApiKey $key, Collection $users, Collection $limits): array
    {
        $userId = (int) $key->sys_userid;

        if ($userId === 1) {
            return ['admin', null];
        }

        $user = $users->get($userId);

        if ($user === null) {
            return ['unbound', null];
        }

        if ($user->typ === 'admin') {
            return ['admin', null];
        }

        $clientId = (int) $user->client_id;

        if ($clientId < 1) {
            return ['client', null];
        }

        $limitClient = $limits->get($clientId);

        return [$limitClient !== null && (int) $limitClient !== 0 ? 'reseller' : 'client', $clientId];
    }
}
