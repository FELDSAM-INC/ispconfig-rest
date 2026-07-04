<?php

namespace App\Support;

/**
 * Password hashing byte-compatible with legacy ISPConfig
 * (source_code/interface/lib/classes/auth.inc.php::crypt_password):
 * SHA-512 crypt with '$6$rounds=5000$' and a 16-hex-character salt.
 *
 * ISPConfig verifies interface logins with PHP crypt() against the stored
 * hash, so any '$6$'-prefixed crypt hash we produce is accepted by the
 * legacy login. The client module stores this hash in client.password and
 * sys_user.passwort (tform 'encryption' => 'CRYPT').
 */
class LegacyCrypt
{
    /**
     * Hash a cleartext password in the legacy CRYPT format.
     */
    public static function hash(string $cleartextPassword): string
    {
        // Legacy prefers SHA-512 (available since PHP 5.3, always on 8.x).
        $salt = '$6$rounds=5000$';
        $salt .= substr(bin2hex(random_bytes(8)), 0, 16);
        $salt .= '$';

        return crypt($cleartextPassword, $salt);
    }

    /**
     * Verify a cleartext password against a stored legacy hash.
     */
    public static function verify(string $cleartextPassword, string $hash): bool
    {
        return hash_equals($hash, crypt($cleartextPassword, $hash));
    }
}
