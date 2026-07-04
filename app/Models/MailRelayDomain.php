<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_relay_domain — relay domain entry (contract:
 * api/components/schemas/MailRelayDomain.yaml; legacy:
 * source_code/interface/web/mail/form/mail_relay_domain.tform.php).
 */
class MailRelayDomain extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'mail_relay_domain';

    /**
     * @var string
     */
    protected $primaryKey = 'relay_domain_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'domain',
        'access',
        'active',
    ];

    /**
     * The MailRelayDomain contract exposes no sys_* fields.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'server_id',
        'domain',
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
     * Legacy defaults: access 'OK' (DB default, hidden in the legacy form —
     * C-4), active y.
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
