<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for client-circle writes (contract:
 * api/components/schemas/ClientCircle.yaml; legacy:
 * source_code/interface/web/client/form/client_circle.tform.php).
 *
 * client_ids is a comma-separated id list; whether every id actually exists
 * is checked in the controller (400 per spec 001, "Invalid client IDs").
 * Requiring circle_name/client_ids is stricter than legacy (accepted
 * deviation, spec 001 US6.5).
 */
abstract class ClientCircleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('active') && is_string($this->input('active'))) {
            $value = filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value === null) {
                $value = match (strtolower($this->input('active'))) {
                    'y' => true,
                    'n' => false,
                    default => $this->input('active'),
                };
            }

            $this->merge(['active' => $value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'circle_name' => ['sometimes', 'string', 'max:64'],
            'client_ids' => ['sometimes', 'string', 'regex:/^\d+(\s*,\s*\d+)*$/'],
            'description' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
