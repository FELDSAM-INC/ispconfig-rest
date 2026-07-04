<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /mail/content-filters (api/modules/mail/content-filters.yaml).
 */
class StoreMailContentFilterRequest extends MailContentFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', $this->mailServerRule()],
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'pattern' => ['required', 'string', 'max:255'],
            'data' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
