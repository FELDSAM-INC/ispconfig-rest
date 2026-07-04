<?php

namespace App\Http\Requests;

/**
 * PUT /system/dns-cas/{id} (api/modules/system/dns-cas.yaml).
 * Keys absent from the request are left unchanged.
 */
class UpdateDnsCaRequest extends DnsCaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ca_name' => ['sometimes', 'filled', 'string', 'max:255'],
            'ca_issue' => ['sometimes', 'filled', 'string', 'max:255'],
            'ca_wildcard' => ['sometimes', 'boolean'],
            'ca_iodef' => ['sometimes', 'nullable', 'string'],
            'ca_critical' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
