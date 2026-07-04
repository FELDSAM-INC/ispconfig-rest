<?php

namespace App\Http\Requests;

use App\Models\DnsSlave;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Shared behavior for slave-zone writes, mirroring the legacy form
 * (source_code/interface/web/dns/form/dns_slave.tform.php and
 * dns_slave_edit.php::onSubmit()):
 *
 *  - origin is IDN-encoded, lowercased and dot-terminated; the legacy
 *    origin regex allows '/' for classless reverse zones and a trailing
 *    dot;
 *  - ns (the master to transfer from) and xfer are validated as
 *    comma-separated IP addresses (legacy ISIP validators);
 *  - a primary zone (dns_soa) with the same origin + server refuses the
 *    slave (legacy onSubmit check) as 422; the dns_slave
 *    (origin, server_id) UNIQUE key is a 409 in the controller.
 */
abstract class DnsSlaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->has('origin') && is_string($this->input('origin'))) {
            $origin = trim($this->input('origin'));

            if ($origin !== '' && function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($origin);
                if ($ascii !== false) {
                    $origin = $ascii;
                }
            }

            $origin = strtolower($origin);

            // Legacy onSubmit: "Check if the zone name has a dot at the end".
            if ($origin !== '' && ! str_ends_with($origin, '.')) {
                $origin .= '.';
            }

            $input['origin'] = $origin;
        }

        if ($this->has('active') && is_string($this->input('active'))) {
            $value = filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value === null) {
                $value = match (strtolower($this->input('active'))) {
                    'y' => true,
                    'n' => false,
                    default => $this->input('active'),
                };
            }

            $input['active'] = $value;
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * Legacy dns_slave_edit.php::onSubmit — a primary zone with the same
     * origin on the same server refuses the slave zone.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $current = $this->currentSlave()?->getRawOriginal() ?? [];
                $origin = $this->input('origin', $current['origin'] ?? null);
                $serverId = $this->input('server_id', $current['server_id'] ?? null);

                if ($origin === null || $serverId === null) {
                    return;
                }

                $collision = DB::table('dns_soa')
                    ->where('origin', $origin)
                    ->where('server_id', (int) $serverId)
                    ->exists();

                if ($collision) {
                    $validator->errors()->add('origin', 'A primary zone with this origin already exists on the selected server.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('xfer', $data) && $data['xfer'] === null) {
            $data['xfer'] = '';
        }

        return $data;
    }

    protected function currentSlave(): ?DnsSlave
    {
        $slave = $this->route('dnsSlave');

        return $slave instanceof DnsSlave ? $slave : null;
    }

    /**
     * Legacy ISIP validator: comma-separated IPv4/IPv6 addresses.
     */
    protected function ipListRule(bool $allowEmpty): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowEmpty): void {
            if (! is_string($value) || $value === '') {
                if (! $allowEmpty && $value === '') {
                    $fail('The :attribute must contain at least one IP address.');
                }

                return;
            }

            foreach (explode(',', $value) as $entry) {
                if (filter_var(trim($entry), FILTER_VALIDATE_IP) === false) {
                    $fail("The :attribute must be a comma-separated list of IP addresses (invalid entry: {$entry}).");

                    return;
                }
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        return [
            // Legacy dns_slave.tform origin regex, byte-identical: allows
            // '/' (classless reverse zones) and an optional trailing dot.
            'origin' => ['string', 'max:255', 'regex:/^[a-zA-Z0-9\.\-\/]{1,255}\.[a-zA-Z0-9\-]{2,63}[\.]{0,1}$/'],
            'ns' => ['string', 'max:255', $this->ipListRule(allowEmpty: false)],
            'active' => ['sometimes', 'boolean'],
            'xfer' => ['sometimes', 'nullable', 'string', 'max:255', $this->ipListRule(allowEmpty: true)],
            'sys_groupid' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
