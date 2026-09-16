<?php

namespace App\Support;

/**
 * Installation password policy (spec 038): the exact port of ISPConfig's
 * lib/classes/validate_password.inc.php — the strength table of
 * `_get_password_strength()` and the length/strength check of
 * `password_check()`.
 *
 * Spec 028 introduced this computation for mailboxes; it is shared because the
 * same legacy validator guards database, FTP, shell, WebDAV and web folder
 * users, the website statistics password and client and reseller passwords.
 * The mail-only ASCII restriction stays in MailPasswordPolicy.
 */
final class PasswordPolicy
{
    public const STRENGTH_NAMES = [1 => 'Weak', 2 => 'Fair', 3 => 'Good', 4 => 'Strong', 5 => 'Very Strong'];

    public const WEAK_MESSAGE = 'The chosen password does not match the security guidelines. It has to be at least {chars} chars in length and have a strength of "{strength}".';

    public const WEAK_LENGTH_MESSAGE = 'The chosen password does not match the security guidelines. It has to be at least {chars} chars in length.';

    /**
     * Legacy `_get_password_strength()`: 1 (weak) to 5 (very strong). The
     * length is counted in bytes, like strlen().
     */
    public static function strength(string $password): int
    {
        $length = strlen($password);

        if ($length < 5) {
            return 1;
        }

        $points = 0;
        $different = 0;

        if (preg_match('/[abcdefghijklnmopqrstuvwxyz]/', $password)) {
            $different++;
        }

        if (preg_match('/[ABCDEFGHIJKLNMOPQRSTUVWXYZ]/', $password)) {
            $points++;
            $different++;
        }

        if (preg_match('/[0123456789]/', $password)) {
            $points++;
            $different++;
        }

        if (preg_match('/[`~!@#$%^&*()_+|\\=\-\[\]}{\';:\/?.>,<" ]/', $password)) {
            $points++;
            $different++;
        }

        if ($points === 0 || $different < 3) {
            return $length <= 6 ? 1 : ($length <= 8 ? 2 : 3);
        }

        if ($points === 1) {
            return $length <= 6 ? 2 : ($length <= 10 ? 3 : 4);
        }

        if ($points === 2) {
            return $length <= 8 ? 3 : ($length <= 10 ? 4 : 5);
        }

        if ($points === 3) {
            return $length <= 6 ? 3 : ($length <= 8 ? 4 : 5);
        }

        return $length <= 6 ? 4 : 5;
    }

    /**
     * The legacy validation message for a password, or null when it passes
     * (`password_check()`: an empty password passes here — the caller's own
     * required/optional rule decides whether one must be present).
     *
     * @param  array{min_length: int, min_strength: int}  $policy
     */
    public static function violation(string $password, array $policy): ?string
    {
        if ($password === '') {
            return null;
        }

        $minLength = $policy['min_length'];
        $minStrength = $policy['min_strength'];

        if (strlen($password) >= $minLength && self::strength($password) >= $minStrength) {
            return null;
        }

        return $minStrength > 0
            ? str_replace(['{chars}', '{strength}'], [(string) $minLength, self::STRENGTH_NAMES[$minStrength] ?? (string) $minStrength], self::WEAK_MESSAGE)
            : str_replace('{chars}', (string) $minLength, self::WEAK_LENGTH_MESSAGE);
    }
}
