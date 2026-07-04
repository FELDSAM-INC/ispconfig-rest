<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * ftp_user — an FTP account bound to a web domain (contract:
 * api/components/schemas/FtpUser.yaml; legacy:
 * source_code/interface/web/sites/form/ftp_user.tform.php +
 * ftp_user_edit.php).
 *
 * The DB stores the FULL (prefixed) name in `username` and the applied
 * prefix in `username_prefix`; the contract exposes the un-prefixed name
 * as `username` plus the read-only `username_prefix`/`username_full`.
 */
class FtpUser extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ftp_user';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'ftp_user_id';

    /**
     * Controllers/services assign the derived fields (server_id, dir,
     * uid, gid, sys_groupid, username_prefix) explicitly.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_domain_id',
        'username',
        'password',
        'quota_size',
        'expires',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'parent_domain_id' => 'integer',
        'quota_size' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'active' => YesNoBoolean::class,
        'expires' => 'datetime',
    ];

    /**
     * writeOnly password + DB columns the contract does not expose.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'ftp_user_id',
        'password',
        'quota_files', 'ul_ratio', 'dl_ratio', 'ul_bandwidth', 'dl_bandwidth',
        'user_type', 'user_config',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'username_full',
        'server_name',
        'parent_domain',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quota_size' => -1,
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

    /**
     * Contract `username` = the stored name without its prefix (legacy
     * tools_sites::removePrefix with the stored username_prefix).
     */
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

    /**
     * Contract `username_full` = the stored (prefixed) name.
     */
    protected function usernameFull(): Attribute
    {
        return Attribute::get(fn () => $this->getAttributes()['username'] ?? null);
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
