<?php

namespace App\Models;

use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * web_database_user — a database credential set shared by website
 * databases (contract: api/components/schemas/DatabaseUser.yaml; legacy:
 * source_code/interface/web/sites/form/database_user.tform.php +
 * database_user_edit.php).
 *
 * The DB stores the FULL (prefixed) name in `database_user` and the
 * applied prefix in `database_user_prefix`. All four password columns are
 * hashes derived from the submitted plaintext (MYSQL PASSWORD()-style,
 * MYSQLSHA2, POSTGRESHA256) — plaintext is never stored, none is ever
 * returned.
 */
class WebDatabaseUser extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'web_database_user';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'database_user_id';

    /**
     * Password hashes are assigned explicitly by the controller after
     * hashing — the plaintext field is never mass-assigned.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'database_user',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'database_user_id',
        'database_password',
        'database_password_sha2',
        'database_password_mongo',
        'database_password_postgres',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * server_id is always 0 — database users are provisioned on every
     * server hosting one of their databases (legacy "we need this on all
     * servers").
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'server_id' => 0,
        'database_user_prefix' => '',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * Contract `database_user` = stored name without its prefix.
     */
    protected function databaseUser(): Attribute
    {
        return Attribute::get(function () {
            $raw = (string) ($this->getAttributes()['database_user'] ?? '');
            $prefix = (string) ($this->getAttributes()['database_user_prefix'] ?? '');

            if ($prefix !== '' && str_starts_with($raw, $prefix)) {
                return substr($raw, strlen($prefix));
            }

            return $raw;
        });
    }
}
