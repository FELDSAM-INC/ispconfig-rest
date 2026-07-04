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
}
