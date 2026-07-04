<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /mail/access-rules (api/modules/mail/access-rules.yaml).
 * The (server_id, source, type) unique key is surfaced as 409 in the
 * controller; access defaults to 'REJECT', type to 'recipient'.
 */
class StoreMailAccessRequest extends MailAccessRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', $this->mailServerRule()],
            'source' => ['required', 'string', 'max:255'],
            'access' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['recipient', 'sender', 'client'])],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
