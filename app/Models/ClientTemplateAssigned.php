<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * client_template_assigned — the pivot linking a client to an additional
 * ('a'-type) limit template (contract:
 * api/components/schemas/ClientTemplateAssigned.yaml; DDL: assigned_template_id
 * PK, client_id, client_template_id — no sys_* columns).
 *
 * Master-template assignments live in client.template_master and have no
 * pivot row. This model was missing entirely in the previous implementation
 * (spec 001 gap G1 — the root cause of the GET /clients/{id} 500).
 *
 * Writes go through BaseModel, so pivot changes are datalogged. Legacy does
 * NOT datalog this table (plain INSERT/DELETE in client_templates.inc.php);
 * the surplus datalog rows are deliberate (constitution Principle II) and
 * harmless — no server plugin subscribes to this table.
 */
class ClientTemplateAssigned extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client_template_assigned';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'assigned_template_id';

    /**
     * The table has no sys_userid/sys_groupid/sys_perm_* columns.
     */
    protected bool $hasSysFields = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'client_template_id',
    ];

    /**
     * assigned_template_id is exposed as `id` (x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'assigned_template_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id' => 'integer',
        'client_template_id' => 'integer',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * The assigned template.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ClientTemplate::class, 'client_template_id', 'template_id');
    }

    /**
     * The client the template is assigned to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }
}
