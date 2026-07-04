<?php

namespace App\Http\Requests;

use App\Models\DnsTemplate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for DNS template writes, mirroring the legacy form
 * (source_code/interface/web/dns/form/dns_template.tform.php):
 *
 *  - `name` is NOTEMPTY in legacy and required by the contract; the DB
 *    column is varchar(64), so max:64 applies (spec 002 gap G09 — the old
 *    max:255 let 65–255 char names die at the DB layer as a 500);
 *  - `fields` is the comma-separated placeholder list; each token must be
 *    in the legacy $field_values whitelist (DOMAIN, IP, IPV6, NS1, NS2,
 *    EMAIL, DKIM, DNSSEC) — tokens are upper-cased before validation, as
 *    the legacy CHECKBOXARRAY only ever stores uppercase values;
 *  - `visible` accepts booleans as well as legacy 'Y'/'N' strings.
 */
abstract class DnsTemplateRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware; per-record
     * sys_perm_* enforcement is out of scope (spec 002 assumption).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation.
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        // Legacy CHECKBOXARRAY values are uppercase tokens — normalize so
        // the whitelist check matches the storage format.
        if ($this->has('fields') && is_string($this->input('fields'))) {
            $tokens = preg_split('/\s*,\s*/', trim($this->input('fields')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $input['fields'] = strtoupper(implode(',', $tokens));
        }

        if ($this->has('visible') && is_string($this->input('visible'))) {
            $value = filter_var($this->input('visible'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value === null) {
                $value = match (strtolower($this->input('visible'))) {
                    'y' => true,
                    'n' => false,
                    default => $this->input('visible'), // left invalid -> boolean rule fails
                };
            }

            $input['visible'] = $value;
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * Validated data ready for DnsTemplate::fill().
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * Every comma-separated token must be an allowed placeholder (legacy
     * dns_template.tform.php $field_values; spec 002 FR-010).
     */
    protected function fieldsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            foreach (explode(',', $value) as $token) {
                if (! in_array($token, DnsTemplate::ALLOWED_FIELDS, true)) {
                    $fail(sprintf(
                        "The :attribute contains an invalid placeholder '%s'. Allowed values: %s.",
                        $token,
                        implode(', ', DnsTemplate::ALLOWED_FIELDS)
                    ));

                    return;
                }
            }
        };
    }

    /**
     * Shared column rules (store makes the core fields required, update
     * relaxes them to 'sometimes').
     *
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        return [
            'name' => ['string', 'max:64'],
            'fields' => ['string', 'max:255', $this->fieldsRule()],
            'template' => ['string', 'max:65535'],
            'visible' => ['sometimes', 'boolean'],
            'sys_groupid' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
