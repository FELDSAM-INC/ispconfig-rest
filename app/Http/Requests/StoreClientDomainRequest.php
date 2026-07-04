<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /clients/domains (api/modules/client/domains.yaml).
 * client_id is a write-only request field resolved to the client's
 * sys_group; unknown clients are 422 (exists rule), duplicate domains 409
 * (controller, real UNIQUE key).
 */
class StoreClientDomainRequest extends ClientDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', ...$this->domainRules()],
            'client_id' => ['required', 'integer', Rule::exists('client', 'client_id')],
        ];
    }
}
