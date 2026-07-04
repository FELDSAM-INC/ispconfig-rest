<?php

namespace App\Http\Requests;

/**
 * POST /servers/{id}/ip-addresses (api/modules/server/ip-addresses.yaml).
 *
 * Mirrors legacy server_ip.tform.php: ip_address validated against the
 * declared ip_type (validate_server::check_server_ip -> 422 on mismatch);
 * virtualhost_port regex /^([0-9]{1,5}\,{0,1}){1,}$/i. The table-wide
 * ip_address uniqueness (409) and the client_id reference check (400) are
 * enforced in ServerIpController per the contract's status codes.
 */
class StoreServerIpRequest extends ServerModuleRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeYesNoFlags(['virtualhost']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ip_type' => ['sometimes', 'in:IPv4,IPv6'],
            'ip_address' => [
                'required',
                'string',
                'max:39',
                $this->ipMatchesTypeRule(fn (): string => (string) $this->input('ip_type', 'IPv4')),
            ],
            'virtualhost' => ['sometimes', 'boolean'],
            'virtualhost_port' => ['sometimes', 'string', 'max:255', 'regex:/^([0-9]{1,5}\,{0,1}){1,}$/i'],
            'client_id' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
