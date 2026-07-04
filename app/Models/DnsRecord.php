<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Services\DnsRecordMetaService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * dns_rr — a resource record inside a zone (contract:
 * api/components/schemas/DnsRecord.yaml; legacy:
 * source_code/interface/web/dns/form/dns_*.tform.php + dns_edit_base.php).
 *
 * The stored wire format lives in type/aux/data; DnsRecordMetaService
 * composes those from the request's top-level meta fields and decomposes
 * them into the appended read-only `meta` object. server_id and
 * sys_groupid are always inherited from the parent zone (legacy parity),
 * stamp and the per-record serial are refreshed by the controller on every
 * effective write.
 */
class DnsRecord extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_rr';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Writable columns. type/aux/data are set from the composed storage
     * values; server_id, sys_*, stamp and serial are server-managed.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'zone',
        'name',
        'type',
        'data',
        'aux',
        'ttl',
        'active',
    ];

    /**
     * dns_rr.active is UPPERCASE enum('N','Y') (ispconfig3.sql) — :upper
     * keeps datalog payloads byte-compatible with legacy.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => YesNoBoolean::class.':upper',
        'zone' => 'integer',
        'server_id' => 'integer',
        'aux' => 'integer',
        'ttl' => 'integer',
        'serial' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Raw column defaults, mirroring the legacy tform presets
     * (ttl 3600, active Y) and the dns_rr DDL (aux 0).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'aux' => 0,
        'ttl' => 3600,
        'active' => 'Y',
    ];

    /**
     * Every read response carries the computed `meta` object.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'meta',
    ];

    /**
     * The parent zone (spec 002 gap G04 — foreign/owner keys were swapped).
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsSoa::class, 'zone', 'id');
    }

    /**
     * Computed read-only meta object: type-specific fields decomposed from
     * aux/data, with stored TXT rows re-classified as SPF/DKIM/DMARC.
     * Simple types serialize as an empty JSON object (contract: meta is
     * always an object).
     */
    protected function meta(): Attribute
    {
        return Attribute::get(function (): object|array {
            $meta = app(DnsRecordMetaService::class)->meta($this->getAttributes());

            return $meta === [] ? (object) [] : $meta;
        });
    }

    /**
     * Scope: only active records.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'Y');
    }

    /**
     * Scope: records of one zone.
     */
    public function scopeForZone($query, int $zoneId)
    {
        return $query->where('zone', $zoneId);
    }
}
