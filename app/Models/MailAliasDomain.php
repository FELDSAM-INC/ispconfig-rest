<?php

namespace App\Models;

/**
 * /mail/alias-domains resource — rows of mail_forwarding with the hidden
 * discriminator type='aliasdomain' (contract:
 * api/components/schemas/MailAliasDomain.yaml; legacy:
 * source_code/interface/web/mail/form/mail_aliasdomain.tform.php + the hidden
 * type field in templates/mail_aliasdomain_edit.htm). There is NO
 * mail_alias_domain table in ISPConfig (C-1).
 *
 * The subclass only changes the serialization shape: the fixed `type`,
 * `allow_send_as`, `greylisting` and the computed `is_catchall` are not part
 * of the MailAliasDomain contract; sys_* fields are.
 */
class MailAliasDomain extends MailForwarding
{
    /**
     * type is forced to 'aliasdomain' by the controller, never writable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'source',
        'destination',
        'active',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'forwarding_id',
        'type',
        'allow_send_as',
        'greylisting',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'aliasdomain',
        'active' => 'y',
        'allow_send_as' => 'n',
        'greylisting' => 'n',
    ];

    /**
     * Route binding is scoped to type='aliasdomain': a forward/alias/catchall
     * row 404s on /mail/alias-domains.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQuery()
            ->aliasDomains()
            ->where($field ?? $this->getKeyName(), $value)
            ->first();
    }
}
