<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/web-domains (api/modules/sites/web-domains.yaml).
 */
class StoreWebDomainRequest extends WebDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = fn (): string => (string) $this->input('type', 'vhost');

        return array_merge($this->commonRules(), [
            'server_id' => [
                'required',
                'integer',
                Rule::exists('server', 'server_id')
                    ->where('web_server', 1)
                    ->where('mirror_server_id', 0),
            ],
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
        ]);
    }
}
