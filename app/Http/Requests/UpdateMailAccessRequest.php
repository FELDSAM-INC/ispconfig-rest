<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /mail/access-rules/{id} (api/modules/mail/access-rules.yaml).
 *
 * server_id is immutable after creation; the source + type combination must
 * remain unique per server (409, checked in the controller).
 */
class UpdateMailAccessRequest extends MailAccessRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentAccessRule()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'server_id', 'server')],
            'source' => ['sometimes', 'string', 'max:255'],
            'access' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['recipient', 'sender', 'client'])],
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

        return $data;
    }
}
