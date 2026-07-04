<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /mail/users/{id}/cc (api/modules/mail/user-cc.yaml).
 *
 * cc is an optionally-empty comma-separated list of email addresses (legacy
 * regex, mail_user.tform.php:186), lowercased and IDN-encoded (FR-021).
 */
class UpdateMailUserCCRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['forward_in_lda']);

        if ($this->has('cc') && is_string($this->input('cc')) && $this->input('cc') !== '') {
            $parts = array_map(
                fn (string $address): string => $this->idnLowerEmail($address),
                preg_split('/\s*,\s*/', trim($this->input('cc'))) ?: []
            );
            $input['cc'] = implode(',', $parts);
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cc' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^(\w+[\w\.\-\+]*\w{0,}@\w+[\w.-]*\.[a-z\-]{2,63}){0,1}(,\s*\w+[\w\.\-\+]*\w{0,}@\w+[\w.-]*\.[a-z\-]{2,63}){0,}$/i',
            ],
            'forward_in_lda' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('cc', $data) && $data['cc'] === null) {
            $data['cc'] = '';
        }

        return $data;
    }
}
