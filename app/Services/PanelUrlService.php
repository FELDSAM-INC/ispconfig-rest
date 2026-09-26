<?php

namespace App\Services;

/** Read the master's installed interface vhost, without loading ISPConfig's private PHP configuration. */
class PanelUrlService
{
    public function url(): ?string
    {
        $override = trim((string) config('panel.url', ''));
        if ($override !== '') {
            return $this->valid($override) ? $override : null;
        }

        $fallbackHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $urls = [];
        foreach ((array) config('panel.vhosts', []) as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (! is_readable($file) || ! is_file($file)) {
                    continue;
                }
                $text = @file_get_contents($file, false, null, 0, 131073);
                if (! is_string($text) || strlen($text) > 131072) {
                    continue;
                }
                $text = preg_replace('/^\h*#.*$/m', '', $text);
                foreach ($this->listeners($text, $fallbackHost) as $url) {
                    if ($this->valid($url)) {
                        $urls[$url] = $url;
                    }
                }
            }
        }

        // IPv4/IPv6 listeners for the same URL are one result; ambiguous installations must provide the public URL.
        return count($urls) === 1 ? reset($urls) : null;
    }

    private function listeners(string $text, string $fallbackHost): array
    {
        $urls = [];
        if (preg_match_all('/<VirtualHost\s+([^>]+)>(.*?)<\/VirtualHost>/is', $text, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                $host = preg_match('/^\h*ServerName\h+([^\s#]+)\h*$/mi', $block[2], $name) ? $name[1] : $fallbackHost;
                $scheme = preg_match('/^\h*SSLEngine\h+on\b/mi', $block[2]) ? 'https' : 'http';
                preg_match_all('/:(\d+)(?:\s|$)/', $block[1], $ports);
                foreach ($ports[1] as $port) {
                    $urls[] = $this->address($scheme, $host, (int) $port);
                }
            }
        } elseif (preg_match_all('/^\h*listen\h+((?:\[[^\]]+\]|[\w.*-]+):)?(\d+)([^;]*);/mi', $text, $listeners, PREG_SET_ORDER)) {
            $host = $fallbackHost;
            if (preg_match('/^\h*server_name\h+([^;\s]+)\h*;/mi', $text, $name) && $name[1] !== '_') {
                $host = $name[1];
            }
            foreach ($listeners as $listener) {
                $scheme = preg_match('/\bssl\b/i', $listener[3]) || preg_match('/^\h*ssl\h+on\h*;/mi', $text) ? 'https' : 'http';
                $urls[] = $this->address($scheme, $host, (int) $listener[2]);
            }
        }

        return $urls;
    }

    private function address(string $scheme, string $host, int $port): string
    {
        if ($port < 1 || $port > 65535 || $host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '['.$host.']';
        }

        return $scheme.'://'.$host.($port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port).'/';
    }

    private function valid(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && ! preg_match('/[\x00-\x20<>"\x5c]/', $url);
    }
}
