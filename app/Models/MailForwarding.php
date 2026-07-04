<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_forwarding — forward / catchall / alias rows served by /mail/forwards
 * (contract: api/components/schemas/MailForwarding.yaml; legacy:
 * source_code/interface/web/mail/form/mail_forward.tform.php,
 * mail_alias.tform.php, mail_domain_catchall.tform.php).
 *
 * Rows with type='aliasdomain' share the table but are exposed through the
 * dedicated /mail/alias-domains resource (MailAliasDomain subclass) — the
 * forwardTypes() scope keeps them out of this resource.
 */
class MailForwarding extends BaseModel
{
    public const FORWARD_TYPES = ['forward', 'catchall', 'alias'];

    /**
     * @var string
     */
    protected $table = 'mail_forwarding';

    /**
     * @var string
     */
    protected $primaryKey = 'forwarding_id';

    /**
     * server_id and sys_groupid are inherited from the relevant mail_domain
     * (never writable); type is fixed after creation but set on store.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'source',
        'destination',
        'type',
        'active',
        'allow_send_as',
        'greylisting',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'forwarding_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'is_catchall',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'active' => YesNoBoolean::class,
        'allow_send_as' => YesNoBoolean::class,
        'greylisting' => YesNoBoolean::class,
        'server_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Legacy form defaults: active y, greylisting n. allow_send_as differs
     * per type (y for alias, n for forward/catchall) and is applied by the
     * controller when absent from the request.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => 'y',
        'greylisting' => 'n',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * Computed contract field: catchall sources start with '@'.
     */
    protected function isCatchall(): Attribute
    {
        return Attribute::get(fn () => str_starts_with((string) ($this->getAttributes()['source'] ?? ''), '@'));
    }

    /**
     * Route binding is scoped to the resource's types: an alias-domain row
     * 404s on /mail/forwards (and vice versa in the subclass). Funnelled
     * through resolveRouteBindingQuery so BaseModel's row-permission scoping
     * applies too (spec 011 FR-008).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->resolveRouteBindingQuery($this->newQuery()->forwardTypes(), $value, $field)->first();
    }

    /**
     * Scope: the /mail/forwards resource (forward, catchall, alias).
     */
    public function scopeForwardTypes($query)
    {
        return $query->whereIn('type', self::FORWARD_TYPES);
    }

    /**
     * Scope: the /mail/alias-domains resource (type=aliasdomain).
     */
    public function scopeAliasDomains($query)
    {
        return $query->where('type', 'aliasdomain');
    }
}
