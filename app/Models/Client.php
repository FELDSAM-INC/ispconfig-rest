<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * client — a customer or reseller (contract: api/components/schemas/Client.yaml;
 * legacy: source_code/interface/web/client/form/client.tform.php and the
 * `client` DDL in ispconfig3.sql).
 *
 * A reseller is a client row with limit_client > 0 or -1 (see ClientReseller).
 * ENUM('n','y') columns are exposed as booleans, the primary key as `id`;
 * password is writeOnly (stored as a legacy CRYPT hash), tmp_data/id_rsa are
 * intentionally never exposed (schema note).
 */
class Client extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'client_id';

    /**
     * Writable fields per the contract (everything not readOnly in
     * Client.yaml). System fields come from IspContext/ClientService,
     * template_additional is server-managed bookkeeping, created_at is
     * readOnly — none of them mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_client_id',
        // Contact
        'contact_name',
        'contact_firstname',
        'gender',
        'email',
        'telephone',
        'mobile',
        'fax',
        'internet',
        'icq',
        'notes',
        // Company
        'company_name',
        'company_id',
        'vat_id',
        'customer_no',
        // Address
        'street',
        'zip',
        'city',
        'state',
        'country',
        // Authentication
        'username',
        'password',
        // Bank
        'bank_account_owner',
        'bank_account_number',
        'bank_code',
        'bank_name',
        'bank_account_iban',
        'bank_account_swift',
        'paypal_email',
        // Servers
        'default_mailserver',
        'default_webserver',
        'default_dnsserver',
        'default_slave_dnsserver',
        'default_dbserver',
        'default_xmppserver',
        'mail_servers',
        'web_servers',
        'dns_servers',
        'db_servers',
        'xmpp_servers',
        // Settings
        'language',
        'usertheme',
        'template_master',
        // Mail limits
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
        // XMPP limits
        'limit_xmpp_domain',
        'limit_xmpp_user',
        'limit_xmpp_muc',
        'limit_xmpp_anon',
        'limit_xmpp_auth_options',
        'limit_xmpp_vjud',
        'limit_xmpp_proxy',
        'limit_xmpp_status',
        'limit_xmpp_pastebin',
        'limit_xmpp_httparchive',
        // Web limits
        'limit_web_ip',
        'limit_web_domain',
        'limit_web_quota',
        'limit_web_subdomain',
        'limit_web_aliasdomain',
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
        'limit_ftp_user',
        // Shell
        'limit_shell_user',
        'ssh_chroot',
        'limit_webdav_user',
        'limit_backup',
        'limit_directive_snippets',
        'limit_aps',
        // Cron
        'limit_cron',
        'limit_cron_type',
        'limit_cron_frequency',
        'limit_traffic_quota',
        // DNS limits
        'limit_dns_zone',
        'limit_dns_record',
        'limit_dns_slave_zone',
        // Database limits
        'limit_database',
        'limit_database_quota',
        'limit_database_postgresql',
        'limit_database_user',
        // Additional service limits
        'limit_client',
        'limit_domainmodule',
        'limit_openvz_vm',
        'limit_openvz_vm_template_id',
        // Account management
        'can_use_api',
        'locked',
        'canceled',
        'added_date',
        'added_by',
        'validation_status',
        'risk_score',
        'activation_code',
        // Customer number management
        'customer_no_template',
        'customer_no_start',
        'customer_no_counter',
        // Technical
        'ssh_rsa',
    ];

    /**
     * password is writeOnly; tmp_data/id_rsa are intentionally not exposed
     * (Client.yaml header note); client_id is exposed as `id`.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'tmp_data',
        'id_rsa',
        'client_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * client enums are lowercase 'n'/'y' (ispconfig3.sql).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'parent_client_id' => 'integer',
        'default_mailserver' => 'integer',
        'default_webserver' => 'integer',
        'default_dnsserver' => 'integer',
        'default_slave_dnsserver' => 'integer',
        'default_dbserver' => 'integer',
        'default_xmppserver' => 'integer',
        'template_master' => 'integer',
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
        'limit_dns_record' => 'integer',
        'limit_dns_slave_zone' => 'integer',
        'limit_database' => 'integer',
        'limit_database_quota' => 'integer',
        'limit_database_postgresql' => 'integer',
        'limit_database_user' => 'integer',
        'limit_client' => 'integer',
        'limit_domainmodule' => 'integer',
        'limit_openvz_vm' => 'integer',
        'limit_openvz_vm_template_id' => 'integer',
        'customer_no_start' => 'integer',
        'customer_no_counter' => 'integer',
        'risk_score' => 'integer',
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
        'can_use_api' => YesNoBoolean::class,
        'locked' => YesNoBoolean::class,
        'canceled' => YesNoBoolean::class,
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * created_at is a Unix timestamp column (bigint); the contract returns
     * it as an ISO 8601 date-time (readOnly).
     */
    protected function createdAt(): Attribute
    {
        return Attribute::get(function () {
            $raw = $this->getAttributes()['created_at'] ?? null;

            if ($raw === null || (int) $raw === 0) {
                return null;
            }

            return date(DATE_ATOM, (int) $raw);
        });
    }

    /**
     * The client's master limit template (client.template_master).
     */
    public function masterTemplate(): BelongsTo
    {
        return $this->belongsTo(ClientTemplate::class, 'template_master', 'template_id');
    }

    /**
     * Additional (addon) template assignments — pivot rows in
     * client_template_assigned.
     */
    public function templateAssignments(): HasMany
    {
        return $this->hasMany(ClientTemplateAssigned::class, 'client_id', 'client_id');
    }

    /**
     * Sub-clients owned by this client (reseller relationship).
     */
    public function clients(): HasMany
    {
        return $this->hasMany(self::class, 'parent_client_id', 'client_id');
    }

    /**
     * Whether this row satisfies the reseller condition
     * (limit_client > 0 OR limit_client = -1, per legacy reseller_list).
     */
    public function isReseller(): bool
    {
        $limitClient = (int) ($this->getAttributes()['limit_client'] ?? 0);

        return $limitClient > 0 || $limitClient === -1;
    }
}
