<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for mailbox writes (contract: api/modules/mail/users.yaml;
 * legacy: source_code/interface/web/mail/form/mail_user.tform.php +
 * mail_user_edit.php::onSubmit()):
 *
 *  - email/login/cc/sender_cc are IDN-encoded and lowercased before
 *    validation (legacy IDNTOASCII + TOLOWER filters);
 *  - the y/n flags accept booleans as well as legacy 'y'/'n' strings;
 *  - quota is bytes, 0 = unlimited, negatives rejected (contract C-6
 *    resolution baked into MailUser.yaml).
 */
abstract class MailUserRequest extends FormRequest
{
    use NormalizesMailInput;

    /**
     * Authentication happens in the api.key middleware; per-record
     * sys_perm_* enforcement is out of scope (spec assumption).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation (legacy SAVE filters).
     */
    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['forward_in_lda', 'postfix', 'greylisting']);

        foreach (['email', 'login', 'sender_cc'] as $field) {
            if ($this->has($field) && is_string($this->input($field)) && $this->input($field) !== '') {
                $input[$field] = $this->idnLowerEmail($this->input($field));
            }
        }

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
     * Validated data ready for MailUser::fill().
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['cc', 'sender_cc'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        return $data;
    }

    /**
     * The route-bound mailbox being updated (null on store).
     */
    protected function currentUser(): ?MailUser
    {
        $user = $this->route('mailUser');

        return $user instanceof MailUser ? $user : null;
    }

    /**
     * Legacy cc validator regex (mail_user.tform.php:186): an optionally
     * empty comma-separated list of email addresses.
     */
    protected function ccRegex(): string
    {
        return '/^(\w+[\w\.\-\+]*\w{0,}@\w+[\w.-]*\.[a-z\-]{2,63}){0,1}(,\s*\w+[\w\.\-\+]*\w{0,}@\w+[\w.-]*\.[a-z\-]{2,63}){0,}$/i';
    }
}
