<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesAssignedServer;
use Illuminate\Validation\Rule;

/**
 * POST /dns/soa (api/modules/dns/soa.yaml).
 *
 * server_id is restricted to actual primary DNS servers, mirroring the
 * legacy datasource (dns_soa.tform.php: dns_server = 1 AND
 * mirror_server_id = 0). The dns_soa origin UNIQUE key is enforced as a 409
 * in the controller (contract), not here.
 *
 * Server selection (spec 016): client and reseller keys get the account's
 * first assigned DNS server when server_id is omitted and may only use
 * assigned DNS servers (legacy dns_soa_edit.php:155-190); the default is
 * merged before the after() origin-collision check runs.
 */
class StoreDnsSoaRequest extends DnsSoaRequest
{
    use ResolvesAssignedServer;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->mergeAssignedServerDefault('dns');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->assignedServerMessages('dns');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['origin'], 'required');
        array_unshift($rules['ns'], 'required');
        array_unshift($rules['mbox'], 'required');

        $rules['server_id'] = $this->assignedServerRules('dns', [
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
