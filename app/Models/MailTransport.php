<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_transport — Postfix transport-map entry (contract:
 * api/components/schemas/MailTransport.yaml; legacy:
 * source_code/interface/web/mail/form/mail_transport.tform.php).
 */
class MailTransport extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'mail_transport';

    /**
     * @var string
     */
    protected $primaryKey = 'transport_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'domain',
        'transport',
        'sort_order',
        'active',
    ];

    /**
     * The MailTransport contract exposes no sys_* fields.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'server_id',
        'domain',
        'transport',
        'sort_order',
        'active',
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
        'active' => YesNoBoolean::class,
        'sort_order' => 'integer',
        'server_id' => 'integer',
    ];

    /**
     * Legacy defaults: sort_order 5 (DB default 5, legacy select 1-10 — C-5),
     * active y (form default).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 5,
        'active' => 'y',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
