<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * firewall — the (single) port-list firewall record of a server (contract:
 * api/components/schemas/ServerFirewall.yaml; legacy:
 * source_code/interface/web/admin/form/firewall.tform.php).
 *
 * SINGLETON: the legacy form declares server_id UNIQUE — a server has AT
 * MOST ONE firewall record. The API models the resource as a singleton at
 * /servers/{id}/firewall (PUT upserts: 201 create / 200 update).
 */
class ServerFirewall extends BaseModel
{
    /**
     * The legacy default port lists (firewall.tform.php field defaults).
     */
    public const DEFAULT_TCP_PORTS = '21,22,25,53,80,110,143,443,465,587,993,995,3306,4190,8080,8081,40110:40210';

    public const DEFAULT_UDP_PORTS = '53';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'firewall';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'firewall_id';

    /**
     * server_id always comes from the URL path (controller forceFill) and
     * is immutable (legacy firewall_edit.php::onBeforeUpdate).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tcp_port',
        'udp_port',
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
     * firewall_id is exposed as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'firewall_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * Raw column defaults mirroring the legacy tform (port lists, active 'y').
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tcp_port' => self::DEFAULT_TCP_PORTS,
        'udp_port' => self::DEFAULT_UDP_PORTS,
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
