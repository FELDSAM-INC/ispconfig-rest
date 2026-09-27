<?php

namespace Tests\Unit;

use App\Support\WebWafIspconfigSecurity;
use App\Support\WebWafPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebWafIspconfigSecurityTest extends TestCase
{
    // Actual ISPConfig blacklist on development; validator trims and scans each line.
    public const VENDOR = <<<'RULES'
/^\s*(LoadModule|LoadFile|Include|IncludeOptional)(\s+|[\\\\])/mi
/^\s*(SuexecUserGroup|suPHP_UserGroup|suPHP_PHPPath|suPHP_ConfigPath)(\s+|[\\\\])/mi
/^\s*(FCGIWrapper|FastCgiExternalServer)(\s+|[\\\\])/mi
/^\s*(CustomLog|ErrorLog)(\s+|[\\\\])/mi
RULES;

    private function blocked(string $rules, string $line): bool
    {
        foreach (explode("\n", trim($rules)) as $rule) {
            $result = preg_match($rule, trim($line));
            self::assertNotFalse($result);
            if ($result === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_generated_policies_pass_but_other_directives_keep_their_protection(): void
    {
        $merged = WebWafIspconfigSecurity::merge(self::VENDOR);
        foreach (WebWafIspconfigSecurity::INCLUDES as $line) {
            self::assertTrue($this->blocked(self::VENDOR, $line));
            self::assertFalse($this->blocked($merged, $line));
        }
        $site = ['domain_id' => 19, 'server_id' => 1, 'sys_groupid' => 2, 'domain' => 'example.test'];
        $policy = WebWafPolicy::compile($site, 'apache', array_replace(WebWafPolicy::DEFAULTS, ['enabled' => true, 'application_profile' => 'wordpress', 'atomic' => true]));
        foreach (explode("\n", $policy) as $line) {
            self::assertFalse($this->blocked($merged, $line), $line);
        }
        foreach (['Include /tmp/arbitrary.conf', 'IncludeOptional /etc/ispconfig-waf/base.conf', 'Include /etc/ispconfig-waf/*.conf',
            'Include /etc/ispconfig-waf/../secrets.conf', 'Include /etc/ispconfig-waf/base.conf.evil', 'Include /etc/ispconfig-waf/base.conf # comment',
            'Include /etc/ispconfig-waf/base.conf /tmp/evil.conf', 'Include "/etc/ispconfig-waf/base.conf"', 'Include /etc/ISPCONFIG-WAF/base.conf',
            'include /etc/ispconfig-waf/base.conf', "Include\t/etc/ispconfig-waf/base.conf", 'Include\\', 'LoadModule test /tmp/evil.so',
            'LoadFile /tmp/evil.so', 'CustomLog "|/tmp/command" combined', 'ErrorLog /tmp/log', 'SuexecUserGroup root root',
            'suPHP_ConfigPath /tmp/php.ini', 'FCGIWrapper /tmp/command'] as $line) {
            self::assertTrue($this->blocked($merged, $line), $line);
        }
        self::assertSame($merged, WebWafIspconfigSecurity::merge(self::VENDOR, $merged));
    }

    public function test_unanchored_custom_rules_backreferences_and_future_vendor_rules_survive(): void
    {
        $custom = '~Include~im'."\n".'#(SetEnv)[ ]+\1#i'."\n".'@ /etc/ispconfig-waf/ @x';
        $merged = WebWafIspconfigSecurity::merge(self::VENDOR, $custom);
        foreach (WebWafIspconfigSecurity::INCLUDES as $line) {
            self::assertFalse($this->blocked($merged, $line));
        }
        foreach (['Include /etc/ispconfig-waf/custom.conf', 'Arbitrary Include token', 'SetEnv SetEnv', 'File /etc/ispconfig-waf/custom.conf'] as $line) {
            self::assertTrue($this->blocked($merged, $line), $line);
        }
        $updated = WebWafIspconfigSecurity::merge(self::VENDOR."\n~NewBlockedDirective~", $merged);
        self::assertTrue($this->blocked($updated, 'NewBlockedDirective'));
        self::assertTrue($this->blocked($updated, 'Arbitrary Include token'));
        self::assertSame($updated, WebWafIspconfigSecurity::merge(self::VENDOR."\n~NewBlockedDirective~", $updated));
    }

    #[DataProvider('invalid')]
    public function test_unrecognized_or_invalid_expressions_fail_safely(string $custom): void
    {
        $this->expectException(RuntimeException::class);
        WebWafIspconfigSecurity::merge(self::VENDOR, $custom);
    }

    public static function invalid(): array
    {
        return [['broken'], ['~[~'], ['{Include}'], ['~(*UTF)Include~']];
    }
}
