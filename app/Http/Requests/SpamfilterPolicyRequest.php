<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\SpamfilterPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared behavior for /mail/spamfilter/policies writes (contract:
 * api/modules/mail/spamfilter-policies.yaml; legacy:
 * source_code/interface/web/mail/form/spamfilter_policy.tform.php).
 *
 * The Y/N flags are UPPERCASE strings stored with exact casing — they are
 * NOT booleans in the API (FR-005).
 */
abstract class SpamfilterPolicyRequest extends FormRequest
{
    use NormalizesMailInput;

    public const YN_FLAGS = [
        'virus_lover',
        'spam_lover',
        'banned_files_lover',
        'bad_header_lover',
        'bypass_virus_checks',
        'bypass_spam_checks',
        'bypass_banned_checks',
        'bypass_header_checks',
    ];

    public const QUARANTINE_FIELDS = [
        'virus_quarantine_to',
        'spam_quarantine_to',
        'banned_quarantine_to',
        'bad_header_quarantine_to',
        'clean_quarantine_to',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * The route-bound record being updated (null on store).
     */
    protected function currentPolicy(): ?SpamfilterPolicy
    {
        $record = $this->route('spamfilterPolicy');

        return $record instanceof SpamfilterPolicy ? $record : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        $rules = [];

        foreach (self::YN_FLAGS as $flag) {
            $rules[$flag] = ['sometimes', 'string', Rule::in(['Y', 'N'])];
        }

        foreach (self::QUARANTINE_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
