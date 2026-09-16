<?php

namespace App\Support;

/**
 * Mailbox password policy (spec 028): the installation policy of
 * PasswordPolicy plus the ISASCII validator mail_user.tform.php uses instead
 * when `mail_password_onlyascii` is enabled.
 *
 * Spec 038 moved the shared length/strength computation to PasswordPolicy so
 * the other credential types can use it without inheriting the mail-only ASCII
 * rule; the constants stay here so existing callers keep working.
 */
final class MailPasswordPolicy
{
    public const STRENGTH_NAMES = PasswordPolicy::STRENGTH_NAMES;

    public const WEAK_MESSAGE = PasswordPolicy::WEAK_MESSAGE;

    public const WEAK_LENGTH_MESSAGE = PasswordPolicy::WEAK_LENGTH_MESSAGE;

    public const ASCII_MESSAGE = 'Please do not use special unicode characters for your password. This could lead to problems with your mail client.';

    /**
     * Legacy _get_password_strength(): 1 (weak) to 5 (very strong).
     */
    public static function strength(string $password): int
    {
        return PasswordPolicy::strength($password);
    }

    /**
     * The legacy validation message for a mailbox password, or null when it
     * passes. With `ascii_only` the ASCII check replaces the length and
     * strength check, exactly as the legacy form swaps the validator.
     *
     * @param  array{min_length: int, min_strength: int, ascii_only: bool}  $policy
     */
    public static function violation(string $password, array $policy): ?string
    {
        if ($policy['ascii_only']) {
            return preg_match('/[^\x20-\x7F]/', $password) === 1 ? self::ASCII_MESSAGE : null;
        }

        return PasswordPolicy::violation($password, $policy);
    }
}
