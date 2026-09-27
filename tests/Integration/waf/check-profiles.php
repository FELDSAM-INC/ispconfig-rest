<?php

require '/app/app/Support/WebWafProfiles.php';
use App\Support\WebWafProfiles;

function same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Incorrect application profile discovery: '.json_encode($actual));
    }
}
same(array_keys(WebWafProfiles::FILES), WebWafProfiles::installed());
$root = '/root/waf-profile-fixtures';
mkdir($root, 0700);
file_put_contents($root.'/owasp.conf', WebWafProfiles::configuration());
$file = $root.'/'.WebWafProfiles::FILES['wordpress'];
copy('/usr/share/modsecurity-crs/rules/'.WebWafProfiles::FILES['wordpress'], $file);
same(['wordpress'], WebWafProfiles::installed($root.'/owasp.conf', $root));
chmod($file, 0666);
same([], WebWafProfiles::installed($root.'/owasp.conf', $root));
chmod($file, 0644);
chown($file, 33);
same([], WebWafProfiles::installed($root.'/owasp.conf', $root));
chown($file, 0);
chmod($root, 0777);
same([], WebWafProfiles::installed($root.'/owasp.conf', $root));
chmod($root, 0700);
rename($file, $file.'.real');
symlink($file.'.real', $file);
same([], WebWafProfiles::installed($root.'/owasp.conf', $root));
unlink($file);
rename($file.'.real', $file);
file_put_contents($file, str_replace('Core Rule Set ver.3.', 'Core Rule Set ver.4.', file_get_contents($file)));
same([], WebWafProfiles::installed($root.'/owasp.conf', $root));
copy('/usr/share/modsecurity-crs/rules/'.WebWafProfiles::FILES['wordpress'], $file);
file_put_contents($root.'/owasp.conf', "Include /usr/share/modsecurity-crs/rules/*.conf\n");
same([], WebWafProfiles::installed($root.'/owasp.conf', $root));
echo "PASS installed profiles: package files, version, managed includes and root ownership\n";
