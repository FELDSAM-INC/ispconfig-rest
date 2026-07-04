<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /mail/spamfilter/wblist (api/modules/mail/spamfilter-wblist.yaml).
 */
class StoreSpamfilterWBListRequest extends SpamfilterWBListRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', $this->mailServerRule()],
            'wb' => ['sometimes', 'string', Rule::in(['W', 'B'])],
            'rid' => ['required', 'integer', 'min:0'],
            'email' => ['required', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
