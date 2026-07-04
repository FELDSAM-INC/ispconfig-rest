<?php

namespace App\Http\Requests;

/**
 * POST /mail/transports (api/modules/mail/transports.yaml).
 * The (server_id, domain) unique key is surfaced as 409 in the controller.
 */
class StoreMailTransportRequest extends MailTransportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', $this->mailServerRule()],
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9](?:\.[a-zA-Z]{2,})+$/',
                $this->notMailDomainRule(),
            ],
            'transport' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
