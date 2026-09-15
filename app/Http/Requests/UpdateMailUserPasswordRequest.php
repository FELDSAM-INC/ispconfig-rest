<?php

namespace App\Http\Requests;

use App\Rules\MailboxPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /mail/users/{id}/password (api/modules/mail/user-password.yaml +
 * api/components/schemas/MailUserPassword.yaml): the installation password
 * policy applies to every key type (spec 028).
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
            'password' => ['required', 'string', 'max:255', new MailboxPassword],
        ];
    }
}
