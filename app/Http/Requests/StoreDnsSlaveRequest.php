<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesAssignedServer;
use Illuminate\Validation\Rule;

/**
 * POST /dns/slaves (api/modules/dns/slave.yaml).
 *
 * Server selection (spec 016): client and reseller keys always use the
 * account's secondary DNS server (legacy dns_slave_edit.php:182-193 forces
 * client.default_slave_dnsserver); it is merged before the after()
 * primary-zone collision check and the controller's unique-origin guard run.
 */
class StoreDnsSlaveRequest extends DnsSlaveRequest
{
    use ResolvesAssignedServer;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->mergeSlaveDnsServerDefault();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->slaveDnsServerMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['origin'], 'required');
        array_unshift($rules['ns'], 'required');

        $rules['server_id'] = $this->slaveDnsServerRules([
            'required',
            'integer',
            Rule::exists('server', 'server_id')
                ->where('dns_server', 1)
                ->where('mirror_server_id', 0),
        ]);

        // Optional owning client (resolved to its sys_group on create).
        $rules['client_id'] = ['sometimes', 'integer', Rule::exists('client', 'client_id')];

        return $rules;
    }
}
