<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesAssignedServer;
use Illuminate\Validation\Rule;

/**
 * PUT /dns/slaves/{id} (api/modules/dns/slave.yaml). Partial updates:
 * every field is optional.
 *
 * Client and reseller keys cannot move a secondary zone to another server
 * (spec 016 FR-008; legacy dns_slave_edit.php restores it for non-admins).
 */
class UpdateDnsSlaveRequest extends DnsSlaveRequest
{
    use ResolvesAssignedServer;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->commonRules();

        array_unshift($rules['origin'], 'sometimes');
        array_unshift($rules['ns'], 'sometimes');

        $rules['server_id'] = $this->immutableServerRules(
            fn (): ?int => $this->currentSlave() !== null ? (int) $this->currentSlave()->getRawOriginal('server_id') : null,
            [
                'sometimes',
                'integer',
                Rule::exists('server', 'server_id')
                    ->where('dns_server', 1)
                    ->where('mirror_server_id', 0),
            ]
        );

        return $rules;
    }
}
