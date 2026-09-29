<?php

namespace Tests\Unit;

use App\Support\WebWafCrs;
use App\Support\WebWafProfiles;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebWafCrsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/waf-crs-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/crs', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function manifest(): array
    {
        return WebWafCrs::manifest(file_get_contents(__DIR__.'/../../waf-server/crs.json'));
    }

    public function test_shipped_manifest_pins_the_lts_release_and_every_profile_plugin(): void
    {
        $manifest = $this->manifest();
        $this->assertMatchesRegularExpression('/\A4\.25\.[0-9]+\z/', $manifest['version']);
        $this->assertSame(array_keys(WebWafProfiles::PLUGINS), array_keys($manifest['plugins']));
        $this->assertSame('https://raw.githubusercontent.com/coreruleset/wordpress-rule-exclusions-plugin/v1.2.0/plugins/wordpress-rule-exclusions-before.conf',
            WebWafCrs::pluginUrl('wordpress-rule-exclusions', '1.2.0', 'wordpress-rule-exclusions-before.conf'));
    }

    public function test_manifest_rejects_other_sources_missing_pins_and_other_plugins(): void
    {
        $valid = json_decode(file_get_contents(__DIR__.'/../../waf-server/crs.json'), true);
        $cases = [
            fn ($m) => array_replace($m, ['version' => '3.3.5']),
            fn ($m) => array_replace($m, ['archive' => str_replace('github.com/coreruleset', 'github.com/attacker', $m['archive'])]),
            fn ($m) => array_replace($m, ['signature' => $m['archive']]),
            fn ($m) => array_replace($m, ['sha256' => strtoupper($m['sha256'])]),
            fn ($m) => array_replace($m, ['fingerprint' => substr($m['fingerprint'], 0, 16)]),
            function ($m) {
                unset($m['plugins']['xenforo']);

                return $m;
            },
            function ($m) {
                $m['plugins']['joomla'] = $m['plugins']['drupal'];

                return $m;
            },
            function ($m) {
                $m['plugins']['drupal']['name'] = 'wordpress-rule-exclusions';

                return $m;
            },
            function ($m) {
                $m['plugins']['drupal']['version'] = '1.0.0/../../x';

                return $m;
            },
            function ($m) {
                $m['plugins']['drupal']['files'] = ['../../rules/x.conf' => str_repeat('a', 64), 'drupal-rule-exclusions-config.conf' => str_repeat('a', 64)];

                return $m;
            },
            function ($m) {
                $m['plugins']['drupal']['files']['drupal-rule-exclusions-before.conf'] = 'unpinned';

                return $m;
            },
        ];
        foreach ($cases as $index => $mutate) {
            try {
                WebWafCrs::manifest(json_encode($mutate($valid)));
                $this->fail('Accepted manifest case '.$index);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_signature_requires_a_good_signature_from_the_pinned_key(): void
    {
        $fingerprint = $this->manifest()['fingerprint'];
        $good = "[GNUPG:] NEWSIG\n[GNUPG:] GOODSIG 38EEACA1AB8A6E72 OWASP CRS\n[GNUPG:] VALIDSIG {$fingerprint} 2026-03-01 1772323200 0 4 0 1 10 00 {$fingerprint}\n";
        $this->assertTrue(WebWafCrs::signatureValid($good, $fingerprint));
        $this->assertFalse(WebWafCrs::signatureValid($good, str_repeat('A', 40)));
        $this->assertFalse(WebWafCrs::signatureValid($good."[GNUPG:] BADSIG 38EEACA1AB8A6E72 OWASP CRS\n", $fingerprint));
        $this->assertFalse(WebWafCrs::signatureValid($good."[GNUPG:] EXPKEYSIG 38EEACA1AB8A6E72 OWASP CRS\n", $fingerprint));
        $this->assertFalse(WebWafCrs::signatureValid("[GNUPG:] ERRSIG 38EEACA1AB8A6E72 1 10 00 1772323200 9 -\n[GNUPG:] NO_PUBKEY 38EEACA1AB8A6E72\n", $fingerprint));
        $this->assertFalse(WebWafCrs::signatureValid('', $fingerprint));
    }

    public function test_extracted_tree_allows_only_bounded_regular_files(): void
    {
        $tree = $this->root.'/tree';
        mkdir($tree.'/rules', 0755, true);
        file_put_contents($tree.'/rules/a.conf', 'SecRule');
        $this->assertNull(WebWafCrs::unsafeTree($tree));
        $this->assertSame('oversized file a.conf', WebWafCrs::unsafeTree($tree, 3));
        $this->assertSame('oversized archive', WebWafCrs::unsafeTree($tree, 100, 3));
        symlink('/etc/passwd', $tree.'/rules/link.conf');
        $this->assertSame('link link.conf', WebWafCrs::unsafeTree($tree));
        unlink($tree.'/rules/link.conf');
        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($tree.'/rules/fifo', 0600);
            $this->assertSame('special file fifo', WebWafCrs::unsafeTree($tree));
        }
    }

    public function test_managed_setup_keeps_the_reference_defaults_and_requires_the_setup_version(): void
    {
        $example = "SecDefaultAction \"phase:1,log,auditlog,pass\"\nSecAction \\\n    \"id:900990,\\\n    phase:1,\\\n    pass,\\\n    setvar:tx.crs_setup_version=4251\"\n";
        $setup = WebWafCrs::setup($example, '4.25.1');
        $this->assertStringStartsWith('# Managed by ispconfig-rest from OWASP CRS 4.25.1', $setup);
        $this->assertStringEndsWith($example, $setup);
        $this->expectException(RuntimeException::class);
        WebWafCrs::setup(str_replace('    setvar:tx', '#    setvar:tx', $example), '4.25.1');
    }

    public function test_xml_attribute_patch_drops_only_the_unparsable_ctl_actions(): void
    {
        mkdir($this->root.'/tree/rules', 0755, true);
        $file = $this->root.'/tree/rules/REQUEST-901-INITIALIZATION.conf';
        $rule = "SecRule TX:crs_xml_attr_inspect \"@eq 0\" \\\n    \"id:901181,\\\n    phase:2,\\\n    pass,\\\n";
        $ctl = "    ctl:ruleRemoveTargetByTag=attack-sqli;XML://@*,\\\n    ctl:ruleRemoveTargetByTag=attack-xss;XML://@*,\\\n";
        $end = "    ver:'OWASP_CRS/4.25.1'\"\nSecRule ARGS|XML:/*|XML://@* \"@rx x\" \"id:942999\"\n";
        file_put_contents($file, $rule.$ctl.$end);
        WebWafCrs::patch($this->root.'/tree', [WebWafCrs::PATCH_XML_ATTRIBUTES]);
        $this->assertSame($rule.$end, file_get_contents($file));
        try {
            WebWafCrs::patch($this->root.'/tree', [WebWafCrs::PATCH_XML_ATTRIBUTES]);
            $this->fail('Applied a patch that no longer matches');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not apply', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        WebWafCrs::patch($this->root.'/tree', ['other']);
    }

    public function test_local_setup_is_only_comments(): void
    {
        foreach (explode("\n", trim(WebWafCrs::LOCAL_SETUP)) as $line) {
            $this->assertStringStartsWith('#', $line);
        }
    }

    public function test_checksum_or_signature_failure_installs_nothing(): void
    {
        $manifest = $this->manifest();
        $fetch = function (string $url, string $file) {
            file_put_contents($file, 'not the release');
        };
        try {
            WebWafCrs::install($manifest, '/nonexistent.gpg', [], $this->root.'/crs', $fetch);
            $this->fail('Accepted a wrong archive');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pinned checksum', $e->getMessage());
        }
        $manifest['sha256'] = hash('sha256', 'not the release');
        try {
            WebWafCrs::install($manifest, '/nonexistent.gpg', [], $this->root.'/crs', $fetch);
            $this->fail('Accepted an unsigned archive');
        } catch (RuntimeException $e) {
            // Without gpgv on the test host the installer refuses before checking the signature.
            $this->assertMatchesRegularExpression('/signature is not valid|Could not run gpgv/', $e->getMessage());
        }
        $this->assertSame([], array_values(array_diff(scandir($this->root.'/crs'), ['.', '..'])));
    }

    public function test_activation_switches_atomically_and_pruning_keeps_only_the_active_version(): void
    {
        $crs = $this->root.'/crs';
        mkdir($crs.'/4.25.1');
        mkdir($crs.'/4.25.2');
        mkdir($crs.'/.staging-abc');
        file_put_contents($crs.'/notes.txt', 'kept');
        $this->assertNull(WebWafCrs::activate('4.25.1', $crs));
        $this->assertSame('4.25.1', readlink($crs.'/current'));
        $this->assertSame('4.25.1', WebWafCrs::activate('4.25.2', $crs));
        $this->assertSame('4.25.2', readlink($crs.'/current'));
        WebWafCrs::prune($crs);
        $this->assertSame(['4.25.2', 'current', 'notes.txt'], array_values(array_diff(scandir($crs), ['.', '..'])));
        unlink($crs.'/current');
        mkdir($crs.'/current');
        $this->expectException(RuntimeException::class);
        WebWafCrs::activate('4.25.2', $crs);
    }

    public function test_intact_requires_the_marker_and_unmodified_plugins(): void
    {
        $this->assertFalse(WebWafCrs::intact($this->root.'/crs/4.25.1', $this->manifest()));
        mkdir($this->root.'/crs/4.25.1');
        symlink($this->root.'/crs/4.25.1', $this->root.'/crs/link');
        $this->assertFalse(WebWafCrs::intact($this->root.'/crs/link', $this->manifest()));
    }

    public function test_configuration_disables_every_plugin_then_enables_only_the_selected_profile(): void
    {
        $config = WebWafProfiles::configuration('/crs');
        $lines = explode("\n", trim($config));
        $this->assertSame(['Include /etc/ispconfig-waf/crs-setup.conf', 'Include /etc/ispconfig-waf/crs-setup.local.conf'], array_slice($lines, 0, 2));
        foreach (WebWafProfiles::PLUGINS as $plugin) {
            $this->assertStringContainsString("setvar:'tx.".$plugin."-plugin_enabled=0'", $lines[2]);
        }
        $this->assertStringContainsString('"@streq wordpress" "id:19971,', $lines[3]);
        $this->assertStringContainsString("setvar:'tx.wordpress-rule-exclusions-plugin_enabled=1'", $lines[3]);
        $this->assertSame(['Include /crs/plugins/*-config.conf', 'Include /crs/plugins/*-before.conf', 'Include /crs/rules/*.conf', 'Include /crs/plugins/*-after.conf'], array_slice($lines, -4));
        $this->assertSame([], WebWafProfiles::installed($this->root.'/missing.conf', $this->root.'/crs'));
    }
}
