<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /mail/content-filters/{id} (api/modules/mail/content-filters.yaml).
 *
 * server_id is immutable after creation; all other fields can be updated.
 */
class UpdateMailContentFilterRequest extends MailContentFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentContentFilter()?->getRawOriginal();

        return [
            'server_id' => ['sometimes', 'integer', $this->immutableAttributeRule($current, 'server_id', 'server')],
            'type' => ['sometimes', 'string', Rule::in(self::TYPES)],
            'pattern' => ['sometimes', 'string', 'max:255'],
            'data' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action' => ['sometimes', 'string', Rule::in(self::ACTIONS)],
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
