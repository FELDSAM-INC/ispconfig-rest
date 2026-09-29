<?php

require '/app/app/Support/WebWafProfiles.php';
use App\Support\WebWafProfiles;

function same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Incorrect application profile discovery: '.json_encode($actual));
    }
}
$all = array_keys(WebWafProfiles::PLUGINS);
$others = array_values(array_diff($all, ['wordpress']));
same($all, WebWafProfiles::installed());
$root = '/root/waf-profile-fixtures';
mkdir($root, 0700);
exec('cp -a '.escapeshellarg(realpath(WebWafProfiles::CRS)).' '.escapeshellarg($root.'/crs'), $output, $code);
same(0, $code);
$config = $root.'/owasp.conf';
$crs = $root.'/crs';
file_put_contents($config, WebWafProfiles::configuration($crs));
same($all, WebWafProfiles::installed($config, $crs));
$file = $crs.'/plugins/'.WebWafProfiles::PLUGINS['wordpress'].'-before.conf';
$original = file_get_contents($file);
chmod($file, 0666);
same($others, WebWafProfiles::installed($config, $crs));
chmod($file, 0644);
chown($file, 33);
same($others, WebWafProfiles::installed($config, $crs));
chown($file, 0);
chmod($crs.'/plugins', 0777);
same([], WebWafProfiles::installed($config, $crs));
chmod($crs.'/plugins', 0755);
rename($file, $file.'.real');
symlink($file.'.real', $file);
same($others, WebWafProfiles::installed($config, $crs));
unlink($file);
rename($file.'.real', $file);
// A plugin without its enable guard could not be switched off for other websites.
file_put_contents($file, str_replace('-plugin_enabled "@eq 0"', '-plugin_enabled "@eq 9"', $original));
same($others, WebWafProfiles::installed($config, $crs));
file_put_contents($file, $original);
rename($crs.'/plugins/'.WebWafProfiles::PLUGINS['wordpress'].'-config.conf', $root.'/config.conf');
same($others, WebWafProfiles::installed($config, $crs));
rename($root.'/config.conf', $crs.'/plugins/'.WebWafProfiles::PLUGINS['wordpress'].'-config.conf');
$initialization = $crs.'/rules/REQUEST-901-INITIALIZATION.conf';
$rules = file_get_contents($initialization);
file_put_contents($initialization, str_replace("ver:'OWASP_CRS/4.", "ver:'OWASP_CRS/3.", $rules));
same([], WebWafProfiles::installed($config, $crs));
file_put_contents($initialization, $rules);
file_put_contents($config, "Include ".$crs."/rules/*.conf\n");
same([], WebWafProfiles::installed($config, $crs));
file_put_contents($config, WebWafProfiles::configuration($crs));
same($all, WebWafProfiles::installed($config, $crs));
echo "PASS installed profiles: pinned plugins, CRS 4, managed includes and root ownership\n";
