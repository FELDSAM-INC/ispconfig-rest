<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * client_circle — a named, comma-separated list of client ids used by the
 * ISPConfig interface to filter client lists (contract:
 * api/components/schemas/ClientCircle.yaml; legacy:
 * source_code/interface/web/client/form/client_circle.tform.php).
 */
class ClientCircle extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client_circle';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'circle_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'circle_name',
        'client_ids',
        'description',
        'active',
    ];

    /**
     * circle_id is exposed as `id`.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'circle_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * client_circle.active is ENUM('n','y') lowercase.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'active' => YesNoBoolean::class,
    ];

    /**
     * Legacy tform default: active 'y'. DB-native value ($attributes
     * bypasses the casts).
     *
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

    /**
     * The circle's client ids as an integer array.
     *
     * @return array<int, int>
     */
    public function clientIds(): array
    {
        $raw = trim((string) ($this->getAttributes()['client_ids'] ?? ''));

        if ($raw === '') {
            return [];
        }

        return array_map('intval', array_filter(array_map('trim', explode(',', $raw)), fn ($id) => $id !== ''));
    }
}
