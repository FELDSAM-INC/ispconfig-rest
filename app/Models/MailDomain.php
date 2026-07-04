<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_domain — an email domain with DKIM signing and outbound-relay
 * configuration (contract: api/components/schemas/MailDomain.yaml; legacy:
 * source_code/interface/web/mail/form/mail_domain.tform.php).
 */
class MailDomain extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mail_domain';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'domain_id';

    /**
     * Writable fields per the contract. System fields come from IspContext
     * (BaseModel), dkim_public is always derived server-side — neither is
     * mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'domain',
        'dkim',
        'dkim_private',
        'dkim_selector',
        'relay_host',
        'relay_user',
        'relay_pass',
        'active',
        'local_delivery',
    ];

    /**
     * mail_domain enums are lowercase 'n'/'y' (ispconfig3.sql), so the
     * default YesNoBoolean case applies.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => YesNoBoolean::class,
        'dkim' => YesNoBoolean::class,
        'local_delivery' => YesNoBoolean::class,
        'server_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * relay_pass is writeOnly in the contract and never serialized;
     * domain_id is exposed as `id` (schema x-db-field mapping).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'relay_pass',
        'domain_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * Raw column defaults, mirroring the legacy tform field defaults
     * (dkim 'n', dkim_selector 'default', active 'y', local_delivery 'y').
     * Values are DB-native — $attributes bypasses the casts.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'dkim' => 'n',
        'dkim_selector' => 'default',
        'relay_host' => '',
        'relay_user' => '',
        'relay_pass' => '',
        'active' => 'y',
        'local_delivery' => 'y',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * Derive the DKIM public key from a private key, mirroring legacy
     * mail_domain_edit.php::onSubmit() (openssl_pkey_get_details).
     * Returns null when the key cannot be parsed.
     */
    public static function derivePublicKey(string $privateKey): ?string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        return $details === false ? null : $details['key'];
    }

    /**
     * Scope: only active domains.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'y');
    }

    /**
     * Scope: only domains with local delivery.
     */
    public function scopeWithLocalDelivery($query)
    {
        return $query->where('local_delivery', 'y');
    }

    /**
     * Scope: domains on a specific server.
     */
    public function scopeForServer($query, int $serverId)
    {
        return $query->where('server_id', $serverId);
    }
}
