<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /mail/spamfilter/policies/{id} (api/modules/mail/spamfilter-policies.yaml).
 */
class UpdateSpamfilterPolicyRequest extends SpamfilterPolicyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'policy_name' => [
                'sometimes',
                'string',
                'max:64',
                Rule::unique('spamfilter_policy', 'policy_name')
                    ->ignore($this->currentPolicy()?->getKey(), 'id'),
            ],
        ], $this->commonRules());
    }
}
