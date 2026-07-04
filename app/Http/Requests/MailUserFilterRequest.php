<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared behavior for mail user filter writes (contract:
 * api/modules/mail/user-filters.yaml; legacy:
 * source_code/interface/web/mail/form/mail_user_filter.tform.php).
 *
 * The enums are the values legacy STORES (Subject/From/... — not UI language
 * keys, C-2 resolution); target uses the legacy unicode pattern.
 */
abstract class MailUserFilterRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active']);

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'rulename' => [$presence, 'string', 'max:64'],
            'source' => [$presence, 'string', Rule::in(['Subject', 'From', 'To', 'List-Id', 'Header', 'Size'])],
            'op' => [$presence, 'string', Rule::in(['contains', 'is', 'begins', 'ends', 'regex', 'localpart', 'domain'])],
            'searchterm' => [$presence, 'string', 'max:255'],
            'action' => [$presence, 'string', Rule::in(['move', 'delete', 'keep', 'reject'])],
            'target' => ['sometimes', 'nullable', 'string', 'max:100', "regex:/^[\\p{Latin}0-9\\.\\'\\-\\_\\ \\&\\/]{0,100}$/u"],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
