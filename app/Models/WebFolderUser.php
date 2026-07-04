<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * web_folder_user — an HTTP-auth credential for a protected web folder.
 * Primary key `web_folder_user_id` (contract:
 * api/components/schemas/WebFolderUser.yaml; legacy:
 * source_code/interface/web/sites/form/web_folder_user.tform.php +
 * web_folder_user_edit.php).
 */
class WebFolderUser extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'web_folder_user';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'web_folder_user_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'web_folder_id',
        'username',
        'password',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'web_folder_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'active' => YesNoBoolean::class,
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'web_folder_user_id',
        'password',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'server_name',
        'web_folder_path',
        'parent_domain',
    ];

    /**
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

    protected function serverName(): Attribute
    {
        return Attribute::get(fn () => $this->lookupServerName((int) ($this->getAttributes()['server_id'] ?? 0)));
    }

    protected function webFolderPath(): Attribute
    {
        return Attribute::get(function () {
            $folder = $this->lookupWebFolder((int) ($this->getAttributes()['web_folder_id'] ?? 0));

            return $folder?->path;
        });
    }

    protected function parentDomain(): Attribute
    {
        return Attribute::get(function () {
            $folder = $this->lookupWebFolder((int) ($this->getAttributes()['web_folder_id'] ?? 0));

            return $folder === null ? null : $this->lookupDomainName((int) $folder->parent_domain_id);
        });
    }
}
