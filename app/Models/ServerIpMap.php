<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * server_ip_map — NAT source -> destination IPv4 rewrite rule (contract:
 * api/components/schemas/ServerIpMap.yaml; legacy:
 * source_code/interface/web/admin/form/server_ip_map.tform.php).
 */
class ServerIpMap extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'server_ip_map';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'server_ip_map_id';

    /**
     * server_id always comes from the URL path (controller forceFill) and
     * cannot be changed afterwards.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'source_ip',
        'destination_ip',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'server_id' => 'integer',
        'active' => YesNoBoolean::class,
    ];

    /**
     * server_ip_map_id is exposed as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'server_ip_map_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * Raw column defaults mirroring the legacy tform (active 'y').
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => 'y',
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
