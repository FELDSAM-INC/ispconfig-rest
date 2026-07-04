<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * web_domain rows of type subdomain / alias — the "web child domain"
 * resource (contract: api/components/schemas/WebChildDomain.yaml; legacy:
 * source_code/interface/web/sites/form/web_childdomain.tform.php).
 *
 * Same table as WebDomain; the global scope keeps the resources disjoint.
 * The contract exposes only a small projection of the web_domain columns,
 * so this model whitelists them via $visible.
 */
class WebChildDomain extends BaseModel
{
    use HasSitesDisplayFields;

    public const CHILD_TYPES = ['subdomain', 'alias'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'web_domain';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'domain_id';

    protected static function booted(): void
    {
        static::addGlobalScope('childTypes', function ($query): void {
            $query->whereIn('type', self::CHILD_TYPES);
        });
    }

    /**
     * Writable fields per the contract. type/server_id/sys_groupid are
     * server-forced in the controller/service (fillable so the service can
     * assign them through fill, but requests never pass server_id).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_domain_id',
        'domain',
        'type',
        'subdomain',
        'redirect_type',
        'redirect_path',
        'seo_redirect',
        'ssl_letsencrypt_exclude',
        'active',
    ];

    /**
     * Only the contract's fields appear in responses (the underlying
     * web_domain table has ~90 columns).
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id', 'server_id', 'parent_domain_id', 'domain', 'type', 'subdomain',
        'redirect_type', 'redirect_path', 'seo_redirect',
        'ssl_letsencrypt_exclude', 'active',
        'sys_userid', 'sys_groupid', 'sys_perm_user', 'sys_perm_group',
        'sys_perm_other', 'server_name', 'parent_domain',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'parent_domain_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'ssl_letsencrypt_exclude' => YesNoBoolean::class,
        'active' => YesNoBoolean::class,
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'server_name',
        'parent_domain',
    ];

    /**
     * Raw column defaults per the legacy child-domain tform + contract.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'subdomain',
        'subdomain' => 'www',
        'redirect_type' => '',
        'seo_redirect' => '',
        'ssl_letsencrypt_exclude' => 'n',
        'active' => 'y',
        // Child domains never carry vhost provisioning values.
        'hd_quota' => 0,
        'traffic_quota' => -1,
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    protected function serverName(): Attribute
    {
        return Attribute::get(fn () => $this->lookupServerName((int) ($this->getAttributes()['server_id'] ?? 0)));
    }

    protected function parentDomain(): Attribute
    {
        return Attribute::get(fn () => $this->lookupDomainName((int) ($this->getAttributes()['parent_domain_id'] ?? 0)));
    }
}
