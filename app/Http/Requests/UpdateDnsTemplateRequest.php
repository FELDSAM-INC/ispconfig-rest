<?php

namespace App\Http\Requests;

/**
 * PUT /dns/templates/{id} (api/modules/dns/template.yaml). Partial updates:
 * every field is optional; the `fields` whitelist still applies whenever
 * `fields` is present (legacy custom validator behavior).
 */
class UpdateDnsTemplateRequest extends DnsTemplateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['name'], 'sometimes');
        array_unshift($rules['fields'], 'sometimes');
        array_unshift($rules['template'], 'sometimes');

        return $rules;
    }
}
