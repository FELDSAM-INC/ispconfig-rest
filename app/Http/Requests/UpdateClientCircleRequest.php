<?php

namespace App\Http\Requests;

use App\Models\ClientCircle;
use Illuminate\Validation\Rule;

/**
 * PUT /clients/circles/{id} (api/modules/client/circles.yaml).
 * Partial updates; circle_name stays unique (ignoring the circle itself).
 */
class UpdateClientCircleRequest extends ClientCircleRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        $circle = $this->route('circle');
        $ignoreId = $circle instanceof ClientCircle ? $circle->getKey() : null;

        $rules['circle_name'] = [
            'sometimes',
            'string',
            'max:64',
            Rule::unique('client_circle', 'circle_name')->ignore($ignoreId, 'circle_id'),
        ];

        return $rules;
    }
}
