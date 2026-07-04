<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/web-child-domains (api/modules/sites/web-child-domains.yaml).
 */
class StoreWebChildDomainRequest extends WebChildDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'parent_domain_id' => [
                'required',
                'integer',
                Rule::exists('web_domain', 'domain_id')->where('type', 'vhost'),
            ],
            'domain' => [
                'required',
                'string',
                'max:255',
                $this->childDomainFormatRule(fn (): string => (string) $this->input('type', 'subdomain')),
            ],
            'type' => ['required', Rule::in(['subdomain', 'alias'])],
        ]);
    }
}
