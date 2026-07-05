<?php

namespace App\Http\Requests\Concerns;

use Closure;

/**
 * BIND-safety rdata validators for DNS record writes (spec 013 P1).
 *
 * Every rule is calibrated to BIND's zone parser as ground truth — a value
 * these closures accept must never make `named-checkzone` refuse the whole
 * zone (incident 2026-07-05: one bad DS/TLSA/SSHFP/NAPTR record took every
 * record of the domain offline). Legacy 3.3.1p1 validates almost none of
 * this (dns_ds.tform.php:105 TODO, dns_tlsa.tform.php:107-109,
 * dns_sshfp.tform.php:108-113), so these rules are an intentional,
 * documented stricter-than-legacy deviation (spec 013, Parity section).
 */
trait ValidatesDnsRdata
{
    use ValidatesPosixEre;

    /**
     * Hexadecimal value with an even number of digits, optionally an exact
     * length resolved at validation time (DS RFC 4034 §5.1.4 / TLSA RFC 6698
     * §2.1.3 / SSHFP RFC 4255 §3.1.2 — BIND: "bad hex encoding").
     *
     * @param  Closure|null  $expectedLength  resolves the exact digit count
     *                                        (null = even-length check only)
     */
    protected function hexRule(?Closure $expectedLength = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($expectedLength): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (! preg_match('/^[0-9a-fA-F]+$/', $value)) {
                $fail("The {$attribute} must be a hexadecimal string (characters 0-9 and a-f only) — BIND refuses the zone otherwise (\"bad hex encoding\").");

                return;
            }

            $expected = $expectedLength === null ? null : $expectedLength();

            if ($expected !== null && strlen($value) !== $expected) {
                $fail("The {$attribute} must be exactly {$expected} hexadecimal characters for the selected digest/matching/hash type.");

                return;
            }

            if (strlen($value) % 2 !== 0) {
                $fail("The {$attribute} must contain an even number of hexadecimal characters.");
            }
        };
    }

    /**
     * Why $value is not valid base64, or null when it is. Whitespace between
     * chunks is tolerated (zone files split long keys across lines).
     */
    protected function base64Error(string $value): ?string
    {
        $compact = (string) preg_replace('/\s+/', '', $value);

        if ($compact === '') {
            return 'the value is empty';
        }

        if (! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $compact)) {
            return 'it contains characters outside the base64 alphabet';
        }

        if (strlen($compact) % 4 !== 0) {
            return 'its length is not a multiple of 4';
        }

        if (base64_decode($compact, true) === false) {
            return 'it does not decode as base64';
        }

        return null;
    }

    /**
     * DNSKEY rdata: `<flags> 3 <algorithm> <base64-key>` (RFC 4034 §2 —
     * protocol MUST be 3, BIND rejects any other value; no legacy form
     * exists for DNSKEY).
     */
    protected function dnskeyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $parts = preg_split('/\s+/', trim($value), 4) ?: [];

            if (count($parts) < 4) {
                $fail("The {$attribute} must be a DNSKEY rdata of the form '<flags> 3 <algorithm> <base64-key>' (RFC 4034 §2).");

                return;
            }

            [$flags, $protocol, $algorithm, $key] = $parts;

            if (! ctype_digit($flags) || (int) $flags > 65535) {
                $fail("The {$attribute} DNSKEY flags field must be an integer between 0 and 65535 (RFC 4034 §2.1.1).");

                return;
            }

            if ($protocol !== '3') {
                $fail("The {$attribute} DNSKEY protocol field must be exactly 3 (RFC 4034 §2.1.2 — BIND rejects any other value).");

                return;
            }

            if (! ctype_digit($algorithm) || (int) $algorithm > 255) {
                $fail("The {$attribute} DNSKEY algorithm field must be an integer between 0 and 255 (RFC 4034 §2.1.3).");

                return;
            }

            if (($error = $this->base64Error($key)) !== null) {
                $fail("The {$attribute} DNSKEY public key must be valid base64: {$error}.");
            }
        };
    }

    /**
     * NAPTR regexp: empty, or a delimited substitution expression per BIND's
     * txt_valid_regex (lib/dns/rdata/in_1/naptr_35.c; RFC 3403 §4.1, RFC
     * 2915 §3): one single-byte delimiter that is not a digit, backslash or
     * the 'i' flag char, used exactly three times (escaped occurrences
     * allowed inside), optional trailing 'i' flag, replacement backrefs
     * \1-\9 only (bounded by the ERE's capture groups), and the ERE part
     * must compile.
     */
    protected function naptrRegexpRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (($error = $this->naptrRegexpError($value)) !== null) {
                $fail("The {$attribute} must be empty or a delimited substitution expression like '!^.*$!sip:info@example.com!' — {$error} (RFC 3403 §4.1; BIND refuses the zone otherwise).");
            }
        };
    }

    /**
     * Why $value fails BIND's txt_valid_regex grammar, or null when valid.
     */
    protected function naptrRegexpError(string $value): ?string
    {
        $delim = $value[0];

        if ($delim >= '0' && $delim <= '9') {
            return 'the delimiter (first character) must not be a digit';
        }

        if ($delim === '\\' || $delim === 'i') {
            return 'the delimiter (first character) must not be a backslash or the "i" flag character';
        }

        $ere = '';
        $groups = 0;
        $maxBackref = 0;
        $inReplacement = false;
        $inFlags = false;
        $length = strlen($value);

        for ($i = 1; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === $delim && ! $inReplacement) {
                $inReplacement = true;

                continue;
            }

            if ($char === $delim && ! $inFlags) {
                $inFlags = true;

                continue;
            }

            if ($char === $delim) {
                return 'the delimiter must appear exactly three times';
            }

            if ($inFlags) {
                // Flags are not escaped (BIND); only 'i' is defined.
                if ($char === 'i') {
                    continue;
                }

                return 'only the "i" flag may follow the final delimiter';
            }

            if (! $inReplacement) {
                $ere .= $char;

                if ($char === '(') {
                    $groups++;
                }
            }

            if ($char === '\\') {
                if ($i + 1 >= $length) {
                    return 'a trailing backslash is not a valid escape';
                }

                $next = $value[++$i];

                if ($inReplacement && $next >= '0' && $next <= '9') {
                    if ($next === '0') {
                        return 'replacement backreferences are \1-\9 only (\0 is invalid)';
                    }

                    $maxBackref = max($maxBackref, (int) $next);
                } elseif (! $inReplacement) {
                    $ere .= $next;
                }
            }
        }

        if (! $inFlags) {
            return 'expected <delimiter><regex><delimiter><replacement><delimiter> with an optional trailing "i" flag';
        }

        if ($maxBackref > $groups) {
            return "the replacement references \\{$maxBackref} but the regex only defines {$groups} capture group(s)";
        }

        if (($error = $this->posixEreError($ere)) !== null) {
            return 'the regex part is not a valid POSIX ERE ('.$error.')';
        }

        return null;
    }

    /**
     * Reject characters that unbalance the zone file: the BIND template
     * emits these values inside literal double quotes without escaping
     * (server/conf/bind_pri.domain.master:24,36,63;
     * DnsRecordMetaService::quote() wraps verbatim) — an interior quote or
     * newline takes the whole zone offline. Rejection (not escaping) keeps
     * stored bytes identical to legacy for all valid input.
     *
     * @param  bool  $banBackslash  also reject backslashes (CAA/HINFO values
     *                              are quoted verbatim with no escape layer)
     * @param  Closure|null  $normalize  applied before checking (TXT strips
     *                                   accidental wrapping quotes first)
     */
    protected function noZoneBreakingCharsRule(bool $banBackslash = false, ?Closure $normalize = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($banBackslash, $normalize): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if ($normalize !== null) {
                $value = (string) $normalize($value);
            }

            if (str_contains($value, '"')) {
                $fail("The {$attribute} must not contain double quotes — the value is emitted inside a quoted zone-file string and an interior quote breaks the whole zone.");

                return;
            }

            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                $fail("The {$attribute} must not contain CR or LF characters — a raw newline breaks the zone file.");

                return;
            }

            if ($banBackslash && str_contains($value, '\\')) {
                $fail("The {$attribute} must not contain backslashes — the value is quoted verbatim without escaping.");
            }
        };
    }

    /**
     * LOC rdata per the RFC 1876 presentation grammar:
     * `d1 [m1 [s1]] {N|S} d2 [m2 [s2]] {E|W} alt[m] [siz[m] [hp[m] [vp[m]]]]`
     * (legacy validator commented out — dns_loc.tform.php:113-118; BIND
     * loc_29.c refuses malformed LOC).
     */
    protected function locRule(): Closure
    {
        $degrees = '\d{1,3}(\s+\d{1,2}(\s+\d{1,2}(\.\d{1,3})?)?)?';
        $meters = '-?\d+(\.\d+)?m?';
        $pattern = '/^'.$degrees.'\s+[NSns]\s+'.$degrees.'\s+[EWew]\s+'.$meters
            .'(\s+'.$meters.'(\s+'.$meters.'(\s+'.$meters.')?)?)?$/';

        return function (string $attribute, mixed $value, Closure $fail) use ($pattern): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (! preg_match($pattern, $value)) {
                $fail("The {$attribute} must match the RFC 1876 LOC presentation format 'd1 [m1 [s1]] N|S d2 [m2 [s2]] E|W alt[m] [siz[m] [hp[m] [vp[m]]]]', e.g. '51 30 12.748 N 0 7 39.612 W 0.00m'.");
            }
        };
    }

    /**
     * CAA issue/issuewild value: issuer-domain[;parameters] (RFC 8659
     * §4.5-4.6). Legacy composes from a curated CA list plus an option
     * regex (dns_caa_edit.php:152-163); the API validates the free-form
     * value instead.
     */
    protected function caaIssuerShapeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (! preg_match('/^[a-zA-Z0-9\.\-]*(;\s*[a-zA-Z0-9_\-]+=[^";]+)*$/', $value)) {
                $fail("The {$attribute} must be an issuer domain optionally followed by ';key=value' parameters (RFC 8659 §4.5), e.g. 'letsencrypt.org' or 'ca.example.net; account=230123'.");
            }
        };
    }
}
