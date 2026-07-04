<?php

namespace App\Http\Requests;

use App\Models\Client;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Shared behavior for client/reseller writes (contract:
 * api/components/schemas/Client.yaml + ClientReseller.yaml; legacy:
 * source_code/interface/web/client/form/client.tform.php):
 *
 *  - y/n flag fields accept booleans as well as legacy 'y'/'n' strings
 *    (the API speaks booleans, YesNoBoolean stores y/n);
 *  - username follows the legacy regex and is checked for uniqueness
 *    against sys_user (legacy validate_client::username_unique) and,
 *    stricter than legacy, against client.username;
 *  - field lengths mirror the real DDL (64-char contact/company names etc.),
 *    fixing spec 001 gap G15.
 */
abstract class ClientRequest extends FormRequest
{
    /**
     * The client's boolean (ENUM y/n) columns.
     *
     * @var array<int, string>
     */
    protected const FLAG_FIELDS = [
        'limit_mail_backup',
        'limit_relayhost',
        'limit_xmpp_muc',
        'limit_xmpp_anon',
        'limit_xmpp_vjud',
        'limit_xmpp_proxy',
        'limit_xmpp_status',
        'limit_xmpp_pastebin',
        'limit_xmpp_httparchive',
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
        'limit_backup',
        'limit_directive_snippets',
        'can_use_api',
        'locked',
        'canceled',
    ];

    /**
     * Authentication happens in the api.key middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept legacy 'y'/'n' strings for the boolean flags.
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        foreach (static::FLAG_FIELDS as $flag) {
            if ($this->has($flag) && is_string($this->input($flag))) {
                $input[$flag] = $this->normalizeFlag($this->input($flag));
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    protected function normalizeFlag(string $value): mixed
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($bool !== null) {
            return $bool;
        }

        return match (strtolower($value)) {
            'y' => true,
            'n' => false,
            default => $value, // left invalid -> boolean rule fails
        };
    }

    /**
     * Validated data ready for the service/model.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * Rules shared by create and update — everything optional here; the
     * concrete requests overlay required/uniqueness rules.
     *
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        $rules = [
            'parent_client_id' => ['sometimes', 'integer', 'min:0'],
            // Contact (lengths per ispconfig3.sql)
            'contact_name' => ['sometimes', 'string', 'max:64'],
            'contact_firstname' => ['sometimes', 'nullable', 'string', 'max:64'],
            'gender' => ['sometimes', 'nullable', Rule::in(['', 'm', 'f'])],
            'email' => ['sometimes', 'email', 'max:255'],
            'telephone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'fax' => ['sometimes', 'nullable', 'string', 'max:32'],
            'internet' => ['sometimes', 'nullable', 'string', 'max:255'],
            'icq' => ['sometimes', 'nullable', 'string', 'max:16'],
            'notes' => ['sometimes', 'nullable', 'string'],
            // Company
            'company_name' => ['sometimes', 'string', 'max:64'],
            'company_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vat_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Address
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:32'],
            'city' => ['sometimes', 'nullable', 'string', 'max:64'],
            'state' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            // Authentication (legacy regex /^[\w\.\-\_]{0,64}$/ + NOTEMPTY)
            'username' => ['sometimes', 'string', 'min:1', 'max:64', 'regex:/^[\w\.\-]{1,64}$/', $this->usernameUniqueRule()],
            // Bank
            'bank_account_owner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_iban' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_swift' => ['sometimes', 'nullable', 'string', 'max:255'],
            'paypal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            // Servers
            'default_mailserver' => ['sometimes', 'integer', 'min:0'],
            'default_webserver' => ['sometimes', 'integer', 'min:0'],
            'default_dnsserver' => ['sometimes', 'integer', 'min:0'],
            'default_slave_dnsserver' => ['sometimes', 'integer', 'min:0'],
            'default_dbserver' => ['sometimes', 'integer', 'min:0'],
            'default_xmppserver' => ['sometimes', 'integer', 'min:0'],
            'mail_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'web_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'dns_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'db_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'xmpp_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            // Settings
            'language' => ['sometimes', 'string', 'size:2'],
            'usertheme' => ['sometimes', 'string', 'max:32'],
            'template_master' => ['sometimes', 'integer', 'min:0', $this->masterTemplateRule()],
            // String limit fields
            'limit_xmpp_auth_options' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit_web_ip' => ['sometimes', 'nullable', 'string'],
            'web_php_options' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssh_chroot' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit_cron_type' => ['sometimes', Rule::in(['url', 'chrooted', 'full'])],
            // Account management
            'added_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'added_by' => ['sometimes', 'nullable', 'string', 'max:255'],
            'validation_status' => ['sometimes', Rule::in(['accept', 'review', 'reject'])],
            'risk_score' => ['sometimes', 'integer', 'min:0'],
            'activation_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'customer_no_template' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_no_start' => ['sometimes', 'integer', 'min:0'],
            'customer_no_counter' => ['sometimes', 'integer', 'min:0'],
            'ssh_rsa' => ['sometimes', 'nullable', 'string', 'max:600'],
        ];

        foreach ($this->integerLimitFields() as $field) {
            $rules[$field] = ['sometimes', 'integer', 'min:-1'];
        }

        foreach (static::FLAG_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    protected function integerLimitFields(): array
    {
        return [
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
            'limit_mailmailinglist',
            'limit_xmpp_domain',
            'limit_xmpp_user',
            'limit_web_domain',
            'limit_web_quota',
            'limit_web_subdomain',
            'limit_web_aliasdomain',
            'limit_ftp_user',
            'limit_shell_user',
            'limit_webdav_user',
            'limit_aps',
            'limit_cron',
            'limit_cron_frequency',
            'limit_traffic_quota',
            'limit_dns_zone',
            'limit_dns_record',
            'limit_dns_slave_zone',
            'limit_database',
            'limit_database_quota',
            'limit_database_postgresql',
            'limit_database_user',
            'limit_client',
            'limit_domainmodule',
            'limit_openvz_vm',
            'limit_openvz_vm_template_id',
        ];
    }

    /**
     * The client being updated (null on create).
     */
    protected function currentClient(): ?Client
    {
        foreach (['client', 'reseller'] as $param) {
            $bound = $this->route($param);

            if ($bound instanceof Client) {
                return $bound;
            }
        }

        return null;
    }

    /**
     * Legacy username uniqueness: no other sys_user may carry the name
     * (validate_client::username_unique; excluding the client's own login on
     * update); additionally unique within the client table itself.
     */
    protected function usernameUniqueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $current = $this->currentClient();
            $currentId = $current?->getKey();

            $sysUserQuery = DB::table('sys_user')->where('username', $value);
            $clientQuery = DB::table('client')->where('username', $value);

            if ($currentId !== null) {
                $sysUserQuery->where('client_id', '!=', $currentId);
                $clientQuery->where('client_id', '!=', $currentId);
            }

            if ($sysUserQuery->exists() || $clientQuery->exists()) {
                $fail('The username is already in use.');
            }
        };
    }

    /**
     * template_master must be 0 (none) or an existing master ('m') template.
     */
    protected function masterTemplateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ((int) $value === 0) {
                return;
            }

            $exists = DB::table('client_template')
                ->where('template_id', (int) $value)
                ->where('template_type', 'm')
                ->exists();

            if (! $exists) {
                $fail('The selected master template does not exist.');
            }
        };
    }
}
