<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /dns/soa (api/modules/dns/soa.yaml).
 *
 * server_id is restricted to actual primary DNS servers, mirroring the
 * legacy datasource (dns_soa.tform.php: dns_server = 1 AND
 * mirror_server_id = 0). The dns_soa origin UNIQUE key is enforced as a 409
 * in the controller (contract), not here.
 */
class StoreDnsSoaRequest extends DnsSoaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['origin'], 'required');
        array_unshift($rules['ns'], 'required');
        array_unshift($rules['mbox'], 'required');

        $rules['server_id'] = [
            'required',
            'integer',
            Rule::exists('server', 'server_id')
                ->where('dns_server', 1)
                ->where('mirror_server_id', 0),
        ];

        return $rules;
    }
}
