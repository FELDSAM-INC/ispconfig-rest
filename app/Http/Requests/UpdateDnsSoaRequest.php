<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesAssignedServer;
use Illuminate\Validation\Rule;

/**
 * PUT /dns/soa/{id} (api/modules/dns/soa.yaml).
 *
 * Partial updates: every field is optional ('sometimes'). Origin changes
 * stay possible (legacy allows admin renames); collisions with another
 * zone's origin are refused with 409 in the controller. Client-sent
 * `serial` values are ignored — the serial is server-managed and bumped
 * automatically on every effective update.
 *
 * Client and reseller keys cannot move a zone to another server (spec 016
 * FR-008; legacy dns_soa_edit.php restores the stored server_id for
 * non-admins, the API rejects instead). Admin keys may change it.
 */
class UpdateDnsSoaRequest extends DnsSoaRequest
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
        array_unshift($rules['mbox'], 'sometimes');

        $rules['server_id'] = $this->immutableServerRules(
            fn (): ?int => $this->currentZone() !== null ? (int) $this->currentZone()->getRawOriginal('server_id') : null,
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
