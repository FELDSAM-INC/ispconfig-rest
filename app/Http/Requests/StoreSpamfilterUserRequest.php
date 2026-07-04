<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /mail/spamfilter/users (api/modules/mail/spamfilter-users.yaml).
 * The email unique key is surfaced as 409 in the controller.
 */
class StoreSpamfilterUserRequest extends SpamfilterUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', $this->mailServerRule()],
            'policy_id' => ['required', 'integer', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'email' => ['required', 'string', 'max:255'],
            'fullname' => ['required', 'string', 'max:64'],
            'local' => ['sometimes', 'string', Rule::in(['Y', 'N'])],
        ];
    }
}
