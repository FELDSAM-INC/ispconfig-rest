<?php

namespace App\Http\Requests;

/**
 * PUT /servers/{id}/ip-mappings/{mapping_id}
 * (api/modules/server/ip-mappings.yaml).
 *
 * Partial updates with the same rules as create; server_id is taken from
 * the path and cannot be changed (422 on a differing body value).
 */
class UpdateServerIpMapRequest extends ServerModuleRequest
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
            'server_id' => ['sometimes', 'integer', $this->serverIdMatchesPathRule()],
            'source_ip' => ['sometimes', 'string', 'filled', 'max:15'],
            'destination_ip' => ['sometimes', 'string', 'filled', 'ipv4', 'max:35'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
