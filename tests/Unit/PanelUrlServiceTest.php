<?php

namespace Tests\Unit;

use App\Services\PanelUrlService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PanelUrlServiceTest extends TestCase
{
    #[DataProvider('vhosts')]
    public function test_reads_the_installed_panel_listener(string $vhost, ?string $expected): void
    {
        $file = tempnam(sys_get_temp_dir(), 'isp-panel-');
        try {
            file_put_contents($file, $vhost);
            config(['panel.url' => null, 'panel.vhosts' => [$file], 'app.url' => 'https://master.example.com:8090']);
            $this->assertSame($expected, app(PanelUrlService::class)->url());
        } finally {
            unlink($file);
        }
    }

    public static function vhosts(): array
    {
        return [
            'apache ssl' => ["<VirtualHost _default_:8080>\n SSLEngine On\n</VirtualHost>", 'https://master.example.com:8080/'],
            'apache custom name and port' => ["<VirtualHost *:9443>\n ServerName panel.example.com\n SSLEngine on\n</VirtualHost>", 'https://panel.example.com:9443/'],
            'apache http' => ["<VirtualHost *:8088>\n# SSLEngine On\n</VirtualHost>", 'http://master.example.com:8088/'],
            'nginx dual stack ssl' => ["server {\n listen 8443 ssl;\n listen [::]:8443 ssl ipv6only=on;\n server_name _;\n}", 'https://master.example.com:8443/'],
            'nginx named ssl default port' => ["server {\n listen 443 ssl;\n server_name panel.example.com;\n}", 'https://panel.example.com/'],
            'nginx http' => ["server {\n listen 192.0.2.1:8080;\n # ssl on;\n server_name _;\n}", 'http://master.example.com:8080/'],
            'legacy nginx ssl' => ["server {\n listen 8080;\n ssl on;\n}", 'https://master.example.com:8080/'],
            'no listener' => ["# listen 8080 ssl;\n", null],
            'invalid port' => ["listen 99999 ssl;\n", null],
            'ambiguous listeners' => ["listen 80;\nlisten 443 ssl;\n", null],
            'unsafe host' => ["listen 8080 ssl;\nserver_name user:secret@evil.test;\n", null],
        ];
    }

    public function test_missing_vhost_does_not_guess_the_port(): void
    {
        config(['panel.url' => null, 'panel.vhosts' => [], 'app.url' => 'https://master.example.com:8090']);
        $this->assertNull(app(PanelUrlService::class)->url());
    }

    public function test_proxy_url_override_is_validated(): void
    {
        config(['panel.vhosts' => []]);
        foreach (['https://panel.example.com/ispconfig/', 'http://panel.example.com:8080/'] as $url) {
            config(['panel.url' => $url]);
            $this->assertSame($url, app(PanelUrlService::class)->url());
        }
        foreach (['javascript:alert(1)', 'https://user:secret@panel.example.com', '//panel.example.com', 'https://panel.example.com/\foo'] as $url) {
            config(['panel.url' => $url]);
            $this->assertNull(app(PanelUrlService::class)->url());
        }
    }
}
