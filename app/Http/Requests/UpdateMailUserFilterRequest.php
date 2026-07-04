<?php

namespace App\Http\Requests;

/**
 * PUT /mail/users/{id}/filters/{filter_id} (api/modules/mail/user-filters.yaml).
 *
 * Partial updates; the filter cannot be moved to another mailbox
 * (mailuser_id is never fillable).
 */
class UpdateMailUserFilterRequest extends MailUserFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->commonRules(required: false);
    }
}
