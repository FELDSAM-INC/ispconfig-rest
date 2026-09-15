<?php

namespace App\Http\Requests\Usage;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters of the traffic history endpoints
 * (api/modules/usage/web-domains.yaml and mail-users.yaml, spec 017 FR-010):
 * `months` 1–36 (default 12) and `granularity` month|day (default month).
 * Invalid values are 422 problem+json.
 */
class TrafficHistoryRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware; row access is
     * checked by the controller through the read predicate.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'months' => ['sometimes', 'integer', 'min:1', 'max:36'],
            'granularity' => ['sometimes', 'string', 'in:month,day'],
        ];
    }

    public function months(): int
    {
        return (int) ($this->validated()['months'] ?? 12);
    }

    public function granularity(): string
    {
        return (string) ($this->validated()['granularity'] ?? 'month');
    }
}
