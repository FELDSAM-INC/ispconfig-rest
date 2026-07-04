<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * domain — a pre-registered domain of the domain module (contract:
 * api/components/schemas/ClientDomain.yaml; legacy:
 * source_code/interface/web/client/form/domain.tform.php, domain_edit.php).
 *
 * The table has no client_id column: ownership is expressed through
 * sys_groupid (the owning client's sys_group) with sys_perm_group 'ru' —
 * the API resolves the write-only client_id request field accordingly
 * (mirrors legacy domain_edit.php::onAfterInsert).
 */
class ClientDomain extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'domain';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'domain_id';

    /**
     * Only the domain name is writable; ownership fields are resolved
     * server-side from client_id.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'domain',
    ];

    /**
     * domain_id is exposed as `id`.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'domain_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * The system group that owns the domain.
     */
    public function sysGroup(): BelongsTo
    {
        return $this->belongsTo(SysGroup::class, 'sys_groupid', 'groupid');
    }
}
