<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * shell_user — an SSH/shell account bound to a web domain (contract:
 * api/components/schemas/ShellUser.yaml; legacy:
 * source_code/interface/web/sites/form/shell_user.tform.php +
 * shell_user_edit.php).
 *
 * Username storage follows the FtpUser convention: full prefixed name in
 * `username`, prefix in `username_prefix`; the API exposes the un-prefixed
 * name plus username_prefix/username_full.
 */
class ShellUser extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * Legacy shell username blacklist
     * (source_code/interface/lib/shelluser_blacklist).
     *
     * @var array<int, string>
     */
    public const USERNAME_BLACKLIST = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail',
        'news', 'uucp', 'proxy', 'www-data', 'wwwrun', 'apache', 'backup',
        'list', 'irc', 'gnats', 'nobody', 'debian-exim', 'statd', 'identd',
        'sshd', 'mysql', 'postgres', 'postfix', 'clamav', 'amavis', 'vmail',
        'getmail', 'ispconfig', 'courier', 'dovecot', 'mongodb',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'shell_user';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'shell_user_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_domain_id',
        'username',
        'password',
        'ssh_rsa',
        'chroot',
        'shell',
        'quota_size',
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
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'shell_user_id',
        'password',
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
        'shell' => '/bin/bash',
        'chroot' => 'no',
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

    /**
     * Legacy functions.inc.php::is_allowed_user — hard blacklist plus a
     * strict character/length whitelist.
     */
    public static function isAllowedUser(string $username): bool
    {
        if (in_array($username, ['root', 'ispconfig', 'vmail', 'getmail'], true)) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9\.\-_]{1,32}$/', $username) === 1;
    }
}
