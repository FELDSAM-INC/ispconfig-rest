<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * API-owned credential table (NOT an ISPConfig table — it is exempt from the
 * BaseModel/datalog rule per the constitution's Code Boundaries: API-owned
 * tables are clearly separate and managed by our own migrations).
 */
class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $fillable = [
        'name',
        'key_hash',
        'sys_userid',
        'sys_groupid',
        'active',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Mint a new key: returns [model, plaintext]. The plaintext is shown once
     * and only its SHA-256 hash is stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function mint(string $name, int $sysUserid = 1, int $sysGroupid = 1): array
    {
        $plaintext = 'isp_'.Str::random(40);

        $key = self::create([
            'name' => $name,
            'key_hash' => hash('sha256', $plaintext),
            'sys_userid' => $sysUserid,
            'sys_groupid' => $sysGroupid,
            'active' => true,
        ]);

        return [$key, $plaintext];
    }

    /**
     * Deactivate every active key bound to a client's identity — one of its
     * control-panel users or its group (spec 014 FR-010). The admin user and
     * group 1 are never matched.
     *
     * @param  array<int, int>  $userIds
     */
    public static function deactivateForClientIdentities(array $userIds, int $groupId): int
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds), fn (int $id): bool => $id > 1));
        $matchGroup = $groupId > 1;

        if ($userIds === [] && ! $matchGroup) {
            return 0;
        }

        return self::query()
            ->where('active', true)
            ->where(function ($query) use ($userIds, $groupId, $matchGroup): void {
                if ($userIds !== []) {
                    $query->whereIn('sys_userid', $userIds);
                }

                if ($matchGroup) {
                    $query->orWhere('sys_groupid', $groupId);
                }
            })
            ->update(['active' => false]);
    }
}
