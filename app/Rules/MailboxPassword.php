<?php

namespace App\Rules;

use App\Services\AccountMailService;
use App\Support\MailPasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A mailbox password must satisfy the installation password policy (spec 028):
 * the legacy validate_password::password_check validator of
 * mail_user.tform.php, applied to every key type.
 */
class MailboxPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $message = MailPasswordPolicy::violation($value, app(AccountMailService::class)->passwordPolicy());

        if ($message !== null) {
            $fail($message);
        }
    }
}
