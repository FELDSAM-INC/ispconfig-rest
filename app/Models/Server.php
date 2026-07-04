<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * server — an ISPConfig node and its service-role flags (contract:
 * api/components/schemas/Server.yaml; legacy:
 * source_code/interface/web/admin/form/server.tform.php).
 *
 * Unlike the child tables of this module the server table uses INTEGER 0/1
 * flags (tinyint columns), NOT 'y'/'n' enums — no YesNoBoolean casts here.
 *
 * The `config` column (the INI blob) is intentionally hidden from JSON: it
 * is exposed exclusively through the /servers/{id}/configs* endpoints via
 * ServerConfigService.
 */
class Server extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'server';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'server_id';

    /**
     * Writable fields per the contract. `config` is deliberately NOT mass
     * assignable — config writes go through ServerConfigService, which sets
     * the attribute explicitly. `updated` and `dbversion` are system-managed
     * (readOnly in the schema).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'server_name',
        'mail_server',
        'web_server',
        'dns_server',
        'file_server',
        'db_server',
        'vserver_server',
        'proxy_server',
        'firewall_server',
        'xmpp_server',
        'mirror_server_id',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'mail_server' => 'integer',
        'web_server' => 'integer',
        'dns_server' => 'integer',
        'file_server' => 'integer',
        'db_server' => 'integer',
        'vserver_server' => 'integer',
        'proxy_server' => 'integer',
        'firewall_server' => 'integer',
        'xmpp_server' => 'integer',
        'mirror_server_id' => 'integer',
        'updated' => 'integer',
        'dbversion' => 'integer',
        'active' => 'integer',
    ];

    /**
     * config is managed via the configs endpoints only; server_id is exposed
     * as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'config',
        'server_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * Raw column defaults mirroring the legacy tform (role flags '0',
     * active '1', mirror_server_id 0) and the DB column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'server_name' => '',
        'mail_server' => 0,
        'web_server' => 0,
        'dns_server' => 0,
        'file_server' => 0,
        'db_server' => 0,
        'vserver_server' => 0,
        'proxy_server' => 0,
        'firewall_server' => 0,
        'xmpp_server' => 0,
        'mirror_server_id' => 0,
        'active' => 1,
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * The raw INI configuration blob ('' when the column is NULL on a
     * fresh row).
     */
    public function rawConfig(): string
    {
        return (string) ($this->getRawOriginal('config') ?? '');
    }

    public function ips(): HasMany
    {
        return $this->hasMany(ServerIp::class, 'server_id');
    }

    public function ipMaps(): HasMany
    {
        return $this->hasMany(ServerIpMap::class, 'server_id');
    }

    public function firewall(): HasOne
    {
        return $this->hasOne(ServerFirewall::class, 'server_id');
    }

    public function phpVersions(): HasMany
    {
        return $this->hasMany(ServerPhp::class, 'server_id');
    }
}
