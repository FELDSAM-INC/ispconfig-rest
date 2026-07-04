<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * dns_template — a zone-wizard template (contract:
 * api/components/schemas/DnsTemplate.yaml; legacy:
 * source_code/interface/web/dns/form/dns_template.tform.php).
 *
 * The API stores templates only; expanding a template into a zone (legacy
 * dns_wizard.php) has no REST counterpart (spec 002, out of scope).
 */
class DnsTemplate extends BaseModel
{
    /**
     * Placeholder tokens allowed in the `fields` list (legacy
     * dns_template.tform.php $field_values).
     *
     * @var array<int, string>
     */
    public const ALLOWED_FIELDS = ['DOMAIN', 'IP', 'IPV6', 'NS1', 'NS2', 'EMAIL', 'DKIM', 'DNSSEC'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_template';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'template_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'fields',
        'template',
        'visible',
        'sys_groupid',
    ];

    /**
     * dns_template.visible is UPPERCASE enum('N','Y') (ispconfig3.sql).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'visible' => YesNoBoolean::class.':upper',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Raw column defaults (DDL/legacy form preset visible=Y).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visible' => 'Y',
    ];

    /**
     * The contract exposes template_id as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'template_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
