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
     * Hash a mailbox password in the legacy CRYPTMAIL format
     * (tform_base.inc.php:1372-1376): the cleartext is converted from UTF-8
     * to ISO-8859-1 before hashing ("The password for the mail system needs
     * to be converted to latin1 before it is hashed"), then run through the
     * same SHA-512 crypt as CRYPT (auth.inc.php::crypt_password with
     * $charset = 'ISO-8859-1').
     */
    public static function hashMail(string $cleartextPassword): string
    {
        $converted = mb_convert_encoding($cleartextPassword, 'ISO-8859-1', 'UTF-8');

        return self::hash($converted === false ? $cleartextPassword : $converted);
    }

    /**
     * Verify a cleartext password against a stored legacy hash.
     */
    public static function verify(string $cleartextPassword, string $hash): bool
    {
        return hash_equals($hash, crypt($cleartextPassword, $hash));
    }

    /**
     * Verify a cleartext password against a stored CRYPTMAIL hash.
     */
    public static function verifyMail(string $cleartextPassword, string $hash): bool
    {
        $converted = mb_convert_encoding($cleartextPassword, 'ISO-8859-1', 'UTF-8');

        return self::verify($converted === false ? $cleartextPassword : $converted, $hash);
    }

    /**
     * MySQL PASSWORD()-style hash (legacy tform encryption MYSQL,
     * db_mysql.inc.php::getPasswordHash 'mysql_native_password'):
     * '*' followed by the uppercase hex SHA1 of the binary SHA1.
     * Stored in web_database_user.database_password.
     */
    public static function mysqlPassword(string $cleartextPassword): string
    {
        return '*'.strtoupper(sha1(sha1($cleartextPassword, true)));
    }

    /**
     * MySQL 8 caching_sha2_password hash (legacy tform encryption MYSQLSHA2,
     * db_mysql.inc.php::getPasswordHash 'caching_sha2_password' →
     * mysqlSha256Crypt with a 20-byte salt and 5000 rounds). Stored in
     * web_database_user.database_password_sha2.
     */
    public static function mysqlSha2Password(string $cleartextPassword): string
    {
        return self::mysqlSha256Crypt($cleartextPassword, self::mysqlSalt(20), 5000);
    }

    /**
     * PostgreSQL SCRAM-SHA-256 verifier (legacy tform encryption
     * POSTGRESHA256, crypt.inc.php::postgres_scram_sha_256). Stored in
     * web_database_user.database_password_postgres.
     */
    public static function postgresScramSha256(string $cleartextPassword): string
    {
        $salt = random_bytes(16);
        $digestKey = hash_pbkdf2('sha256', $cleartextPassword, $salt, 4096, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $digestKey, true);
        $storedKey = hash('sha256', $clientKey, true);
        $serverKey = hash_hmac('sha256', 'Server Key', $digestKey, true);

        return sprintf(
            'SCRAM-SHA-256$4096:%s$%s:%s',
            base64_encode($salt),
            base64_encode($storedKey),
            base64_encode($serverKey)
        );
    }

    /**
     * Apache digest-auth hash for WebDAV users
     * (legacy webdav_user_edit.php:166: md5("username:dir:password") — the
     * username is the full prefixed name).
     */
    public static function webdavDigest(string $username, string $dir, string $cleartextPassword): string
    {
        return md5($username.':'.$dir.':'.$cleartextPassword);
    }

    /**
     * Salt generator for the MySQL SHA-256 crypt
     * (db_mysql.inc.php::genSalt): printable bytes, never '$'.
     */
    protected static function mysqlSalt(int $size): string
    {
        $salt = random_bytes($size);

        for ($i = 0; $i < $size; $i++) {
            $ord = ord($salt[$i]) & 0x7F;
            if ($ord < 32) {
                $ord += 32;
            }
            if ($ord === 36 /* $ */) {
                $ord += 1;
            }
            $salt[$i] = chr($ord);
        }

        return $salt;
    }

    /**
     * Faithful port of db_mysql.inc.php::mysqlSha256Crypt — the SHA-256
     * crypt algorithm as MySQL implements it (salt NOT truncated to 16
     * chars, custom base64 output, '$A$' prefix with the round count in
     * thousands as 3 hex digits).
     */
    protected static function mysqlSha256Crypt(string $plaintext, string $salt, int $rounds): string
    {
        $plaintextLen = strlen($plaintext);
        $saltLen = strlen($salt);

        $ctxA = hash_init('sha256');
        hash_update($ctxA, $plaintext);
        hash_update($ctxA, $salt);

        $ctxB = hash_init('sha256');
        hash_update($ctxB, $plaintext);
        hash_update($ctxB, $salt);
        hash_update($ctxB, $plaintext);
        $b = hash_final($ctxB, true);

        for ($i = $plaintextLen; $i > 32; $i -= 32) {
            hash_update($ctxA, $b);
        }
        hash_update($ctxA, substr($b, 0, $i));

        for ($i = $plaintextLen; $i > 0; $i >>= 1) {
            if (($i & 1) !== 0) {
                hash_update($ctxA, $b);
            } else {
                hash_update($ctxA, $plaintext);
            }
        }
        $a = hash_final($ctxA, true);

        $ctxDP = hash_init('sha256');
        for ($i = 0; $i < $plaintextLen; $i++) {
            hash_update($ctxDP, $plaintext);
        }
        $dp = hash_final($ctxDP, true);

        $p = '';
        for ($i = $plaintextLen; $i > 32; $i -= 32) {
            $p .= $dp;
        }
        $p .= substr($dp, 0, $i);

        $ctxDS = hash_init('sha256');
        for ($i = 0; $i < 16 + ord($a[0]); $i++) {
            hash_update($ctxDS, $salt);
        }
        $ds = hash_final($ctxDS, true);

        $s = '';
        for ($i = $saltLen; $i >= 32; $i -= 32) {
            $s .= $ds;
        }
        $s .= substr($ds, 0, $i);

        $c = '';
        for ($i = 0; $i < $rounds; $i++) {
            $ctxC = hash_init('sha256');
            if (($i & 1) !== 0) {
                hash_update($ctxC, $p);
            } else {
                hash_update($ctxC, $i === 0 ? $a : $c);
            }
            if ($i % 3 !== 0) {
                hash_update($ctxC, $s);
            }
            if ($i % 7 !== 0) {
                hash_update($ctxC, $p);
            }
            if (($i & 1) !== 0) {
                hash_update($ctxC, $i === 0 ? $a : $c);
            } else {
                hash_update($ctxC, $p);
            }
            $c = hash_final($ctxC, true);
        }

        $b64result = str_repeat(' ', 43);
        $pos = 0;
        $b64From24Bit = function (int $b2, int $b1, int $b0, int $n) use (&$b64result, &$pos): void {
            $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            $w = ($b2 << 16) | ($b1 << 8) | $b0;
            while (--$n >= 0) {
                $b64result[$pos++] = $alphabet[$w & 0x3F];
                $w >>= 6;
            }
        };

        $b64From24Bit(ord($c[0]), ord($c[10]), ord($c[20]), 4);
        $b64From24Bit(ord($c[21]), ord($c[1]), ord($c[11]), 4);
        $b64From24Bit(ord($c[12]), ord($c[22]), ord($c[2]), 4);
        $b64From24Bit(ord($c[3]), ord($c[13]), ord($c[23]), 4);
        $b64From24Bit(ord($c[24]), ord($c[4]), ord($c[14]), 4);
        $b64From24Bit(ord($c[15]), ord($c[25]), ord($c[5]), 4);
        $b64From24Bit(ord($c[6]), ord($c[16]), ord($c[26]), 4);
        $b64From24Bit(ord($c[27]), ord($c[7]), ord($c[17]), 4);
        $b64From24Bit(ord($c[18]), ord($c[28]), ord($c[8]), 4);
        $b64From24Bit(ord($c[9]), ord($c[19]), ord($c[29]), 4);
        $b64From24Bit(0, ord($c[31]), ord($c[30]), 3);

        return sprintf('$A$%03x$%s%s', intdiv($rounds, 1000), $salt, $b64result);
    }
}
