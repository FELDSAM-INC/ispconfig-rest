<?php

namespace App\Models;

use App\Casts\YesNoBoolean;

/**
 * dns_ssl_ca — DNS Certification Authority (CAA policy record) consumed by
 * ISPConfig interface plugins to auto-create CAA dns_rr records (contract:
 * api/components/schemas/DnsCaConfig.yaml; DDL:
 * source_code/install/sql/ispconfig3.sql).
 *
 * Datalog note (documented Principle II superset, per the contract and
 * specs/008-system-module): legacy ISPConfig maintains this table with
 * direct SQL and NO sys_datalog journaling — its 3.2 INSERT is even
 * syntactically broken. The table is interface-only (no server daemon
 * consumes it), so routing writes through BaseModel's datalog is a harmless
 * superset of legacy behavior that the API adopts deliberately for
 * auditability.
 *
 * Flag storage: `active`/`ca_wildcard` are UPPERCASE enum('N','Y') columns,
 * `ca_critical` is tinyint(1) — the API speaks booleans for all three.
 */
class DnsSslCa extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_ssl_ca';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Writable fields per the contract. ca_issue uniqueness (DB UNIQUE KEY)
     * is enforced as a 409 in the controller.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ca_name',
        'ca_issue',
        'ca_wildcard',
        'ca_iodef',
        'ca_critical',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'active' => YesNoBoolean::class.':upper',
        'ca_wildcard' => YesNoBoolean::class.':upper',
        'ca_critical' => 'boolean',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * The contract exposes only the CAA policy fields — no sys_* columns.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
    ];

    /**
     * Raw column defaults per the DDL (active 'N', ca_wildcard 'N',
     * ca_iodef '', ca_critical 0). $attributes bypasses the casts.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => 'N',
        'ca_wildcard' => 'N',
        'ca_iodef' => '',
        'ca_critical' => 0,
    ];
}
