<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * web_folder — an HTTP-auth protected directory of a website. Primary key
 * `web_folder_id` (contract: api/components/schemas/WebFolder.yaml;
 * legacy: source_code/interface/web/sites/form/web_folder.tform.php +
 * web_folder_edit.php).
 */
class WebFolder extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'web_folder';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'web_folder_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_domain_id',
        'path',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'parent_domain_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'active' => YesNoBoolean::class,
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'web_folder_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'server_name',
        'parent_domain',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'path' => '/',
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

    protected function parentDomain(): Attribute
    {
        return Attribute::get(fn () => $this->lookupDomainName((int) ($this->getAttributes()['parent_domain_id'] ?? 0)));
    }
}
