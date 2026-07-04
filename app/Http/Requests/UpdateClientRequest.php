<?php

namespace App\Http\Requests;

/**
 * PUT /clients/{id} (api/modules/client/clients.yaml).
 *
 * Partial updates: every field optional. An empty password means
 * "no change" (legacy tform skips empty PASSWORD fields), so it is dropped
 * before validation.
 */
class UpdateClientRequest extends ClientRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->has('password') && blank($this->input('password'))) {
            // Drop it from the actual input source — JSON requests read from
            // the json bag, form-encoded requests from the request bag.
            $this->getInputSource()->remove('password');
            $this->request->remove('password');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        $rules['password'] = ['sometimes', 'string', 'min:8', 'max:200'];

        return $rules;
    }
}
