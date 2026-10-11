<?php

namespace Tests\Unit;

use Exception;
use PHPUnit\Framework\TestCase;

/** ansible/roles/ispconfig_server/files/autoinstall.php, which the patched ISPConfig autoinstaller requires. */
final class AnsibleAutoinstallValuesTest extends TestCase
{
    /** The relevant part of the autoinstall.ini the autoinstaller generates (ISPConfigDebianOS::runPerfectSetup). */
    private const GENERATED = "[install]\n\tlanguage=en\n\tinstall_mode=expert\n\thostname=web1.example.com\n\tispconfig_port=8080\n"
        ."\tispconfig_admin_password=generated\n\n\t[ssl_cert]\n\tssl_cert_country=DE\n\n\t[expert]\n\tmysql_ispconfig_user=ispconfig\n"
        ."\tjoin_multiserver_setup=n\n\tmysql_master_hostname=\n\tmysql_master_root_password=\n\tinstall_ispconfig_web_interface=y\n\n"
        ."\t[update]\n\tmysql_master_hostname=\n\tmysql_master_root_password=\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ispcp-ai-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(dirname(__DIR__, 2).'/ansible/roles/ispconfig_server/files/autoinstall.php', $this->dir.'/autoinstall.php');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
    }

    private function apply(array $values, string $autoinstall = self::GENERATED): string
    {
        file_put_contents($this->dir.'/autoinstall.json', json_encode($values));

        return (static function (string $file, string $autoinstall): string {
            return require $file;
        })($this->dir.'/autoinstall.php', $autoinstall);
    }

    public function test_joining_server_replaces_keys_everywhere_and_adds_missing_ones_to_expert(): void
    {
        $result = $this->apply([
            'join_multiserver_setup' => 'y',
            'mysql_master_hostname' => '10.0.0.10',
            'mysql_master_port' => '3306',
            'mysql_master_root_password' => 'p=a$s\\1;#"x',
            'install_ispconfig_web_interface' => 'n',
            'ssl_cert_country' => 'CZ',
        ]);
        $this->assertStringContainsString("\t[expert]\n\tmysql_master_port=3306\n\tmysql_ispconfig_user=ispconfig\n\tjoin_multiserver_setup=y\n", $result);
        $this->assertSame(2, substr_count($result, "\tmysql_master_hostname=10.0.0.10\n"), 'expert and update');
        $this->assertSame(2, substr_count($result, "\tmysql_master_root_password=p=a\$s\\1;#\"x\n"));
        $this->assertStringContainsString("\tinstall_ispconfig_web_interface=n\n", $result);
        $this->assertStringContainsString("\tssl_cert_country=CZ\n", $result);
        $this->assertStringContainsString("\tispconfig_admin_password=generated\n", $result, 'untouched');
    }

    public function test_values_that_break_the_ini_are_refused(): void
    {
        foreach ([['k' => "a\nb"], ['k' => ' padded'], ['Bad-Key' => 'x']] as $values) {
            try {
                $this->apply($values);
                $this->fail('accepted '.json_encode($values));
            } catch (Exception $e) {
                $this->assertStringStartsWith('Invalid autoinstall value', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('no [expert] section');
        $this->apply(['new_key' => 'x'], "[install]\nlanguage=en\n");
    }
}
