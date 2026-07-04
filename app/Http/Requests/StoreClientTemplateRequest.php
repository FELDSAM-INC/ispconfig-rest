<?php

namespace App\Http\Requests;

/**
 * POST /clients/templates (api/modules/client/templates.yaml).
 * template_name is the only contract-required field; template_type
 * defaults to 'm' (legacy tform default).
 */
class StoreClientTemplateRequest extends ClientTemplateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        $rules['template_name'] = ['required', 'string', 'max:64'];

        return $rules;
    }
}
