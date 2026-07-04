<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * server_ip — an IP address registered on a server (contract:
 * api/components/schemas/ServerIp.yaml; legacy:
 * source_code/interface/web/admin/form/server_ip.tform.php).
 *
 * Note: the table has NO `active` column (verified against
 * source_code/install/sql/ispconfig3.sql).
 */
class ServerIp extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'server_ip';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'server_ip_id';

    /**
     * server_id is not fillable: it always comes from the URL path and is
     * immutable on update (legacy server_ip_edit.php::onBeforeUpdate) — the
     * controller sets it via forceFill on create.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'ip_type',
        'ip_address',
        'virtualhost',
        'virtualhost_port',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'server_id' => 'integer',
        'client_id' => 'integer',
        'virtualhost' => YesNoBoolean::class,
    ];

    /**
     * server_ip_id is exposed as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'server_ip_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * Raw column defaults mirroring the legacy tform (ip_type IPv4,
     * virtualhost 'y', virtualhost_port '80,443', client_id 0).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_id' => 0,
        'ip_type' => 'IPv4',
        'virtualhost' => 'y',
        'virtualhost_port' => '80,443',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
