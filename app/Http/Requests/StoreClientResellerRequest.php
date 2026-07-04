<?php

namespace App\Http\Requests;

/**
 * POST /resellers (api/modules/client/resellers.yaml).
 *
 * ClientReseller.yaml = Client.yaml + required limit_client. The reseller
 * condition itself (limit_client > 0 or -1; anything else is 400, not 422)
 * is enforced in the controller per the contract's wording.
 */
class StoreClientResellerRequest extends StoreClientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['limit_client'] = ['required', 'integer', 'min:-1'];

        return $rules;
    }
}
