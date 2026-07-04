<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /mail/spamfilter/users/{id} (api/modules/mail/spamfilter-users.yaml).
 *
 * email and server_id are immutable after creation.
 */
class UpdateSpamfilterUserRequest extends SpamfilterUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentSpamfilterUser()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'server_id', 'server')],
            'email' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'email', 'email')],
            'policy_id' => ['sometimes', 'integer', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'fullname' => ['sometimes', 'string', 'max:64'],
            'local' => ['sometimes', 'string', Rule::in(['Y', 'N'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        unset($data['server_id'], $data['email']); // immutable

        return $data;
    }
}
