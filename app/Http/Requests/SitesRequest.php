<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for all sites-module write requests:
 *
 *  - y/n flag inputs accept booleans as well as legacy 'y'/'n' strings
 *    (the API speaks booleans, YesNoBoolean casts store y/n);
 *  - domain inputs are trimmed, IDN-encoded and lower-cased (legacy
 *    IDNTOASCII + TOLOWER save filters);
 *  - helpers for the recurring legacy rules (quota regex
 *    /^(\-1|[0-9]{1,10})$/, path-traversal rejection, immutable fields).
 */
abstract class SitesRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware; per-record
     * sys_perm_* enforcement is out of scope (admin-scoped API key).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * y/n flags this request accepts — normalized to booleans before
     * validation.
     *
     * @return array<int, string>
     */
    protected function booleanFields(): array
    {
        return [];
    }

    /**
     * Whether this request carries a `domain` field to normalize.
     */
    protected function normalizesDomain(): bool
    {
        return false;
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->normalizesDomain() && $this->has('domain') && is_string($this->input('domain'))) {
            $domain = trim($this->input('domain'));

            if ($domain !== '' && function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($domain);
                if ($ascii !== false) {
                    $domain = $ascii;
                }
            }

            $input['domain'] = strtolower($domain);
        }

        foreach ($this->booleanFields() as $flag) {
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
     * Validated data ready for Model::fill().
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * Legacy quota rule /^(\-1|[0-9]{1,10})$/ — -1 (unlimited) or a
     * positive integer up to 10 digits.
     *
     * @return array<int, mixed>
     */
    protected function quotaRules(): array
    {
        return ['integer', 'min:-1', 'max:9999999999'];
    }

    /**
     * Legacy path-traversal check (ftp/shell/webdav `dir` fields): reject
     * values containing '..' or './' (case-insensitive stristr in legacy).
     */
    protected function noPathTraversalRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && (stristr($value, '..') !== false || stristr($value, './') !== false)) {
                $fail('The :attribute must not contain ".." or "./".');
            }
        };
    }

    /**
     * Reject a value that differs from the record's current one
     * (idempotent full-body PUTs still pass).
     *
     * @param  Closure(): mixed  $currentValue
     */
    protected function immutableRule(Closure $currentValue, string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($currentValue, $label): void {
            $expected = $currentValue();

            if ($expected === null) {
                return;
            }

            $given = is_int($expected) ? (int) $value : $value;

            if ($given !== $expected) {
                $fail("The {$label} cannot be changed after creation.");
            }
        };
    }
}
