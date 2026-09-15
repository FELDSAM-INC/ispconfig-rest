<?php

namespace App\Support;

/**
 * Mailbox password policy (spec 028): exact port of ISPConfig's
 * lib/classes/validate_password.inc.php (strength table, password_check())
 * and of the ISASCII validator mail_user.tform.php uses instead when
 * mail_password_onlyascii is enabled. Messages are the English legacy texts
 * (lib/lang/en.lng, mail/lib/lang/en_mail_user.lng).
 */
final class MailPasswordPolicy
{
    public const STRENGTH_NAMES = [1 => 'Weak', 2 => 'Fair', 3 => 'Good', 4 => 'Strong', 5 => 'Very Strong'];

    public const WEAK_MESSAGE = 'The chosen password does not match the security guidelines. It has to be at least {chars} chars in length and have a strength of "{strength}".';

    public const WEAK_LENGTH_MESSAGE = 'The chosen password does not match the security guidelines. It has to be at least {chars} chars in length.';

    public const ASCII_MESSAGE = 'Please do not use special unicode characters for your password. This could lead to problems with your mail client.';

    /**
     * Legacy _get_password_strength(): 1 (weak) to 5 (very strong). The
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
     * (password_check(): empty passwords pass, the caller requires them).
     *
     * @param  array{min_length: int, min_strength: int, ascii_only: bool}  $policy
     */
    public static function violation(string $password, array $policy): ?string
    {
        if ($policy['ascii_only']) {
            return preg_match('/[^\x20-\x7F]/', $password) === 1 ? self::ASCII_MESSAGE : null;
        }

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
