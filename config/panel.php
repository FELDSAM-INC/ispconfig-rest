<?php

return [
    // Public address when a proxy or custom layout makes local vhost discovery insufficient.
    'url' => env('ISPCONFIG_PANEL_URL'),
    // Only active ISPConfig interface configurations, never a path supplied by the caller.
    'vhosts' => [
        '/etc/apache2/sites-enabled/*ispconfig.vhost',
        '/etc/httpd/conf/sites-enabled/*ispconfig.vhost',
        '/etc/nginx/sites-enabled/*ispconfig.vhost',
    ],
];
