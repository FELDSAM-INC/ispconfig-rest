<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * sys_log — the per-server processing log ISPConfig's daemons write while
 * applying datalog changes; read-only through the API (contract:
 * api/components/schemas/SystemLog.yaml; spec 009 FR-016).
 *
 * Documented exception to the "models extend BaseModel" rule (constitution
 * Principle II), mirroring the sibling DataLog model: BaseModel exists to
 * journal WRITES through sys_datalog, but sys_log is populated exclusively
 * by the server daemons themselves — the API never creates or modifies
 * entries, and journaling writes to the processing log would be circular.
 * save()/delete() are overridden to throw so accidental writes fail loudly.
 */
class SystemLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sys_log';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'syslog_id';

    /**
     * ISPConfig tables carry no created_at/updated_at columns.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'syslog_id' => 'integer',
        'server_id' => 'integer',
        'datalog_id' => 'integer',
        'loglevel' => 'integer',
        'tstamp' => 'integer',
    ];

    /**
     * syslog_id is exposed as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'syslog_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * The processing log is immutable through the API (spec 009 FR-016).
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        throw new LogicException('sys_log is read-only through the API; entries are written by the server daemons.');
    }

    /**
     * The processing log is immutable through the API (spec 009 FR-016).
     */
    public function delete(): bool
    {
        throw new LogicException('sys_log is read-only through the API; entries are never deleted.');
    }
}
