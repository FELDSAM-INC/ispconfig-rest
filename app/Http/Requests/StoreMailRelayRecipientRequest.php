<?php

namespace App\Http\Requests;

/**
 * POST /mail/relay-recipients (api/modules/mail/relay-recipients.yaml).
 * access defaults to 'OK', active to y server-side (C-4).
 */
class StoreMailRelayRecipientRequest extends MailRelayRecipientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', $this->mailServerRule()],
            'source' => ['required', 'string', 'max:255', 'regex:'.self::SOURCE_REGEX],
            'access' => ['sometimes', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
