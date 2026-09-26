<?php
require '/app/vendor/autoload.php';
require '/isp/server/plugins-available/nginx_plugin.inc.php';
$service = new App\Services\WebRuntimeService;
$site = new App\Models\WebDomain;
$site->setRawAttributes(['type'=>'vhost','web_folder'=>'','php'=>'php-fpm','php_fpm_chroot'=>'n']);
$settings = ['document_root_subdir'=>'public', 'environment'=>[
 'APP_ENV'=>'production', 'EMPTY'=>'', 'DB_PASSWORD'=>' $dollar ${HOME} "quotes" \\path {literal} <tmpl_var name="domain"> ##subroot /etc## ##merge## # comment č ',
 'CURLY_CLOSE'=>'}only', 'CURLY_OPEN'=>'{only', 'MULTISPACE'=>'one   two', 'LITERAL_BACKSLASH'=>'a\\nb',
]];
file_put_contents('/out/expected.json', json_encode($settings['environment']));
$apache = str_replace('{DOCROOT_CLIENT}', '/var/www/runtime/web', $service->compile($site, 'apache', $settings));
file_put_contents('/out/apache.conf', '<VirtualHost *:8080>'."\nServerName runtime.test\nDocumentRoot /var/www/runtime/web\n<Directory /var/www/runtime/web>\nRequire all granted\nAllowOverride All\n</Directory>\n<FilesMatch \\\.php$>\nSetHandler \"proxy:fcgi://127.0.0.1:9000\"\n</FilesMatch>\n".$apache."\n</VirtualHost>\n");
$nginx = 'server {'."\nlisten 8081;\nserver_name runtime.test;\nroot /var/www/runtime/web;\nindex index.php;\nlocation ~ \\\.php$ {\ntry_files /dummy @php;\n}\nlocation @php {\ntry_files \$uri =404;\ninclude /etc/nginx/fastcgi_params;\nfastcgi_pass 127.0.0.1:9000;\nfastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n}\n".$service->compile($site, 'nginx', $settings)."\n}\n";
$merge = new ReflectionMethod(nginx_plugin::class, 'nginx_merge_locations');
file_put_contents('/out/nginx.conf', $merge->invoke(new nginx_plugin, $nginx));

// Verify the same public root inside an actual PHP-FPM chroot.
$site->setRawAttributes(['type'=>'vhost','web_folder'=>'','php'=>'php-fpm','php_fpm_chroot'=>'y']);
$apacheChroot = str_replace('{DOCROOT_CLIENT}', '/var/www/runtime/web', $service->compile($site, 'apache', $settings));
$apacheBase = file_get_contents('/out/apache.conf');
$apacheBase = substr($apacheBase, 0, strpos($apacheBase, '# BEGIN ISPCP RUNTIME'));
file_put_contents('/out/apache-chroot.conf', str_replace(['*:8080', ':9000'], ['*:8082', ':9001'], $apacheBase).$apacheChroot."\n</VirtualHost>\n");
$nginxBase = substr($nginx, 0, strpos($nginx, '# BEGIN ISPCP RUNTIME'));
$nginxBase = str_replace(['8081', ':9000'], ['8083', ':9001'], $nginxBase);
file_put_contents('/out/nginx-chroot.conf', $merge->invoke(new nginx_plugin, $nginxBase.$service->compile($site, 'nginx', $settings)."\n}\n"));
