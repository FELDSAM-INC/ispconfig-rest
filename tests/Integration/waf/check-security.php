<?php

require '/app/vendor/autoload.php';
use App\Support\WebWafIspconfigSecurity;
use Tests\Unit\WebWafIspconfigSecurityTest;

$dir = '/usr/local/ispconfig/security';
$target = $dir.'/apache_directives.blacklist.custom';
function check(bool $passed, string $message): void
{
    if (! $passed) {
        throw new RuntimeException($message);
    }
}
function installSecurity(bool $success = true): void
{
    $process = proc_open(['bash', '/opt/waf-release/waf-server/install.sh', '--ispconfig-security-only'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/tmp/waf-security.log', 'w'], 2 => ['file', '/tmp/waf-security.log', 'a']], $pipes);
    check((proc_close($process) === 0) === $success, 'Unexpected installer result: '.file_get_contents('/tmp/waf-security.log'));
}

$first = file_get_contents($target);
check($first === WebWafIspconfigSecurity::merge(WebWafIspconfigSecurityTest::VENDOR), 'Full installer did not configure the exception');
check(fileperms($target) % 01000 === 0640 && fileowner($target) === 0 && filegroup($target) === filegroup($dir.'/apache_directives.blacklist'), 'Blacklist owner/mode/group changed');
$backup = file_get_contents($target.'.before-ispcp-waf');
check($backup === file_get_contents($dir.'/apache_directives.blacklist'), 'Original blacklist was not backed up');
$settings = file_get_contents($dir.'/security_settings.ini');
// A panel-only host must not require an enabled webserver or install/reload packages.
rename('/usr/local/ispconfig/server', '/usr/local/ispconfig/server.off');
installSecurity();
check(file_get_contents($target) === $first, 'Repeated install changed the blacklist');
file_put_contents($target, $first."~AdministratorBlockedDirective~\n~Include~\n");
$custom = file_get_contents($target);
file_put_contents($dir.'/apache_directives.blacklist', WebWafIspconfigSecurityTest::VENDOR."\n~NewUpstreamBlock~\n");
installSecurity();
$updated = file_get_contents($target);
check($updated === WebWafIspconfigSecurity::merge(file_get_contents($dir.'/apache_directives.blacklist'), $custom), 'Custom/upstream rules lost');
installSecurity();
check(file_get_contents($target) === $updated, 'Custom install is not idempotent');
check(file_get_contents($target.'.before-ispcp-waf') === $backup, 'Original backup was overwritten');
check(file_get_contents($dir.'/security_settings.ini') === $settings, 'Directive scanning settings changed');

rename($target, $target.'.real');
symlink($target.'.real', $target);
installSecurity(false);
check(file_get_contents($target.'.real') === $updated, 'Followed a blacklist symlink');
unlink($target);
rename($target.'.real', $target);
chmod($target, 0660);
installSecurity(false);
chmod($target, 0640);
file_put_contents($target, "invalid pattern\n");
installSecurity(false);
check(file_get_contents($target) === "invalid pattern\n", 'Invalid custom blacklist was overwritten');
file_put_contents($target, $updated);
echo "PASS ISPConfig security: automatic and panel-only install, exact exceptions, permissions, backup, custom/upstream merge, idempotency and safe refusal\n";
