<?php

namespace App\Http\Requests;

use App\Models\ServerIp;

/**
 * PUT /servers/{id}/ip-addresses/{ip_address_id}
 * (api/modules/server/ip-addresses.yaml).
 *
 * Partial updates. server_id is immutable — an IP cannot be moved between
 * servers (legacy server_ip_edit.php::onBeforeUpdate, 422). A changed
 * ip_address re-applies the per-type validation (422, against the
 * effective ip_type: request value or the stored record's) — the
 * uniqueness check (409) lives in ServerIpController.
 */
class UpdateServerIpRequest extends ServerModuleRequest
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
            'server_id' => ['sometimes', 'integer', $this->serverIdMatchesPathRule()],
            'ip_type' => ['sometimes', 'in:IPv4,IPv6'],
            'ip_address' => [
                'sometimes',
                'string',
                'filled',
                'max:39',
                $this->ipMatchesTypeRule(function (): string {
                    if ($this->has('ip_type')) {
                        return (string) $this->input('ip_type');
                    }

                    return (string) ($this->currentIp()?->getRawOriginal('ip_type') ?? 'IPv4');
                }),
            ],
            'virtualhost' => ['sometimes', 'boolean'],
            'virtualhost_port' => ['sometimes', 'string', 'max:255', 'regex:/^([0-9]{1,5}\,{0,1}){1,}$/i'],
            'client_id' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * The record being updated (scoped to the path's server).
     */
    protected function currentIp(): ?ServerIp
    {
        $server = $this->routeServer();

        if ($server === null) {
            return null;
        }

        return ServerIp::query()
            ->where('server_id', $server->getKey())
            ->find((int) $this->route('ipAddress'));
    }
}
