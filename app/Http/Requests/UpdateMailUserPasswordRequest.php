<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /mail/users/{id}/password (api/modules/mail/user-password.yaml +
 * api/components/schemas/MailUserPassword.yaml — minLength 5 after the
 * schema harmonization, C-11).
 */
class UpdateMailUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
