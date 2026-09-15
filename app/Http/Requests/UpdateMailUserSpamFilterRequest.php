<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemAuthorizationException;
use App\Http\Requests\Concerns\ValidatesSpamfilterPolicy;
use App\Services\AccountMailService;
use App\Support\IspContext;
use App\Support\ProblemType;
use App\Support\ProblemTypeCollector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /mail/users/{id}/spamfilter (api/modules/mail/user-spamfilter.yaml).
 *
 * move_junk is the legacy three-state y/a/n flag (stored verbatim);
 * purge_*_days are non-negative integers (FR-022).
 *
 * Client and reseller keys (spec 025): move_junk and purge_*_days belong to
 * the legacy Mail Filter tab (403 when the installation hides it,
 * mail_user.tform.php:427); custom_mailfilter is the administrator-only
 * Custom Rules tab (:474) and refused as a typed field error.
 *
 * policy_id (spec 026) is the mailbox's spam filter level for every key type:
 * 0 or a policy the key can read; it is stored in spamfilter_users, not in
 * the mail_user row, and is not a mail filter tab field.
 */
class UpdateMailUserSpamFilterRequest extends FormRequest
{
    use ValidatesSpamfilterPolicy;

    /** Fields of the legacy Mail Filter tab. */
    public const MAIL_FILTER_TAB_FIELDS = ['move_junk', 'purge_trash_days', 'purge_junk_days'];

    public function authorize(): bool
    {
        if (array_intersect(self::MAIL_FILTER_TAB_FIELDS, array_keys($this->all())) === []) {
            return true;
        }

        return app(AccountMailService::class)->tabAllowed(
            app(IspContext::class)->authScope(),
            AccountMailService::MAIL_FILTER_TAB
        );
    }

    protected function failedAuthorization(): void
    {
        throw new ProblemAuthorizationException(
            AccountMailService::TAB_MESSAGES[AccountMailService::MAIL_FILTER_TAB],
            ProblemType::FEATURE_NOT_ALLOWED,
            ['feature' => AccountMailService::MAIL_FILTER_TAB]
        );
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
            'policy_id' => $this->spamfilterPolicyRules(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->exists('custom_mailfilter') || app(IspContext::class)->authScope()->isAdmin) {
                    return;
                }

                app(ProblemTypeCollector::class)->tag('custom_mailfilter', ProblemType::FEATURE_NOT_ALLOWED);
                $validator->errors()->add('custom_mailfilter', 'Custom mail filter rules can only be changed with an administrator key.');
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return Arr::except($this->validated(), ['policy_id']);
    }

    /**
     * The spam filter level to set, or null when the request does not set it.
     */
    public function policyId(): ?int
    {
        return $this->validatedPolicy('policy_id');
    }
}
