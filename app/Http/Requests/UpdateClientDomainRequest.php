<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /clients/domains/{id} (api/modules/client/domains.yaml).
 *
 * The contract allows renames (409 when the new name is taken — enforced
 * in the controller against the real UNIQUE key; legacy silently kept the
 * old name, the API rejects conflicts instead). client_id re-owns the
 * domain via the client's sys_group.
 */
class UpdateClientDomainRequest extends ClientDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['sometimes', ...$this->domainRules()],
            'client_id' => ['sometimes', 'integer', Rule::exists('client', 'client_id')],
        ];
    }
}
