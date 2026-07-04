<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * webdav_user — a WebDAV account for a website directory. Primary key
 * `webdav_user_id` (contract: api/components/schemas/WebdavUser.yaml;
 * legacy: source_code/interface/web/sites/form/webdav_user.tform.php +
 * webdav_user_edit.php).
 *
 * The DB stores the FULL (prefixed) name in `username` and the applied
 * prefix in `username_prefix`; the stored password is the Apache
 * digest-auth hash md5(username:dir:password).
 */
class WebdavUser extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webdav_user';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'webdav_user_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_domain_id',
        'username',
        'password',
        'dir',
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
        'webdav_user_id',
        'password',
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
        'active' => 'y',
        'username_prefix' => '',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    protected function username(): Attribute
    {
        return Attribute::get(function () {
            $raw = (string) ($this->getAttributes()['username'] ?? '');
            $prefix = (string) ($this->getAttributes()['username_prefix'] ?? '');

            if ($prefix !== '' && str_starts_with($raw, $prefix)) {
                return substr($raw, strlen($prefix));
            }

            return $raw;
        });
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
