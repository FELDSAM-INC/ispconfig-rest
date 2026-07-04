<?php

namespace App\Http\Requests;

/**
 * POST /dns/templates (api/modules/dns/template.yaml).
 *
 * The contract requires name, fields and template; visible defaults to
 * true (model attribute default 'Y').
 */
class StoreDnsTemplateRequest extends DnsTemplateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['name'], 'required');
        array_unshift($rules['fields'], 'required');
        array_unshift($rules['template'], 'required');

        return $rules;
    }
}
