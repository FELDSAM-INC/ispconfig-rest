<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /mail/users/{id}/spamfilter (api/modules/mail/user-spamfilter.yaml).
 *
 * move_junk is the legacy three-state y/a/n flag (stored verbatim);
 * purge_*_days are non-negative integers (FR-022).
 */
class UpdateMailUserSpamFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'move_junk' => ['sometimes', 'string', Rule::in(['y', 'a', 'n'])],
            'purge_trash_days' => ['sometimes', 'integer', 'min:0'],
            'purge_junk_days' => ['sometimes', 'integer', 'min:0'],
            'custom_mailfilter' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
