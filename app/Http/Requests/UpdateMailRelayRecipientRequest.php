<?php

namespace App\Http\Requests;

/**
 * PUT /mail/relay-recipients/{id} (api/modules/mail/relay-recipients.yaml).
 *
 * source and server_id are immutable after creation; only access and active
 * can be updated.
 */
class UpdateMailRelayRecipientRequest extends MailRelayRecipientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentRelayRecipient()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'server_id', 'server')],
            'source' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'source', 'source')],
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

        unset($data['server_id'], $data['source']); // immutable

        return $data;
    }
}
