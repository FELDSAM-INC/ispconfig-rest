<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Only the sender and rule settings are accepted; recipient and server come from the URL. */
class StoreMailRecipientWBListRequest extends SpamfilterWBListRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'wb' => ['required', Rule::in(['W', 'B'])],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'active' => ['sometimes', 'boolean'],
            'rid' => ['prohibited'],
            'server_id' => ['prohibited'],
        ];
    }
}
