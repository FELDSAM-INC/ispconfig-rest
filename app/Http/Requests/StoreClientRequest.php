<?php

namespace App\Http\Requests;

/**
 * POST /clients (api/modules/client/clients.yaml).
 *
 * Contract-required fields: company_name, contact_name, email, username,
 * password (Client.yaml `required`); everything else optional with legacy
 * defaults.
 */
class StoreClientRequest extends ClientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        $rules['company_name'] = ['required', 'string', 'max:64'];
        $rules['contact_name'] = ['required', 'string', 'max:64'];
        $rules['email'] = ['required', 'email', 'max:255'];
        $rules['username'] = ['required', 'string', 'min:1', 'max:64', 'regex:/^[\w\.\-]{1,64}$/', $this->usernameUniqueRule()];
        $rules['password'] = ['required', 'string', 'min:8', 'max:200'];

        return $rules;
    }
}
