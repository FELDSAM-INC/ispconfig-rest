<?php

namespace App\Http\Requests;

use App\Models\MailUser;
use App\Models\MailUserFilter;

/**
 * PUT /mail/users/{id}/filters/{filter_id} (api/modules/mail/user-filters.yaml).
 *
 * Partial updates; the filter cannot be moved to another mailbox
 * (mailuser_id is never fillable). The regex-op compile rule (FR-019)
 * resolves op/searchterm against the stored row when not submitted.
 */
class UpdateMailUserFilterRequest extends MailUserFilterRequest
{
    /** Cached route-bound filter (false = looked up, not found). */
    protected MailUserFilter|false|null $storedFilter = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->commonRules(required: false);
    }

    /**
     * The stored value of the filter being updated (the filter is scoped to
     * the mailbox in the URL, mirroring the controller's findFilter()).
     */
    protected function storedFilterValue(string $field): mixed
    {
        if ($this->storedFilter === null) {
            $mailUser = $this->route('mailUser');
            $filterId = $this->route('filterId');

            $this->storedFilter = ($mailUser instanceof MailUser && $filterId !== null)
                ? (MailUserFilter::query()
                    ->where('mailuser_id', $mailUser->getKey())
                    ->find((int) $filterId) ?? false)
                : false;
        }

        if ($this->storedFilter === false) {
            return null;
        }

        return $this->storedFilter->getRawOriginal()[$field] ?? null;
    }
}
