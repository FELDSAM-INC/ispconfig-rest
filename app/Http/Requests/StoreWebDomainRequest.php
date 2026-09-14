<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesAssignedServer;
use Illuminate\Validation\Rule;

/**
 * POST /sites/web-domains (api/modules/sites/web-domains.yaml).
 *
 * Server selection (spec 016): client and reseller keys creating a vhost get
 * the account's first assigned web server when server_id is omitted and may
 * only use assigned web servers (legacy web_vhost_domain_edit.php:115-122,
 * server_chosen_not_ok). vhostsubdomain/vhostalias always use the parent's
 * server (WebDomainService), so non-admin keys may omit server_id there.
 */
class StoreWebDomainRequest extends WebDomainRequest
{
    use ResolvesAssignedServer;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->isVhost()) {
            $this->mergeAssignedServerDefault('web');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->isVhost() ? $this->assignedServerMessages('web') : [];
    }

    protected function isVhost(): bool
    {
        return $this->input('type', 'vhost') === 'vhost';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = fn (): string => (string) $this->input('type', 'vhost');

        $adminServerRules = [
            'required',
            'integer',
            Rule::exists('server', 'server_id')
                ->where('web_server', 1)
                ->where('mirror_server_id', 0),
        ];

        if ($this->isVhost()) {
            $serverRules = $this->assignedServerRules('web', $adminServerRules);
        } else {
            // Children inherit the parent's server (WebDomainService).
            $serverRules = $this->usesAssignedServers() ? ['sometimes', 'integer'] : $adminServerRules;
        }

        return array_merge($this->commonRules(), [
            'server_id' => $serverRules,
            'domain' => [
                'required',
                'string',
                'max:255',
                $this->webDomainFormatRule(),
            ],
            'type' => ['sometimes', Rule::in(['vhost', 'vhostsubdomain', 'vhostalias'])],
            'parent_domain_id' => [
                Rule::requiredIf(fn (): bool => in_array($type(), ['vhostsubdomain', 'vhostalias'], true)),
                'integer',
                Rule::when(
                    in_array($type(), ['vhostsubdomain', 'vhostalias'], true),
                    [Rule::exists('web_domain', 'domain_id')->where('type', 'vhost')]
                ),
            ],
            'hd_quota' => [
                'sometimes',
                ...$this->quotaRules(),
                $this->vhostQuotaNotZeroRule($type),
            ],
            // Optional owning client (resolved to its sys_group on create).
            'client_id' => ['sometimes', 'integer', Rule::exists('client', 'client_id')],
        ]);
    }
}
