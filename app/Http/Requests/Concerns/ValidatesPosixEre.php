<?php

namespace App\Http\Requests\Concerns;

use Closure;

/**
 * POSIX extended regular expression (ERE) compile check, shared by the NAPTR
 * regexp validator (BIND compiles the substitution regex with REG_EXTENDED,
 * lib/dns/rdata/in_1/naptr_35.c) and the mail filter `op=regex` rule (the
 * legacy UI hint promises POSIX ERE — web/mail/lib/lang/en_mail_user_filter.lng).
 *
 * The check is a documented stricter-than-legacy deviation for mail filters
 * (spec 013 US4): 3.3.1p1 has no compile check, but one invalid pattern makes
 * Dovecot reject the mailbox's whole custom_mailfilter sieve script.
 */
trait ValidatesPosixEre
{
    /**
     * Why $pattern is not a valid POSIX ERE, or null when it is.
     *
     * PCRE (a superset of ERE) is used for the structural compile check;
     * PCRE-only `(?...)` group constructs (inline flags, lookaround,
     * non-capture groups) are rejected explicitly since they are invalid
     * in POSIX ERE but compile under PCRE.
     */
    protected function posixEreError(string $pattern): ?string
    {
        // Scan for an unescaped '(?' — invalid in POSIX ERE ('?' needs a
        // preceding repeatable element), PCRE-only otherwise.
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            if ($pattern[$i] === '\\') {
                $i++; // skip the escaped character

                continue;
            }

            if ($pattern[$i] === '(' && ($pattern[$i + 1] ?? '') === '?') {
                return 'the "(?" construct (inline flags, lookaround, non-capture groups) is PCRE-only and invalid in POSIX ERE';
            }
        }

        $delimited = '/'.str_replace('/', '\\/', $pattern).'/';

        // Silence the compilation warning locally (the '@' operator would
        // still surface through global error handlers, e.g. PHPUnit's).
        set_error_handler(static fn (): bool => true);

        try {
            $result = preg_match($delimited, '');
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            return 'the pattern does not compile as a regular expression';
        }

        return null;
    }

    /**
     * Closure rule: the value must compile as a POSIX ERE.
     */
    protected function posixEreRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (($error = $this->posixEreError($value)) !== null) {
                $fail("The {$attribute} must be a valid POSIX extended regular expression (ERE): {$error}.");
            }
        };
    }
}
