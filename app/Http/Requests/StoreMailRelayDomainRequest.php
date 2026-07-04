<?php

namespace App\Http\Requests;

/**
 * POST /mail/relay-domains (api/modules/mail/relay-domains.yaml).
 * The (domain, server_id) unique key is surfaced as 409 in the controller;
 * access defaults to 'OK' server-side (C-4).
 */
class StoreMailRelayDomainRequest extends MailRelayDomainRequest
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
            ],
            'access' => ['sometimes', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
