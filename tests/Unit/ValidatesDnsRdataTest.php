<?php

namespace Tests\Unit;

use App\Http\Requests\Concerns\ValidatesDnsRdata;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * Isolation tests for the BIND-safety closure factories (spec 013 T005).
 * The four 2026-07-05 incident values are the canonical failing cases:
 * a base64 DNSKEY in a DS digest field, TLSA 'somehashstring', SSHFP
 * 'fingerprinthash' and an undelimited NAPTR regexp.
 */
class ValidatesDnsRdataTest extends TestCase
{
    private const INCIDENT_BASE64_DIGEST = 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==';

    private object $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = new class
        {
            use ValidatesDnsRdata;

            public function make(string $factory, mixed ...$args): Closure
            {
                return $this->{$factory}(...$args);
            }

            public function naptrError(string $value): ?string
            {
                return $this->naptrRegexpError($value);
            }

            public function ereError(string $pattern): ?string
            {
                return $this->posixEreError($pattern);
            }
        };
    }

    /**
     * @return array<int, string> collected failure messages
     */
    private function failures(Closure $rule, mixed $value): array
    {
        $errors = [];

        $rule('field', $value, function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        return $errors;
    }

    // ------------------------------------------------------------------
    // hexRule (DS/TLSA/SSHFP — FR-002..FR-004)
    // ------------------------------------------------------------------

    public function test_hex_rule_rejects_the_incident_values(): void
    {
        $rule = $this->host->make('hexRule');

        $this->assertNotEmpty($this->failures($rule, self::INCIDENT_BASE64_DIGEST)); // DS incident
        $this->assertNotEmpty($this->failures($rule, 'somehashstring')); // TLSA incident
        $this->assertNotEmpty($this->failures($rule, 'fingerprinthash')); // SSHFP incident
    }

    public function test_hex_rule_charset_parity_and_exact_length(): void
    {
        $even = $this->host->make('hexRule');

        $this->assertSame([], $this->failures($even, str_repeat('ab', 5)));
        $this->assertSame([], $this->failures($even, '')); // presence handled elsewhere
        $this->assertNotEmpty($this->failures($even, 'abc')); // odd length
        $this->assertNotEmpty($this->failures($even, 'xyz1')); // non-hex chars

        $exact = $this->host->make('hexRule', fn (): ?int => 64);

        $this->assertSame([], $this->failures($exact, str_repeat('a1', 32)));
        $this->assertNotEmpty($this->failures($exact, str_repeat('a1', 20))); // 40 != 64
    }

    // ------------------------------------------------------------------
    // dnskeyRule (FR-005)
    // ------------------------------------------------------------------

    public function test_dnskey_rule_structure_protocol_and_base64(): void
    {
        $rule = $this->host->make('dnskeyRule');

        $this->assertSame([], $this->failures($rule, '257 3 13 '.self::INCIDENT_BASE64_DIGEST));

        $this->assertNotEmpty($this->failures($rule, 'freetext')); // not 4 fields
        $this->assertNotEmpty($this->failures($rule, '257 2 13 aGVsbG8=')); // protocol != 3
        $this->assertNotEmpty($this->failures($rule, '70000 3 13 aGVsbG8=')); // flags > 65535
        $this->assertNotEmpty($this->failures($rule, '257 3 999 aGVsbG8=')); // algorithm > 255
        $this->assertNotEmpty($this->failures($rule, '257 3 13 !!not-base64!!'));
        $this->assertNotEmpty($this->failures($rule, '257 3 13 aGVsbG8')); // length % 4 != 0
    }

    // ------------------------------------------------------------------
    // naptrRegexpRule (FR-006 — BIND txt_valid_regex)
    // ------------------------------------------------------------------

    public function test_naptr_regexp_rejects_the_incident_value_and_grammar_violations(): void
    {
        $this->assertNotNull($this->host->naptrError('sip:info@example.com')); // the incident: no delimiters
        $this->assertNotNull($this->host->naptrError('!^.*$!sip:info@example.com')); // only two delimiters
        $this->assertNotNull($this->host->naptrError('!^.*$!sip:x!extra!')); // four delimiters
        $this->assertNotNull($this->host->naptrError('1^.*$1sip:x1')); // digit delimiter
        $this->assertNotNull($this->host->naptrError('iabcidefi')); // 'i' flag char delimiter
        $this->assertNotNull($this->host->naptrError('\\a\\b\\')); // backslash delimiter
        $this->assertNotNull($this->host->naptrError('!^.*$!sip:x!x')); // flags other than i
        $this->assertNotNull($this->host->naptrError('!^[a$!x!')); // ERE does not compile
        $this->assertNotNull($this->host->naptrError('!^.*$!\\1!')); // backref exceeds groups
        $this->assertNotNull($this->host->naptrError('!^.*$!\\0!')); // \0 is invalid
    }

    public function test_naptr_regexp_accepts_bind_valid_expressions(): void
    {
        $this->assertNull($this->host->naptrError('!^.*$!sip:info@example.com!'));
        $this->assertNull($this->host->naptrError('!^.*$!sip:info@example.com!i')); // trailing flag
        $this->assertNull($this->host->naptrError('!^(.*)$!sip:\\1@example.com!')); // backref within groups
        $this->assertNull($this->host->naptrError('/^.*$/mailto:info@example.com/'));
        $this->assertNull($this->host->naptrError('!^\\!$!x\\!y!')); // escaped delimiters inside

        // The rule itself treats empty as valid (RFC 3403: no substitution).
        $this->assertSame([], $this->failures($this->host->make('naptrRegexpRule'), ''));
    }

    // ------------------------------------------------------------------
    // noZoneBreakingCharsRule (FR-008/FR-009)
    // ------------------------------------------------------------------

    public function test_no_zone_breaking_chars_rule(): void
    {
        $rule = $this->host->make('noZoneBreakingCharsRule');

        $this->assertSame([], $this->failures($rule, 'v=DKIM1; t=s; p=MIIBIjANBg'));
        $this->assertNotEmpty($this->failures($rule, 'has "quote" inside'));
        $this->assertNotEmpty($this->failures($rule, "line1\nline2"));
        $this->assertNotEmpty($this->failures($rule, "carriage\rreturn"));
        $this->assertSame([], $this->failures($rule, 'back\\slash allowed for TXT'));

        $banned = $this->host->make('noZoneBreakingCharsRule', true);
        $this->assertNotEmpty($this->failures($banned, 'back\\slash'));

        $normalized = $this->host->make(
            'noZoneBreakingCharsRule',
            false,
            fn (string $value): string => trim($value, '"')
        );
        $this->assertSame([], $this->failures($normalized, '"accidentally wrapped"'));
    }

    // ------------------------------------------------------------------
    // locRule (FR-010 — RFC 1876)
    // ------------------------------------------------------------------

    public function test_loc_rule_rfc1876_grammar(): void
    {
        $rule = $this->host->make('locRule');

        $this->assertSame([], $this->failures($rule, '51 30 12.748 N 0 7 39.612 W 0.00m'));
        $this->assertSame([], $this->failures($rule, '42 21 54 N 71 06 18 W -24m 30m'));
        $this->assertSame([], $this->failures($rule, '52 N 4 E 1m'));
        $this->assertSame([], $this->failures($rule, '32 7 19 S 116 2 25 E 10m'));

        $this->assertNotEmpty($this->failures($rule, 'somewhere over the rainbow'));
        $this->assertNotEmpty($this->failures($rule, '51 30 12.748 X 0 7 39.612 W 0.00m')); // bad hemisphere
        $this->assertNotEmpty($this->failures($rule, '51 30 N')); // missing longitude/altitude
    }

    // ------------------------------------------------------------------
    // caaIssuerShapeRule (FR-009 — RFC 8659 §4.5)
    // ------------------------------------------------------------------

    public function test_caa_issuer_shape_rule(): void
    {
        $rule = $this->host->make('caaIssuerShapeRule');

        $this->assertSame([], $this->failures($rule, 'letsencrypt.org'));
        $this->assertSame([], $this->failures($rule, 'ca.example.net; account=230123'));
        $this->assertSame([], $this->failures($rule, '')); // presence handled elsewhere

        $this->assertNotEmpty($this->failures($rule, 'not valid issuer'));
        $this->assertNotEmpty($this->failures($rule, 'ca.example.net; noequalsign'));
    }

    // ------------------------------------------------------------------
    // posixEreError (FR-019 shared piece)
    // ------------------------------------------------------------------

    public function test_posix_ere_compile_check(): void
    {
        $this->assertNull($this->host->ereError('^\\[SPAM\\]'));
        $this->assertNull($this->host->ereError('^.*$'));
        $this->assertNull($this->host->ereError('(foo|bar)+baz{2,4}'));

        $this->assertNotNull($this->host->ereError('['));
        $this->assertNotNull($this->host->ereError('('));
        $this->assertNotNull($this->host->ereError('a{2,1}'));
        $this->assertNotNull($this->host->ereError('(?i)spam')); // PCRE-only inline flag
        $this->assertNotNull($this->host->ereError('(?=lookahead)')); // PCRE-only construct
        $this->assertNull($this->host->ereError('\\(?')); // escaped paren + quantifier is fine
    }
}
