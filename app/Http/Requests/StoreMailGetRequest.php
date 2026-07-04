<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /mail/fetchmail (api/modules/mail/fetchmail.yaml).
 */
class StoreMailGetRequest extends MailGetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', $this->mailServerRule()],
            'type' => ['required', 'string', Rule::in(['pop3', 'imap', 'pop3ssl', 'imapssl'])],
            'source_server' => ['required', 'string', 'max:255', 'regex:'.self::SOURCE_SERVER_REGEX],
            'source_username' => ['required', 'string', 'max:255'],
            'source_password' => ['required', 'string', 'max:64'],
            'source_delete' => ['sometimes', 'boolean'],
            'source_read_all' => ['sometimes', 'boolean'],
            'destination' => ['required', 'string', 'max:255', 'email:rfc', $this->existingMailboxRule()],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
