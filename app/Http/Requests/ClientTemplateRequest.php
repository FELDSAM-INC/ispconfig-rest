<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared behavior for client-template writes (contract:
 * api/components/schemas/ClientTemplate.yaml; legacy:
 * source_code/interface/web/client/form/client_template.tform.php).
 * y/n flags accept booleans as well as legacy 'y'/'n' strings.
 */
abstract class ClientTemplateRequest extends FormRequest
{
    /**
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
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        foreach (static::FLAG_FIELDS as $flag) {
            if ($this->has($flag) && is_string($this->input($flag))) {
                $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value === null) {
                    $value = match (strtolower($this->input($flag))) {
                        'y' => true,
                        'n' => false,
                        default => $this->input($flag),
                    };
                }

                $input[$flag] = $value;
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * Rules shared by create and update.
     *
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        $rules = [
            'template_name' => ['sometimes', 'string', 'max:64'],
            'template_type' => ['sometimes', Rule::in(['m', 'a'])],
            'mail_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'web_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'dns_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'db_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'xmpp_servers' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'default_xmppserver' => ['sometimes', 'integer', 'min:0'],
            'default_slave_dnsserver' => ['sometimes', 'integer', 'min:0'],
            'limit_web_ip' => ['sometimes', 'nullable', 'string'],
            'web_php_options' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssh_chroot' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit_cron_type' => ['sometimes', Rule::in(['url', 'chrooted', 'full'])],
        ];

        $integerFields = [
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
            'limit_dns_slave_zone',
            'limit_dns_record',
            'limit_database',
            'limit_database_postgresql',
            'limit_database_user',
            'limit_database_quota',
            'limit_client',
            'limit_domainmodule',
            'limit_openvz_vm',
            'limit_openvz_vm_template_id',
        ];

        foreach ($integerFields as $field) {
            $rules[$field] = ['sometimes', 'integer', 'min:-1'];
        }

        foreach (static::FLAG_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
