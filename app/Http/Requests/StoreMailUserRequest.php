<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POST /mail/users (api/modules/mail/users.yaml).
 *
 * FR-008/FR-011: email valid + unique + normalized, login regex + unique
 * (defaults to the email), password required (minLength 5 per schema),
 * name required, quota bytes >= 0; an active mail_forwarding source with
 * the same address rejects the mailbox (legacy duplicate_alias_or_forward).
 * The domain-part existence check (400) lives in MailUserService.
 */
class StoreMailUserRequest extends MailUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'max:255',
                'email:rfc',
                Rule::unique('mail_user', 'email'),
                $this->noActiveForwardRule(),
            ],
            'login' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^[_a-z0-9][\w\.\-\+@]{1,63}$/',
                Rule::unique('mail_user', 'login'),
            ],
            'password' => ['required', 'string', 'min:5', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'quota' => ['sometimes', 'integer', 'min:0'],
            'cc' => ['sometimes', 'nullable', 'string', 'regex:'.$this->ccRegex()],
            'forward_in_lda' => ['sometimes', 'boolean'],
            'sender_cc' => ['sometimes', 'nullable', 'string', 'max:255', 'email:rfc'],
            'postfix' => ['sometimes', 'boolean'],
            'greylisting' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Legacy mail_user_edit.php: "Check if there is no alias or forward
     * with this address" (active mail_forwarding source).
     */
    protected function noActiveForwardRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $collision = DB::table('mail_forwarding')
                ->where('active', 'y')
                ->where('source', (string) $value)
                ->exists();

            if ($collision) {
                $fail('An active forward or alias with this address already exists.');
            }
        };
    }
}
