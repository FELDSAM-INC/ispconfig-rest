<?php

namespace App\Http\Requests;

/**
 * POST /system/dns-cas (api/modules/system/dns-cas.yaml):
 * ca_name and ca_issue are required; flags default to the DB's 'N'/0.
 */
class StoreDnsCaRequest extends DnsCaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ca_name' => ['required', 'string', 'max:255'],
            'ca_issue' => ['required', 'string', 'max:255'],
            'ca_wildcard' => ['sometimes', 'boolean'],
            'ca_iodef' => ['sometimes', 'nullable', 'string'],
            'ca_critical' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
