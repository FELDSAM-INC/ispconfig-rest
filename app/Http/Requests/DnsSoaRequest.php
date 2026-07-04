<?php

namespace App\Http\Requests;

use App\Models\DnsSoa;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Shared behavior for DNS zone writes, mirroring the legacy form
 * (source_code/interface/web/dns/form/dns_soa.tform.php and
 * dns_soa_edit.php::onSubmit()):
 *
 *  - origin/ns/mbox are IDN-encoded, lowercased and dot-terminated;
 *    '@' in mbox is replaced with '.' (legacy SAVE filters + onSubmit);
 *  - xfer/also_notify have all whitespace stripped and are validated as
 *    comma-separated IPs (legacy validate_dns::validate_ip — 'any' is
 *    allowed for xfer, never for also_notify);
 *  - a same-named secondary zone on the same server is rejected
 *    (legacy onSubmit's dns_slave collision check);
 *  - the boolean flags accept true/false as well as legacy 'Y'/'N' strings.
 *
 * `serial` is server-managed and never validated: client-sent values are
 * ignored (contract).
 */
abstract class DnsSoaRequest extends FormRequest
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
     * Normalize input before validation (legacy SAVE filters + onSubmit).
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        foreach (['origin', 'ns', 'mbox'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = trim($this->input($field));

                // Legacy IDNTOASCII + TOLOWER filters.
                if ($value !== '' && function_exists('idn_to_ascii')) {
                    $ascii = idn_to_ascii($value);
                    if ($ascii !== false) {
                        $value = $ascii;
                    }
                }

                $value = strtolower($value);

                // Legacy onSubmit: replace '@' in mbox with '.'.
                if ($field === 'mbox' && str_contains($value, '@')) {
                    $value = str_replace('@', '.', $value);
                }

                // Legacy onSubmit: origin, ns and mbox are dot-terminated.
                if ($value !== '' && ! str_ends_with($value, '.')) {
                    $value .= '.';
                }

                $input[$field] = $value;
            }
        }

        // Legacy onSubmit strips all whitespace from the IP lists.
        foreach (['xfer', 'also_notify'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $input[$field] = preg_replace('/\s+/', '', $this->input($field));
            }
        }

        foreach (['active', 'dnssec_wanted'] as $flag) {
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
     * Legacy onSubmit: "Check if a secondary zone with the same name already
     * exists" — a dns_slave row with the same origin + server refuses the
     * zone (422, distinct from the dns_soa origin UNIQUE which is a 409).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $current = $this->currentZone()?->getRawOriginal() ?? [];
                $origin = $this->input('origin', $current['origin'] ?? null);
                $serverId = $this->input('server_id', $current['server_id'] ?? null);

                if ($origin === null || $serverId === null) {
                    return;
                }

                $collision = DB::table('dns_slave')
                    ->where('origin', $origin)
                    ->where('server_id', (int) $serverId)
                    ->exists();

                if ($collision) {
                    $validator->errors()->add('origin', 'A secondary (slave) zone with this origin already exists on the selected server.');
                }
            },
        ];
    }

    /**
     * Validated data ready for DnsSoa::fill(): nullable text inputs mapped
     * to the columns' legacy '' defaults.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['xfer', 'also_notify', 'update_acl'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        return $data;
    }

    /**
     * The route-bound zone being updated (null on store).
     */
    protected function currentZone(): ?DnsSoa
    {
        $zone = $this->route('dnsSoa');

        return $zone instanceof DnsSoa ? $zone : null;
    }

    /**
     * Legacy validate_dns::validate_ip — a (comma-separated) list of IPv4/
     * IPv6 addresses with optional /prefix ranges; empty allowed here
     * because the columns are optional. 'any' is accepted except for
     * also_notify (legacy special case).
     */
    protected function ipListRule(bool $allowAny): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowAny): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if ($allowAny && $value === 'any') {
                return;
            }

            foreach (explode(',', $value) as $entry) {
                $ip = trim($entry);
                $prefix = null;

                if (str_contains($ip, '/')) {
                    [$ip, $prefix] = array_map('trim', explode('/', $ip, 2));
                }

                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $fail("The :attribute must be a comma-separated list of IP addresses (invalid entry: {$entry}).");

                    return;
                }

                if ($prefix !== null) {
                    $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

                    if (! is_numeric($prefix) || (int) $prefix < 1 || (int) $prefix > $max) {
                        $fail("The :attribute contains an invalid prefix length: {$entry}.");

                        return;
                    }
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
            // Contract pattern (DnsSoa.yaml), validated after normalization.
            'origin' => ['string', 'max:255', 'regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}\.?$/'],
            // Legacy dns_soa.tform ns regex (dot-terminated values pass).
            'ns' => ['string', 'max:255', 'regex:/^[a-zA-Z0-9\.\-]{1,255}$/'],
            // Legacy dns_soa.tform mbox regex (requires the trailing dot).
            'mbox' => ['string', 'max:255', 'regex:/^[a-zA-Z0-9\.\-\_\+]{0,255}\.$/'],
            'refresh' => ['sometimes', 'integer', 'min:60', 'max:4294967295'],
            'retry' => ['sometimes', 'integer', 'min:60', 'max:4294967295'],
            'expire' => ['sometimes', 'integer', 'min:60', 'max:4294967295'],
            'minimum' => ['sometimes', 'integer', 'min:60', 'max:4294967295'],
            'ttl' => ['sometimes', 'integer', 'min:60', 'max:4294967295'],
            'active' => ['sometimes', 'boolean'],
            'xfer' => ['sometimes', 'nullable', 'string', 'max:255', $this->ipListRule(allowAny: true)],
            'also_notify' => ['sometimes', 'nullable', 'string', 'max:255', $this->ipListRule(allowAny: false)],
            'update_acl' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dnssec_wanted' => ['sometimes', 'boolean'],
            'dnssec_algo' => ['sometimes', 'string', 'in:NSEC3RSASHA1,ECDSAP256SHA256'],
            'sys_groupid' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
