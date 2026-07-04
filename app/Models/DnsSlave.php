<?php

namespace App\Models;

use App\Casts\YesNoBoolean;

/**
 * dns_slave — a secondary zone transferred from an external master
 * (contract: api/components/schemas/DnsSlave.yaml; legacy:
 * source_code/interface/web/dns/form/dns_slave.tform.php).
 *
 * The (origin, server_id) combination is UNIQUE (ispconfig3.sql key
 * `slave`); the API surfaces violations as 409 problems (contract).
 */
class DnsSlave extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_slave';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'origin',
        'ns',
        'active',
        'xfer',
        'sys_groupid',
    ];

    /**
     * dns_slave.active is UPPERCASE enum('N','Y') (ispconfig3.sql).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => YesNoBoolean::class.':upper',
        'server_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Raw column defaults (legacy form preset active=Y).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => 'Y',
        'xfer' => '',
    ];

    /**
     * Scope: only active slave zones.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'Y');
    }
}
