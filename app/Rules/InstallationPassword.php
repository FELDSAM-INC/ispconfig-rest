<?php

namespace App\Rules;

use App\Services\PasswordPolicyService;
use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A credential must satisfy the installation password policy (spec 038): the
 * legacy validate_password::password_check validator that ISPConfig attaches
 * to database, FTP, shell, WebDAV and web folder users, the website statistics
 * password and client and reseller passwords — applied to every key type, as
 * the legacy forms do.
 *
 * An empty or absent value is not judged; the field's own required/optional
 * rule decides whether a password must be present. Mailboxes use
 * App\Rules\MailboxPassword, which adds the mail-only ASCII restriction.
 */
class InstallationPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $message = PasswordPolicy::violation($value, app(PasswordPolicyService::class)->policy());

        if ($message !== null) {
            $fail($message);
        }
    }
}
