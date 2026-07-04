<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_relay_recipient — relay recipient pattern (contract:
 * api/components/schemas/MailRelayRecipient.yaml; legacy:
 * source_code/interface/web/mail/form/mail_relay_recipient.tform.php).
 */
class MailRelayRecipient extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'mail_relay_recipient';

    /**
     * @var string
     */
    protected $primaryKey = 'relay_recipient_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'source',
        'access',
        'active',
    ];

    /**
     * The MailRelayRecipient contract exposes no sys_* fields.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'server_id',
        'source',
        'access',
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
        'server_id' => 'integer',
    ];

    /**
     * Legacy defaults: access 'OK', active y (hidden column — C-4).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'access' => 'OK',
        'active' => 'y',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
