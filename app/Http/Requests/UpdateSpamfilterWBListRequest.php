<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /mail/spamfilter/wblist/{id} (api/modules/mail/spamfilter-wblist.yaml).
 *
 * email and rid are immutable after creation.
 */
class UpdateSpamfilterWBListRequest extends SpamfilterWBListRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentWBList()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->mailServerRule()],
            'wb' => ['sometimes', 'string', Rule::in(['W', 'B'])],
            'rid' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'rid', 'recipient reference (rid)')],
            'email' => ['sometimes', 'string', $this->immutableAttributeRule($current, 'email', 'email')],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        unset($data['email'], $data['rid']); // immutable

        return $data;
    }
}
