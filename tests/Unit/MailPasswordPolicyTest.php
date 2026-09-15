<?php

namespace Tests\Unit;

use App\Support\MailPasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec 028: exact port of ISPConfig's validate_password strength table and
 * password_check() messages (lib/classes/validate_password.inc.php).
 */
class MailPasswordPolicyTest extends TestCase
{
    private const GOOD = 'The chosen password does not match the security guidelines. It has to be at least 8 chars in length and have a strength of "Good".';

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function strengths(): array
    {
        return [
            'shorter than 5 characters' => ['Ab1!', 1],
            'lowercase, 5' => ['abcde', 1],
            'lowercase, 8' => ['abcdefgh', 2],
            'lowercase, 10' => ['abcdefghij', 3],
            'lowercase and digits (2 classes), 6' => ['abc123', 1],
            'uppercase and digits (2 classes), 9' => ['ABCDEFGH1', 3],
            'lower, upper, digit, 6' => ['Abcde1', 3],
            'lower, upper, digit, 8' => ['Abcdef12', 3],
            'lower, upper, digit, 9' => ['Abcdefg12', 4],
            'lower, upper, digit, 11' => ['Abcdefgh123', 5],
            'all classes, 6' => ['Ab1!ef', 3],
            'all classes, 7' => ['Ab1!efg', 4],
            'all classes, 9' => ['Ab1!efghi', 5],
            'upper, digit, special, 8' => ['ABC123!!', 4],
            'space is a special character' => ['Abc 12', 3],
            'length counts bytes' => ['äbcdefg', 2],
        ];
    }

    #[DataProvider('strengths')]
    public function test_strength_matches_the_legacy_table(string $password, int $expected): void
    {
        $this->assertSame($expected, MailPasswordPolicy::strength($password));
    }

    public function test_strength_and_length_messages(): void
    {
        $policy = ['min_length' => 8, 'min_strength' => 3, 'ascii_only' => false];

        $this->assertSame(self::GOOD, MailPasswordPolicy::violation('abcdefgh', $policy));
        $this->assertSame(self::GOOD, MailPasswordPolicy::violation('Abc12', $policy));
        $this->assertNull(MailPasswordPolicy::violation('Abcdef12', $policy));

        $this->assertSame(
            'The chosen password does not match the security guidelines. It has to be at least 12 chars in length and have a strength of "Very Strong".',
            MailPasswordPolicy::violation('Abcdef12', ['min_length' => 12, 'min_strength' => 5, 'ascii_only' => false])
        );

        $lengthOnly = ['min_length' => 10, 'min_strength' => 0, 'ascii_only' => false];
        $this->assertSame(
            'The chosen password does not match the security guidelines. It has to be at least 10 chars in length.',
            MailPasswordPolicy::violation('abcdefghi', $lengthOnly)
        );
        $this->assertNull(MailPasswordPolicy::violation('abcdefghij', $lengthOnly));
    }

    public function test_no_minimum_and_empty_password(): void
    {
        $this->assertNull(MailPasswordPolicy::violation('a', ['min_length' => 0, 'min_strength' => 0, 'ascii_only' => false]));
        $this->assertNull(MailPasswordPolicy::violation('', ['min_length' => 8, 'min_strength' => 3, 'ascii_only' => false]));
    }

    public function test_ascii_only_mode_replaces_length_and_strength(): void
    {
        $policy = ['min_length' => 12, 'min_strength' => 5, 'ascii_only' => true];
        $message = 'Please do not use special unicode characters for your password. This could lead to problems with your mail client.';

        $this->assertNull(MailPasswordPolicy::violation('short', $policy));
        $this->assertSame($message, MailPasswordPolicy::violation('pässwort', $policy));
        $this->assertSame($message, MailPasswordPolicy::violation("tab\tinside", $policy));
    }
}
