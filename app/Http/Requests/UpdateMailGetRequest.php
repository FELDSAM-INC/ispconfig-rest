<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /mail/fetchmail/{id} (api/modules/mail/fetchmail.yaml).
 *
 * server_id is immutable after creation; source_password is only re-set
 * when a non-empty value is provided.
 */
class UpdateMailGetRequest extends MailGetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentMailGet()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'server_id', 'server')],
            'type' => ['sometimes', 'string', Rule::in(['pop3', 'imap', 'pop3ssl', 'imapssl'])],
            'source_server' => ['sometimes', 'string', 'max:255', 'regex:'.self::SOURCE_SERVER_REGEX],
            'source_username' => ['sometimes', 'string', 'max:255'],
            'source_password' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source_delete' => ['sometimes', 'boolean'],
            'source_read_all' => ['sometimes', 'boolean'],
            'destination' => ['sometimes', 'string', 'max:255', 'email:rfc', $this->existingMailboxRule()],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        unset($data['server_id']); // immutable

        // Empty password = unchanged (contract: only re-set when provided).
        if (array_key_exists('source_password', $data) && ($data['source_password'] === null || $data['source_password'] === '')) {
            unset($data['source_password']);
        }

        return $data;
    }
}
