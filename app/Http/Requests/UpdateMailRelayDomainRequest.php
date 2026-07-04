<?php

namespace App\Http\Requests;

/**
 * PUT /mail/relay-domains/{id} (api/modules/mail/relay-domains.yaml).
 *
 * domain is immutable after creation; only access and active can be updated.
 */
class UpdateMailRelayDomainRequest extends MailRelayDomainRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentRelayDomain()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'server_id', 'server')],
            'domain' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'domain', 'domain')],
            'access' => ['sometimes', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        unset($data['server_id'], $data['domain']); // immutable

        return $data;
    }
}
