<?php

namespace App\Http\Requests;

/**
 * PUT /mail/users/{id} (api/modules/mail/users.yaml).
 *
 * Partial updates: every field is optional. email and login are immutable
 * after creation (contract, C-7 — re-sending the current value is accepted);
 * the password is re-hashed only when a non-empty value is provided
 * (FR-013), so empty/null passwords are dropped from the payload.
 */
class UpdateMailUserRequest extends MailUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentUser()?->getRawOriginal();

        return [
            'email' => [
                'sometimes',
                'string',
                $this->immutableAttributeRule($current, 'email', 'email'),
            ],
            'login' => [
                'sometimes',
                'nullable',
                'string',
                $this->immutableAttributeRule($current, 'login', 'login'),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:5', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'quota' => ['sometimes', 'integer', 'min:0'],
            'cc' => ['sometimes', 'nullable', 'string', 'regex:'.$this->ccRegex()],
            'forward_in_lda' => ['sometimes', 'boolean'],
            'sender_cc' => ['sometimes', 'nullable', 'string', 'max:255', 'email:rfc'],
            'postfix' => ['sometimes', 'boolean'],
            'greylisting' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        // Immutable fields never reach fill(); empty password = unchanged
        // (legacy skips empty password fields, FR-013).
        unset($data['email'], $data['login']);

        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        return $data;
    }

    /**
     * The empty string counts as "not provided" for the password (legacy
     * behavior), so min:5 must not fire on it.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->input('password') === '') {
            $this->request->remove('password');
        }
    }
}
