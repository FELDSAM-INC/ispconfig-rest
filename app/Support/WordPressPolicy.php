<?php

namespace App\Support;

use InvalidArgumentException;

/** Fixed Apache grammar. Neither WordPress files nor API input supply directives. */
final class WordPressPolicy
{
    public const SERVER = ['xmlrpc', 'config', 'htfiles', 'sensitive', 'potential', 'indexes', 'includes_php', 'uploads_php', 'cache_php', 'author', 'bots'];

    public const LOCAL = ['file_editor', 'concatenate', 'salts', 'pingbacks', 'permissions', 'languages', 'prefix', 'admin_login'];

    public const ONE_WAY = ['salts', 'permissions', 'languages'];

    public const BEGIN = '# BEGIN ISPCP WORDPRESS ';

    public const END = '# END ISPCP WORDPRESS ';

    public static function identity(array $site, string $root): string
    {
        return hash('sha256', implode('|', array_map(static fn ($key) => (string) ($site[$key] ?? ''), ['domain_id', 'server_id', 'sys_groupid', 'domain', 'system_user', 'system_group'])).'|'.$root);
    }

    public static function path(string $path): string
    {
        if ($path !== '' && (! preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9_./-]*\z~D', $path) || str_contains($path, '//') || array_intersect(explode('/', $path), ['.', '..']) || str_ends_with($path, '/'))) {
            throw new InvalidArgumentException('This installation path cannot safely be used in Apache protections.');
        }

        return $path;
    }

    public static function id(string $path): string
    {
        return substr(hash('sha256', $path), 0, 32);
    }

    public static function compile(string $path, array $measures): string
    {
        self::path($path);
        if (array_diff($measures, self::SERVER)) {
            throw new InvalidArgumentException('Unknown Apache measure.');
        }
        $measures = array_values(array_intersect(self::SERVER, $measures));
        if (! $measures) {
            return '';
        }
        $id = self::id($path);
        $prefix = '/'.($path === '' ? '' : preg_quote($path, '~').'/');
        $lines = [self::BEGIN.$id.' '.base64_encode(json_encode(['path' => $path, 'measures' => $measures], JSON_THROW_ON_ERROR))];
        $patterns = [
            'xmlrpc' => 'xmlrpc\.php(?:/|$)',
            'config' => 'wp-config\.php(?:/|$)',
            'htfiles' => '(?:[^/]+/)*\.ht(?:access|passwd)(?:/|$)',
            'sensitive' => '(?:wp-config(?:-sample)?\.php|wp-admin/(?:install|setup-config)\.php)(?:/|$)',
            'potential' => '(?:.*(?:\.(?:sql|bak|old|orig|log|swp|dump|ini|env|zip|tar|tgz|gz|bz2|7z)(?:\.(?:gz|zip|bz2))?|~)|(?:[^/]+/)*(?:\.git|\.svn|\.env|readme\.html|license\.txt))(?:/|$)',
            'includes_php' => 'wp-includes/.*\.(?:php[0-9]?|phtml|phar)(?:/|$)',
            'uploads_php' => 'wp-content/uploads/.*\.(?:php[0-9]?|phtml|phar)(?:/|$)',
            'cache_php' => '(?:wp-content/(?:cache|w3tc-config)|cache)/.*\.(?:php[0-9]?|phtml|phar)(?:/|$)',
        ];
        foreach ($measures as $measure) {
            $lines[] = '# ISPCP WP '.$id.' '.$measure;
            $staticFolder = ['includes_php' => 'wp-includes/', 'uploads_php' => 'wp-content/uploads/', 'cache_php' => '(?:wp-content/(?:cache|w3tc-config)|cache)/'][$measure] ?? null;
            if ($staticFolder !== null) {
                $lines[] = '<LocationMatch "(?i)^'.$prefix.$staticFolder.'">';
                $lines[] = '  <If "true">';
                $lines[] = '    SetHandler default-handler';
                $lines[] = '    Options -ExecCGI';
                $lines[] = '  </If>';
                $lines[] = '</LocationMatch>';
            }
            if (isset($patterns[$measure])) {
                $lines[] = '<LocationMatch "(?i)^'.$prefix.$patterns[$measure].'">';
                $lines[] = '  <If "true">';
                $lines[] = '    Require all denied';
                $lines[] = '  </If>';
                $lines[] = '</LocationMatch>';
            } elseif ($measure === 'indexes') {
                $lines[] = '<LocationMatch "^'.$prefix.'">';
                $lines[] = '  <If "true">';
                $lines[] = '    Options -Indexes';
                $lines[] = '  </If>';
                $lines[] = '</LocationMatch>';
            } else {
                $lines[] = '<LocationMatch "^'.$prefix.'">';
                $condition = $measure === 'author'
                    ? 'unescape(%{QUERY_STRING}) =~ m#(?:^|[&;])author=[+ ]*[0-9]+(?:[&;]|$)#'
                    : '%{HTTP_USER_AGENT} =~ m#(?i)(?:sqlmap|nikto|masscan|wpscan)#';
                $lines[] = '  <If "'.$condition.'">';
                $lines[] = '    Require all denied';
                $lines[] = '  </If>';
                $lines[] = '</LocationMatch>';
            }
        }
        $lines[] = self::END.$id;

        return implode("\n", $lines)."\n";
    }

    public static function blocks(string $directives): array
    {
        preg_match_all('~^'.preg_quote(self::BEGIN, '~').'([a-f0-9]{32}) ([A-Za-z0-9+/=]+)\r?\n.*?^'.preg_quote(self::END, '~').'\1\r?(?:\n|$)~ms', $directives, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $blocks = [];
        foreach ($matches as $match) {
            $meta = json_decode(base64_decode($match[2][0], true) ?: '', true);
            if (! is_array($meta) || ! is_string($meta['path'] ?? null) || ! is_array($meta['measures'] ?? null) || self::id($meta['path']) !== $match[1][0] || isset($blocks[$match[1][0]])
                || rtrim(str_replace("\r\n", "\n", $match[0][0])) !== rtrim(self::compile($meta['path'], $meta['measures']))) {
                throw new InvalidArgumentException('The managed WordPress configuration changed.');
            }
            $blocks[$match[1][0]] = $meta + ['raw' => $match[0][0]];
        }
        if (substr_count($directives, self::BEGIN) !== count($blocks) || substr_count($directives, self::END) !== count($blocks)) {
            throw new InvalidArgumentException('The managed WordPress configuration is malformed.');
        }

        return $blocks;
    }

    public static function replace(string $directives, string $path, array $measures): string
    {
        $blocks = self::blocks($directives);
        $replacement = self::compile($path, $measures);
        $old = $blocks[self::id($path)]['raw'] ?? null;
        if ($old !== null) {
            return str_replace($old, $replacement, $directives);
        }

        return $directives.($directives !== '' && ! str_ends_with($directives, "\n") ? "\n" : '').$replacement;
    }
}
