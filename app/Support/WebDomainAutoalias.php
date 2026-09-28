<?php

namespace App\Support;

/** ISPConfig's four autoalias placeholders; no URL or wildcard is accepted. */
final class WebDomainAutoalias
{
    public static function resolve(string $pattern, array $site, int $clientId = 0, string $username = ''): ?string
    {
        $alias = str_replace(
            ['[client_id]', '[website_id]', '[client_username]', '[website_domain]'],
            [(string) $clientId, (string) ($site['domain_id'] ?? ''), $username, (string) ($site['domain'] ?? '')],
            $pattern
        );

        return $alias !== '' && filter_var($alias, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false ? $alias : null;
    }
}
