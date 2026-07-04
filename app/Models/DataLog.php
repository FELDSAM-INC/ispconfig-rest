<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * sys_datalog — ISPConfig's write-ahead change journal, read-only through
 * the API (contract: api/components/schemas/DataLog.yaml; spec 004 FR-008).
 *
 * Documented exception to the "models extend BaseModel" rule (constitution
 * Principle II / spec 004 gap G3): BaseModel exists to route WRITES through
 * this very table, so a model over the journal itself must not carry those
 * write semantics — journaling the journal would recurse, and the API never
 * creates or modifies entries anyway. Reads are unaffected by Principle II.
 * save()/delete() are overridden to throw so accidental writes fail loudly.
 */
class DataLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sys_datalog';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'datalog_id';

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
        'datalog_id' => 'integer',
        'server_id' => 'integer',
        'tstamp' => 'integer',
    ];

    /**
     * datalog_id is exposed as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'datalog_id',
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
     * Action is stored lowercase ('i'/'u'/'d', ispconfig3.sql char(1));
     * normalize defensively so the contract enum always holds.
     */
    protected function action(): Attribute
    {
        return Attribute::get(fn (?string $value) => strtolower((string) $value));
    }

    /**
     * The stored payload is a PHP-serialized {old, new} diff record written
     * by DatalogService::log() (legacy datalogSave()). Expose it as a
     * structured object; corrupt/legacy blobs that cannot be deserialized
     * are returned verbatim as the raw string (contract: DataLog.yaml).
     * allowed_classes=false closes the PHP object-injection vector — journal
     * payloads are plain arrays, never objects (spec 004 gap G10).
     */
    protected function data(): Attribute
    {
        return Attribute::get(function (?string $value) {
            if ($value === null || $value === '') {
                return null;
            }

            $payload = @unserialize($value, ['allowed_classes' => false]);

            // unserialize() signals failure with false; 'b:0;' is the one
            // input for which false is a legitimate result.
            if ($payload === false && $value !== 'b:0;') {
                return $value;
            }

            return $payload;
        });
    }

    /**
     * The journal is immutable through the API (spec 004 FR-008).
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        throw new LogicException('sys_datalog is read-only through the API; writes go through DatalogService::log().');
    }

    /**
     * The journal is immutable through the API (spec 004 FR-008).
     */
    public function delete(): bool
    {
        throw new LogicException('sys_datalog is read-only through the API; journal entries are never deleted.');
    }
}
