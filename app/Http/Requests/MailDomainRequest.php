<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for mail-domain writes, mirroring the legacy form
 * (source_code/interface/web/mail/form/mail_domain.tform.php and
 * mail_domain_edit.php::onSubmit()):
 *
 *  - domain is IDN-encoded (IDNTOASCII) and lowercased (TOLOWER) before
 *    validation, as promised by the MailDomain schema description;
 *  - the y/n flags accept booleans as well as legacy 'y'/'n' strings
 *    (constitution: the API speaks booleans, YesNoBoolean stores y/n);
 *  - the DKIM private key must parse via openssl (legacy validate_dkim).
 */
abstract class MailDomainRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware; per-record
     * sys_perm_* enforcement is out of scope (spec 003 assumption).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation (legacy SAVE filters).
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->has('domain') && is_string($this->input('domain'))) {
            $domain = trim($this->input('domain'));

            // Legacy IDNTOASCII filter (idn_encode in mail_domain_edit.php).
            if ($domain !== '' && function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($domain);
                if ($ascii !== false) {
                    $domain = $ascii;
                }
            }

            // Legacy TOLOWER filter.
            $input['domain'] = strtolower($domain);
        }

        foreach (['dkim', 'active', 'local_delivery'] as $flag) {
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
     * Validated data ready for MailDomain::fill(): nullable inputs mapped
     * to the columns' NOT NULL '' defaults, dkim_selector falling back to
     * the legacy 'default'.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['relay_host', 'relay_user', 'relay_pass'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        if (array_key_exists('dkim_selector', $data) && ($data['dkim_selector'] === null || $data['dkim_selector'] === '')) {
            $data['dkim_selector'] = 'default';
        }

        return $data;
    }

    /**
     * Legacy ISDOMAIN validator: filter_var('check@'.$domain, FILTER_VALIDATE_EMAIL)
     * (tform_base.inc.php), on top of the documented domain regex.
     */
    protected function domainFormatRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || filter_var('check@'.$value, FILTER_VALIDATE_EMAIL) === false) {
                $fail('The :attribute must be a valid domain name.');
            }
        };
    }

    /**
     * Legacy validate_dkim::check_private_key — the key must be parseable.
     */
    protected function dkimPrivateKeyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && openssl_pkey_get_private($value) === false) {
                $fail('The :attribute must be a valid PEM-encoded private key.');
            }
        };
    }
}
