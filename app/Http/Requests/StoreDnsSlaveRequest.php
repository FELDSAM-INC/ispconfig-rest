<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /dns/slaves (api/modules/dns/slave.yaml).
 */
class StoreDnsSlaveRequest extends DnsSlaveRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['origin'], 'required');
        array_unshift($rules['ns'], 'required');

        $rules['server_id'] = [
            'required',
            'integer',
            Rule::exists('server', 'server_id')
                ->where('dns_server', 1)
                ->where('mirror_server_id', 0),
        ];

        // Optional owning client (resolved to its sys_group on create).
        $rules['client_id'] = ['sometimes', 'integer', Rule::exists('client', 'client_id')];

        return $rules;
    }
}
