<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * client_template — a reusable set of resource limits applied to clients
 * (contract: api/components/schemas/ClientTemplate.yaml; legacy:
 * source_code/interface/web/client/form/client_template.tform.php and the
 * `client_template` DDL in ispconfig3.sql).
 *
 * template_type 'm' templates define a client's base limits (stored in
 * client.template_master); 'a' templates stack on top via the
 * client_template_assigned pivot. Note: legacy sets db_history=no for this
 * form — the REST API datalogs it anyway (documented, harmless surplus).
 */
class ClientTemplate extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client_template';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'template_id';

    /**
     * Writable fields per the contract (ClientTemplate.yaml). System fields
     * come from IspContext (BaseModel).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'template_name',
        'template_type',
        // Mail
        'mail_servers',
        'limit_maildomain',
        'limit_mailbox',
        'limit_mailalias',
        'limit_mailaliasdomain',
        'limit_mailforward',
        'limit_mailcatchall',
        'limit_mailrouting',
        'limit_mail_wblist',
        'limit_mailfilter',
        'limit_fetchmail',
        'limit_mailquota',
        'limit_spamfilter_wblist',
        'limit_spamfilter_user',
        'limit_spamfilter_policy',
        'limit_mail_backup',
        'limit_relayhost',
        'limit_mailmailinglist',
        // XMPP
        'default_xmppserver',
        'xmpp_servers',
        'limit_xmpp_domain',
        'limit_xmpp_user',
        'limit_xmpp_muc',
        'limit_xmpp_anon',
        'limit_xmpp_vjud',
        'limit_xmpp_proxy',
        'limit_xmpp_status',
        'limit_xmpp_pastebin',
        'limit_xmpp_httparchive',
        // Web
        'web_servers',
        'limit_web_ip',
        'limit_web_domain',
        'limit_web_quota',
        'web_php_options',
        'limit_cgi',
        'limit_ssi',
        'limit_perl',
        'limit_ruby',
        'limit_python',
        'force_suexec',
        'limit_hterror',
        'limit_wildcard',
        'limit_ssl',
        'limit_ssl_letsencrypt',
        'limit_web_subdomain',
        'limit_web_aliasdomain',
        'limit_ftp_user',
        // Shell
        'limit_shell_user',
        'ssh_chroot',
        'limit_webdav_user',
        'limit_backup',
        'limit_directive_snippets',
        'limit_aps',
        // DNS
        'dns_servers',
        'limit_dns_zone',
        'default_slave_dnsserver',
        'limit_dns_slave_zone',
        'limit_dns_record',
        // Database
        'db_servers',
        'limit_database',
        'limit_database_postgresql',
        'limit_database_user',
        'limit_database_quota',
        // Cron
        'limit_cron',
        'limit_cron_type',
        'limit_cron_frequency',
        'limit_traffic_quota',
        // Other
        'limit_client',
        'limit_domainmodule',
        'limit_openvz_vm',
        'limit_openvz_vm_template_id',
    ];

    /**
     * template_id is exposed as `id`.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'template_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * client_template enums are lowercase 'n'/'y' (ispconfig3.sql).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'default_xmppserver' => 'integer',
        'default_slave_dnsserver' => 'integer',
        'limit_maildomain' => 'integer',
        'limit_mailbox' => 'integer',
        'limit_mailalias' => 'integer',
        'limit_mailaliasdomain' => 'integer',
        'limit_mailforward' => 'integer',
        'limit_mailcatchall' => 'integer',
        'limit_mailrouting' => 'integer',
        'limit_mail_wblist' => 'integer',
        'limit_mailfilter' => 'integer',
        'limit_fetchmail' => 'integer',
        'limit_mailquota' => 'integer',
        'limit_spamfilter_wblist' => 'integer',
        'limit_spamfilter_user' => 'integer',
        'limit_spamfilter_policy' => 'integer',
        'limit_mailmailinglist' => 'integer',
        'limit_xmpp_domain' => 'integer',
        'limit_xmpp_user' => 'integer',
        'limit_web_domain' => 'integer',
        'limit_web_quota' => 'integer',
        'limit_web_subdomain' => 'integer',
        'limit_web_aliasdomain' => 'integer',
        'limit_ftp_user' => 'integer',
        'limit_shell_user' => 'integer',
        'limit_webdav_user' => 'integer',
        'limit_aps' => 'integer',
        'limit_cron' => 'integer',
        'limit_cron_frequency' => 'integer',
        'limit_traffic_quota' => 'integer',
        'limit_dns_zone' => 'integer',
        'limit_dns_slave_zone' => 'integer',
        'limit_dns_record' => 'integer',
        'limit_database' => 'integer',
        'limit_database_postgresql' => 'integer',
        'limit_database_user' => 'integer',
        'limit_database_quota' => 'integer',
        'limit_client' => 'integer',
        'limit_domainmodule' => 'integer',
        'limit_openvz_vm' => 'integer',
        'limit_openvz_vm_template_id' => 'integer',
        'limit_mail_backup' => YesNoBoolean::class,
        'limit_relayhost' => YesNoBoolean::class,
        'limit_xmpp_muc' => YesNoBoolean::class,
        'limit_xmpp_anon' => YesNoBoolean::class,
        'limit_xmpp_vjud' => YesNoBoolean::class,
        'limit_xmpp_proxy' => YesNoBoolean::class,
        'limit_xmpp_status' => YesNoBoolean::class,
        'limit_xmpp_pastebin' => YesNoBoolean::class,
        'limit_xmpp_httparchive' => YesNoBoolean::class,
        'limit_cgi' => YesNoBoolean::class,
        'limit_ssi' => YesNoBoolean::class,
        'limit_perl' => YesNoBoolean::class,
        'limit_ruby' => YesNoBoolean::class,
        'limit_python' => YesNoBoolean::class,
        'force_suexec' => YesNoBoolean::class,
        'limit_hterror' => YesNoBoolean::class,
        'limit_wildcard' => YesNoBoolean::class,
        'limit_ssl' => YesNoBoolean::class,
        'limit_ssl_letsencrypt' => YesNoBoolean::class,
        'limit_backup' => YesNoBoolean::class,
        'limit_directive_snippets' => YesNoBoolean::class,
    ];

    /**
     * Legacy tform default: template_type 'm'.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'template_type' => 'm',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * Pivot assignments referencing this template (additional assignments).
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClientTemplateAssigned::class, 'client_template_id', 'template_id');
    }

    /**
     * Whether the template is in use — as a master template
     * (client.template_master) or via a client_template_assigned pivot row
     * (fixed in-use check; legacy: client_template_del.php::onBeforeDelete).
     */
    public function isInUse(): bool
    {
        return $this->assignments()->exists()
            || Client::query()->where('template_master', $this->getKey())->exists();
    }
}
