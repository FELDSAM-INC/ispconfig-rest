<?php

namespace App\Http\Requests;

use App\Rules\InstallationPassword;

/**
 * POST /clients (api/modules/client/clients.yaml).
 *
 * Contract-required fields: contact_name, email, username, password
 * (Client.yaml `required`); everything else optional with legacy defaults.
 * company_name is optional like legacy client.tform.php (no validator) and
 * is stored as an empty string when omitted.
 */
class StoreClientRequest extends ClientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        $rules['contact_name'] = ['required', 'string', 'max:64'];
        $rules['email'] = ['required', 'email', 'max:255'];
        $rules['username'] = ['required', 'string', 'min:1', 'max:64', 'regex:/^[\w\.\-]{1,64}$/', $this->usernameUniqueRule()];
        $rules['password'] = ['required', 'string', 'max:200', new InstallationPassword];

        return $rules;
    }
}
