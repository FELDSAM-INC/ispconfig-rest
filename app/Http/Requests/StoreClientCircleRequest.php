<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /clients/circles (api/modules/client/circles.yaml).
 * circle_name and client_ids are required (contract `required`); the name
 * must be unique (422 — the circles contract declares no 409).
 */
class StoreClientCircleRequest extends ClientCircleRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        $rules['circle_name'] = ['required', 'string', 'max:64', Rule::unique('client_circle', 'circle_name')];
        $rules['client_ids'] = ['required', 'string', 'regex:/^\d+(\s*,\s*\d+)*$/'];

        return $rules;
    }
}
