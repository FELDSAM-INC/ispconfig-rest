<?php

namespace App\Http\Requests;

/**
 * POST /mail/users/{id}/filters (api/modules/mail/user-filters.yaml).
 */
class StoreMailUserFilterRequest extends MailUserFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->commonRules(required: true);
    }
}
