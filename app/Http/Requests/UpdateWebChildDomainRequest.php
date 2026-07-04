<?php

namespace App\Http\Requests;

use App\Models\WebChildDomain;
use Illuminate\Validation\Rule;

/**
 * PUT /sites/web-child-domains/{id}
 * (api/modules/sites/web-child-domains.yaml).
 *
 * `type` remains server-controlled: the resource type cannot flip between
 * subdomain and alias (sending the current value is accepted).
 */
class UpdateWebChildDomainRequest extends WebChildDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'parent_domain_id' => [
                'sometimes',
                'integer',
                Rule::exists('web_domain', 'domain_id')->where('type', 'vhost'),
            ],
            'domain' => [
                'sometimes',
                'string',
                'max:255',
                $this->childDomainFormatRule(fn (): string => $this->currentType()),
            ],
            'type' => [
                'sometimes',
                Rule::in(['subdomain', 'alias']),
                $this->immutableRule(fn () => $this->currentType(), 'child domain type'),
            ],
        ]);
    }

    protected function currentType(): string
    {
        $domain = $this->route('webChildDomain');

        return $domain instanceof WebChildDomain
            ? (string) $domain->getAttributes()['type']
            : 'subdomain';
    }
}
