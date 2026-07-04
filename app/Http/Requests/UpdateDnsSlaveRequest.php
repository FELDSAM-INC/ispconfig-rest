<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /dns/slaves/{id} (api/modules/dns/slave.yaml). Partial updates:
 * every field is optional.
 */
class UpdateDnsSlaveRequest extends DnsSlaveRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['origin'], 'sometimes');
        array_unshift($rules['ns'], 'sometimes');

        $rules['server_id'] = [
            'sometimes',
            'integer',
            Rule::exists('server', 'server_id')
                ->where('dns_server', 1)
                ->where('mirror_server_id', 0),
        ];

        return $rules;
    }
}
