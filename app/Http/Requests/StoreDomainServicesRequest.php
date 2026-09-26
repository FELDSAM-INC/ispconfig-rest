<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Domain creation selects services; resource-specific validation is reused before each write. */
class StoreDomainServicesRequest extends SitesRequest
{
    protected function normalizesDomain(): bool
    {
        return true;
    }

    protected function booleanFields(): array
    {
        return ['dns_service', 'mail_service', 'redirect_301'];
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/D'],
            'hosting_type' => ['required', Rule::in(['webhosting', 'none', 'alias'])],
            'parent_domain_id' => ['required_if:hosting_type,alias', 'nullable', 'integer', 'min:1'],
            'dns_service' => ['required', 'boolean'],
            'mail_service' => ['required', 'boolean'],
            'redirect_301' => ['sometimes', 'boolean'],
            'hd_quota' => ['sometimes', 'integer'],
            'traffic_quota' => ['sometimes', 'integer'],
            'php' => ['sometimes', 'string'],
            'server_php_id' => ['sometimes', 'integer'],
        ];
    }
}
