<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * web_database — a website database (contract:
 * api/components/schemas/Database.yaml; legacy:
 * source_code/interface/web/sites/form/database.tform.php +
 * database_edit.php). Model named WebDatabase after its table to avoid
 * clashing with Laravel's database namespace (plan.md naming note).
 *
 * The DB stores the FULL (prefixed) name in `database_name` and the
 * applied prefix in `database_name_prefix`; the contract exposes the
 * un-prefixed name plus database_name_prefix/database_name_full.
 */
class WebDatabase extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'web_database';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'database_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'parent_domain_id',
        'type',
        'database_name',
        'database_quota',
        'database_user_id',
        'database_ro_user_id',
        'database_charset',
        'remote_access',
        'remote_ips',
        'backup_interval',
        'backup_copies',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'parent_domain_id' => 'integer',
        'database_quota' => 'integer',
        'database_user_id' => 'integer',
        'database_ro_user_id' => 'integer',
        'backup_copies' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'remote_access' => YesNoBoolean::class,
        'active' => YesNoBoolean::class,
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'database_id',
        'quota_exceeded',
        'last_quota_notification',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'database_name_full',
        'server_name',
        'parent_domain',
    ];

    /**
     * Contract defaults; remote_access defaults to false on input (the DB
     * column default 'y' is a legacy accident the contract overrides).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'mysql',
        'database_quota' => -1,
        'database_ro_user_id' => 0,
        'database_charset' => '',
        'remote_access' => 'n',
        'backup_interval' => 'none',
        'backup_copies' => 1,
        'active' => 'y',
        'database_name_prefix' => '',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * Contract `database_name` = stored name without its prefix.
     */
    protected function databaseName(): Attribute
    {
        return Attribute::get(function () {
            $raw = (string) ($this->getAttributes()['database_name'] ?? '');
            $prefix = (string) ($this->getAttributes()['database_name_prefix'] ?? '');

            if ($prefix !== '' && str_starts_with($raw, $prefix)) {
                return substr($raw, strlen($prefix));
            }

            return $raw;
        });
    }

    /**
     * Contract `database_name_full` = the stored (prefixed) name.
     */
    protected function databaseNameFull(): Attribute
    {
        return Attribute::get(fn () => $this->getAttributes()['database_name'] ?? null);
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
