<?php

namespace App\Http\Requests;

/**
 * PUT /mail/forwards/{id} (api/modules/mail/forwards.yaml).
 *
 * source and type are immutable after creation (contract); only destination,
 * active, allow_send_as and greylisting can be updated.
 */
class UpdateMailForwardingRequest extends MailForwardingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentForwarding()?->getRawOriginal();

        return [
            'type' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'type', 'type')],
            'source' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'source', 'source')],
            'destination' => ['sometimes', 'string', $this->destinationListRule()],
            'active' => ['sometimes', 'boolean'],
            'allow_send_as' => ['sometimes', 'boolean'],
            'greylisting' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        unset($data['source'], $data['type']); // immutable

        return $data;
    }
}
