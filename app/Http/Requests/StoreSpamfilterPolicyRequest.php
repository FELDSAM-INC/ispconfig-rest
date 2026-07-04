<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /mail/spamfilter/policies (api/modules/mail/spamfilter-policies.yaml).
 */
class StoreSpamfilterPolicyRequest extends SpamfilterPolicyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'policy_name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('spamfilter_policy', 'policy_name'),
            ],
        ], $this->commonRules());
    }
}
