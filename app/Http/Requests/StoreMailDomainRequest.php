<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /mail/domains (api/modules/mail/domains.yaml).
 *
 * Mirrors legacy mail_domain.tform.php validation and the contract's rules:
 * unique domain, DKIM private key required (and parseable) when DKIM is
 * enabled, relay credential chain, server restricted to actual mail servers
 * (legacy datasource: mail_server = 1 AND mirror_server_id = 0).
 */
class StoreMailDomainRequest extends MailDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => [
                'required',
                'integer',
                Rule::exists('server', 'server_id')
                    ->where('mail_server', 1)
                    ->where('mirror_server_id', 0),
            ],
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}[\.]{0,1}$/',
                $this->domainFormatRule(),
                // Application-level uniqueness, mirroring the legacy check
                // (contract: unique across all mail domains).
                Rule::unique('mail_domain', 'domain'),
            ],
            'dkim' => ['sometimes', 'boolean'],
            'dkim_private' => [
                Rule::requiredIf(fn (): bool => $this->boolean('dkim')),
                'nullable',
                'string',
                $this->dkimPrivateKeyRule(),
            ],
            'dkim_selector' => [
                'sometimes',
                'nullable',
                'string',
                'max:63', // DB column varchar(63); contract enforces the DB limit
                'regex:/^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/',
            ],
            'relay_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'relay_user' => ['required_with:relay_host', 'nullable', 'string', 'max:255'],
            'relay_pass' => ['required_with:relay_user', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'local_delivery' => ['sometimes', 'boolean'],
        ];
    }
}
