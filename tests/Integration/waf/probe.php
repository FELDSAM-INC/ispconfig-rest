<?php

require '/app/app/Support/WebWafPolicy.php';
use App\Support\WebWafPolicy;

$mode = $argv[1] ?? 'detection';
$site = ['domain_id' => 1, 'server_id' => 1, 'sys_groupid' => 5, 'domain' => 'waf.test'];
$settings = WebWafPolicy::DEFAULTS;
$settings['enabled'] = true;
$settings['mode'] = $mode === 'detection' ? 'detection' : 'enforcing';
if ($mode === 'exclude') {
    $settings['exclusions'] = [['rule_id' => 942100, 'path' => '/allowed', 'parameter' => 'q']];
}
if ($mode === 'allowip') {
    $settings['ip_allowlist'] = ['127.0.0.1'];
}
if ($mode === 'off') {
    $settings['enabled'] = false;
}
file_put_contents('/etc/apache2/sites-enabled/waf.conf', "<VirtualHost 127.0.0.1:8080>\nServerName waf.test\nDocumentRoot /tmp/waf-www\n<Directory /tmp/waf-www>\nRequire all granted\n</Directory>\n".WebWafPolicy::compile($site, 'apache', $settings)."</VirtualHost>\n");
file_put_contents('/etc/nginx/sites-enabled/waf.conf', "server { listen 127.0.0.1:8081; server_name waf.test; root /tmp/waf-www; location / { try_files \$uri /index.html; }\n".WebWafPolicy::compile($site, 'nginx', $settings)."}\n");

// A second owner must remain protected even when the first site's rules are bypassed.
$control = ['domain_id' => 2, 'server_id' => 1, 'sys_groupid' => 6, 'domain' => 'other.test'];
$controlSettings = array_replace(WebWafPolicy::DEFAULTS, ['enabled' => true, 'mode' => 'enforcing']);
file_put_contents('/etc/apache2/sites-enabled/waf.conf', "<VirtualHost 127.0.0.1:8080>\nServerName other.test\nDocumentRoot /tmp/waf-www\n<Directory /tmp/waf-www>\nRequire all granted\n</Directory>\n".WebWafPolicy::compile($control, 'apache', $controlSettings)."</VirtualHost>\n", FILE_APPEND);
file_put_contents('/etc/nginx/sites-enabled/waf.conf', "server { listen 127.0.0.1:8081; server_name other.test; root /tmp/waf-www; error_log /tmp/waf-original-error.log warn; location / { try_files \$uri /index.html; }\n".WebWafPolicy::compile($control, 'nginx', $controlSettings)."}\n", FILE_APPEND);
