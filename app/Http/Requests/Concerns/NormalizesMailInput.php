<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared input normalization/validation helpers for the mail module
 * Form Requests, mirroring the legacy tform SAVE filters and datasource
 * constraints:
 *
 *  - IDNTOASCII + TOLOWER on email/domain/hostname inputs (FR-006);
 *  - y/n checkbox flags accept booleans as well as legacy 'y'/'n' strings
 *    (the API speaks booleans, YesNoBoolean stores y/n);
 *  - server_id restricted to actual mail servers (legacy datasource:
 *    mail_server = 1 AND mirror_server_id = 0, FR-036);
 *  - immutability rules that accept re-sending the current value
 *    (idempotent full-body PUTs), following UpdateMailDomainRequest.
 */
trait NormalizesMailInput
{
    /**
     * Legacy IDNTOASCII + TOLOWER for a whole value (domains/hostnames).
     */
    protected function idnLower(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value);

            if ($ascii !== false) {
                $value = $ascii;
            }
        }

        return strtolower($value);
    }

    /**
     * IDN-encode only the domain part of an email-shaped value (legacy
     * composes local_part@idn_encode(domain)).
     */
    protected function idnLowerEmail(string $value): string
    {
        $value = trim($value);

        if (! str_contains($value, '@')) {
            return $this->idnLower($value);
        }

        $local = strstr($value, '@', true);
        $domain = substr((string) strrchr($value, '@'), 1);

        return strtolower($local).'@'.$this->idnLower($domain);
    }

    /**
     * Merge normalized boolean values for legacy y/n checkbox flags.
     *
     * @param  array<int, string>  $flags
     * @return array<string, mixed>
     */
    protected function normalizeFlags(array $flags): array
    {
        $input = [];

        foreach ($flags as $flag) {
            if (! $this->has($flag) || ! is_string($this->input($flag))) {
                continue;
            }

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

        return $input;
    }

    /**
     * server_id must reference a mail server (FR-036).
     */
    protected function mailServerRule(): Exists
    {
        return Rule::exists('server', 'server_id')
            ->where('mail_server', 1)
            ->where('mirror_server_id', 0);
    }

    /**
     * Reject a value that differs from the record's current raw attribute
     * (re-sending the current value stays valid).
     *
     * @param  array<string, mixed>|null  $current  raw attributes of the bound record
     */
    protected function immutableAttributeRule(?array $current, string $attribute, string $label): Closure
    {
        return function (string $_attribute, mixed $value, Closure $fail) use ($current, $attribute, $label): void {
            if ($current === null || ! array_key_exists($attribute, $current)) {
                return;
            }

            $expected = $current[$attribute];

            $matches = (is_numeric($expected) && is_numeric($value))
                ? (int) $value === (int) $expected
                : $value === $expected;

            if (! $matches) {
                $fail("The {$label} cannot be changed after creation.");
            }
        };
    }
}
