<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for DNS CA (dns_ssl_ca CAA policy record) writes
 * (contract: api/components/schemas/DnsCaConfig.yaml).
 *
 * The API speaks booleans for active/ca_wildcard/ca_critical; legacy
 * 'Y'/'N' strings are accepted too (UpperYesNo storage handled by the
 * model's casts). The ca_issue uniqueness is a 409, enforced in the
 * controller against the dns_ssl_ca UNIQUE KEY.
 */
abstract class DnsCaRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize y/n flag strings to booleans before validation.
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        foreach (['ca_wildcard', 'ca_critical', 'active'] as $flag) {
            if ($this->has($flag) && is_string($this->input($flag))) {
                $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value === null) {
                    $value = match (strtolower($this->input($flag))) {
                        'y' => true,
                        'n' => false,
                        default => $this->input($flag), // left invalid -> boolean rule fails
                    };
                }

                $input[$flag] = $value;
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * Validated data ready for DnsSslCa::fill(): nullable ca_iodef mapped to
     * the column's NOT NULL '' default.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('ca_iodef', $data) && $data['ca_iodef'] === null) {
            $data['ca_iodef'] = '';
        }

        return $data;
    }
}
