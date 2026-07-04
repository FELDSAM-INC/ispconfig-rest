<?php

namespace App\Http\Requests;

/**
 * POST /servers/{id}/ip-mappings (api/modules/server/ip-mappings.yaml).
 *
 * Mirrors legacy server_ip_map.tform.php: source_ip NOTEMPTY (<= 15 chars,
 * the column is IPv4-sized), destination_ip ISIPV4 + NOTEMPTY (IPv6
 * destinations are rejected with 422), active y/n default y.
 */
class StoreServerIpMapRequest extends ServerModuleRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeYesNoFlags(['active']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_ip' => ['required', 'string', 'filled', 'max:15'],
            'destination_ip' => ['required', 'string', 'filled', 'ipv4', 'max:35'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
