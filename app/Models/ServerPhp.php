<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * server_php — an additional PHP version registered on a web server
 * (contract: api/components/schemas/ServerPhp.yaml; legacy:
 * source_code/interface/web/admin/form/server_php.tform.php).
 */
class ServerPhp extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'server_php';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'server_php_id';

    /**
     * server_id always comes from the URL path (controller forceFill) and
     * cannot be changed afterwards.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'name',
        'php_fastcgi_binary',
        'php_fastcgi_ini_dir',
        'php_fpm_init_script',
        'php_fpm_ini_dir',
        'php_fpm_pool_dir',
        'php_fpm_socket_dir',
        'php_cli_binary',
        'php_jk_section',
        'active',
        'sortprio',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'server_id' => 'integer',
        'client_id' => 'integer',
        'sortprio' => 'integer',
        'active' => YesNoBoolean::class,
    ];

    /**
     * server_php_id is exposed as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'server_php_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * Raw column defaults mirroring the legacy tform (active 'y',
     * sortprio 100, client_id 0).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_id' => 0,
        'active' => 'y',
        'sortprio' => 100,
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
