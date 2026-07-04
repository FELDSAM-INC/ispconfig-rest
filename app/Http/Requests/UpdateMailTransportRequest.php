<?php

namespace App\Http\Requests;

/**
 * PUT /mail/transports/{id} (api/modules/mail/transports.yaml).
 *
 * domain and server_id are immutable after creation (legacy reverts
 * server_id changes: mail_transport_edit.php:123-127); only transport,
 * sort_order and active can be updated.
 */
class UpdateMailTransportRequest extends MailTransportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentTransport()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'server_id', 'server')],
            'domain' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'domain', 'domain')],
            'transport' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10'],
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
