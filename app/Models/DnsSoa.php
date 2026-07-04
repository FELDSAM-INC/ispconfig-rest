<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * dns_soa — an authoritative DNS zone (contract:
 * api/components/schemas/DnsSoa.yaml; legacy:
 * source_code/interface/web/dns/form/dns_soa.tform.php).
 *
 * The SOA `serial` is server-managed (never mass assignable): generated on
 * create and bumped on every effective update by the controller through
 * DnsSerialService. DNSSEC state fields (dnssec_initialized,
 * dnssec_last_signed, dnssec_info) and rendered_zone are readOnly per the
 * contract and only ever written by ISPConfig's server daemons.
 */
class DnsSoa extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_soa';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Writable fields per the contract. serial and all DNSSEC state fields
     * are server-managed; sys_userid comes from IspContext (BaseModel),
     * sys_groupid may be set explicitly (defaults to the API user's group).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'origin',
        'ns',
        'mbox',
        'refresh',
        'retry',
        'expire',
        'minimum',
        'ttl',
        'active',
        'xfer',
        'also_notify',
        'update_acl',
        'dnssec_wanted',
        'dnssec_algo',
        'sys_groupid',
    ];

    /**
     * dns_soa enums are UPPERCASE 'N'/'Y' (ispconfig3.sql: active
     * enum('N','Y'), dnssec_* ENUM('Y','N')) — hence the :upper cast so
     * datalog payloads carry the exact column case legacy plugins expect.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => YesNoBoolean::class.':upper',
        'dnssec_initialized' => YesNoBoolean::class.':upper',
        'dnssec_wanted' => YesNoBoolean::class.':upper',
        'server_id' => 'integer',
        'serial' => 'integer',
        'refresh' => 'integer',
        'retry' => 'integer',
        'expire' => 'integer',
        'minimum' => 'integer',
        'ttl' => 'integer',
        'dnssec_last_signed' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Raw column defaults (DB-native values, bypassing casts), matching the
     * contract's documented defaults — which equal the dns_soa DDL defaults
     * (refresh 28800, retry 7200, expire 604800, minimum 3600, ttl 3600) —
     * plus the legacy form's active=Y preset and DNSSEC form defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'refresh' => 28800,
        'retry' => 7200,
        'expire' => 604800,
        'minimum' => 3600,
        'ttl' => 3600,
        'active' => 'Y',
        'xfer' => '',
        'also_notify' => '',
        'update_acl' => '',
        'dnssec_wanted' => 'N',
        'dnssec_algo' => 'ECDSAP256SHA256',
    ];

    /**
     * The zone's resource records (dns_rr.zone).
     */
    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class, 'zone', 'id');
    }

    /**
     * Scope: only active zones.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'Y');
    }
}
