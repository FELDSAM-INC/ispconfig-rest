<?php

namespace App\Support;

use InvalidArgumentException;

/** Pure, deliberately small rule compiler shared by the API and server acceptance tests. */
final class WebWafPolicy
{
    public const DEFAULTS = ['enabled' => false, 'mode' => 'detection', 'application_profile' => 'none', 'atomic' => false, 'exclusions' => [], 'ip_allowlist' => []];

    public const BEGIN = '# BEGIN ISPCP WAF';

    public const END = '# END ISPCP WAF';

    public static function normalize(array $input): array
    {
        if (array_diff(array_keys($input), array_keys(self::DEFAULTS))) {
            throw new InvalidArgumentException('Unsupported WAF setting.');
        }
        $input += self::DEFAULTS;
        if (! is_string($input['application_profile']) || ! in_array($input['application_profile'], ['none', ...array_keys(WebWafProfiles::FILES)], true)) {
            throw new InvalidArgumentException('Select a supported application profile.');
        }
        if (! is_bool($input['enabled']) || ! is_bool($input['atomic']) || ! in_array($input['mode'], ['detection', 'enforcing'], true)) {
            throw new InvalidArgumentException('Select a valid WAF mode.');
        }
        foreach (['exclusions', 'ip_allowlist'] as $key) {
            if (! is_array($input[$key]) || ! array_is_list($input[$key]) || count($input[$key]) > 100) {
                throw new InvalidArgumentException('Use at most 100 WAF exceptions of each kind.');
            }
        }
        $exclusions = [];
        foreach ($input['exclusions'] as $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['rule_id', 'path', 'parameter']) || ! is_int($row['rule_id'] ?? null) || $row['rule_id'] < 1 || $row['rule_id'] > 2147483647) {
                throw new InvalidArgumentException('Use a numeric rule ID.');
            }
            $path = $row['path'] ?? '';
            $parameter = $row['parameter'] ?? '';
            if (! is_string($path) || strlen($path) > 512 || ($path !== '' && ! preg_match('~\A/[a-zA-Z0-9/_.:@+,%=\-]*\z~D', $path))) {
                throw new InvalidArgumentException('Use an exact URL path without a query string or special rule characters.');
            }
            if (! is_string($parameter) || ! preg_match('/\A[a-zA-Z0-9_.\-]{0,128}\z/D', $parameter)) {
                throw new InvalidArgumentException('Use a plain request argument name.');
            }
            $entry = ['rule_id' => $row['rule_id'], 'path' => $path, 'parameter' => $parameter];
            $exclusions[json_encode($entry)] = $entry;
        }
        ksort($exclusions);
        $input['exclusions'] = array_values($exclusions);
        $ips = [];
        foreach ($input['ip_allowlist'] as $ip) {
            if (! is_string($ip) || strlen($ip) > 49) {
                throw new InvalidArgumentException('Use valid IP addresses or CIDRs.');
            }
            $parts = explode('/', $ip);
            if (count($parts) > 2 || ! filter_var($parts[0], FILTER_VALIDATE_IP) || (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] > (str_contains($parts[0], ':') ? 128 : 32)))) {
                throw new InvalidArgumentException('Use valid IP addresses or CIDRs.');
            }
            $ips[] = strtolower($ip);
        }
        $input['ip_allowlist'] = array_values(array_unique($ips));
        sort($input['ip_allowlist']);

        return array_intersect_key(array_replace(self::DEFAULTS, $input), self::DEFAULTS);
    }

    public static function identity(array $site): string
    {
        return (int) ($site['domain_id'] ?? $site['id'] ?? 0).'-'.substr(hash('sha256', implode(':', [$site['server_id'], $site['sys_groupid'], $site['domain']])), 0, 24);
    }

    public static function rules(array $settings, string $identity): string
    {
        $settings = self::normalize($settings);
        if (! preg_match('/\A[1-9][0-9]*-[a-f0-9]{24}\z/D', $identity)) {
            throw new InvalidArgumentException('Invalid website identity.');
        }
        $lines = ['Include /etc/ispconfig-waf/base.conf'];
        $lines[] = 'SecAction "id:19998,phase:1,pass,t:none,nolog,setvar:tx.ispcp_application_profile='.$settings['application_profile'].'"';
        if ($settings['ip_allowlist']) {
            $lines[] = 'SecRule REMOTE_ADDR "@ipMatch '.implode(',', $settings['ip_allowlist']).'" "id:19999,phase:1,pass,nolog,ctl:ruleEngine=Off"';
        }
        foreach ($settings['exclusions'] as $index => $entry) {
            $action = $entry['parameter'] === '' ? 'ctl:ruleRemoveById='.$entry['rule_id'] : 'ctl:ruleRemoveTargetById='.$entry['rule_id'].';ARGS:'.$entry['parameter'];
            $options = 'id:'.(20000 + $index).',phase:1,pass,nolog,'.$action;
            $lines[] = $entry['path'] === '' ? 'SecAction "'.$options.'"' : 'SecRule REQUEST_FILENAME "@streq '.$entry['path'].'" "'.$options.'"';
        }
        $lines[] = 'Include /etc/ispconfig-waf/owasp.conf';
        if ($settings['atomic']) {
            $lines[] = 'Include /etc/ispconfig-waf/atomic.conf';
        }
        // Set these after vendor rules: a feed must not change the website's selected mode/log path.
        $lines[] = 'SecRuleEngine '.(! $settings['enabled'] ? 'Off' : ($settings['mode'] === 'enforcing' ? 'On' : 'DetectionOnly'));
        $lines[] = 'SecAuditEngine RelevantOnly';
        $lines[] = 'SecAuditLogFormat JSON';
        $lines[] = 'SecAuditLogType Serial';
        $lines[] = 'SecAuditLogParts ABFHZ';
        $lines[] = 'SecAuditLog /var/log/ispconfig-waf/'.$identity.'.json';

        return implode("\n", $lines)."\n";
    }

    public static function compile(array $site, string $engine, array $settings): string
    {
        $settings = self::normalize($settings);
        $identity = self::identity($site);
        $rules = self::rules($settings, $identity);
        $marker = self::BEGIN.' '.base64_encode(json_encode(['identity' => $identity, 'settings' => $settings], JSON_THROW_ON_ERROR));
        if (! $settings['enabled']) {
            return $marker."\n".self::END."\n";
        }
        if ($engine === 'apache') {
            return $marker."\n<IfModule security2_module>\nSecRuleInheritance Off\n".$rules."</IfModule>\n".self::END."\n";
        }
        if ($engine !== 'nginx') {
            throw new InvalidArgumentException('Unsupported web server.');
        }

        return $marker."\nmodsecurity on;\nerror_log /var/log/ispconfig-waf/".$identity.".error warn;\nmodsecurity_rules '\n".$rules."';\n".self::END."\n";
    }

    public static function extract(string $text, string $identity): ?array
    {
        if (! str_contains($text, self::BEGIN)) {
            return null;
        }
        if (! preg_match('/^'.preg_quote(self::BEGIN, '/').' ([A-Za-z0-9+\/=]+)\r?$/m', $text, $match)) {
            throw new InvalidArgumentException('The managed WAF configuration was changed externally.');
        }
        $data = json_decode((string) base64_decode($match[1], true), true);
        if (! is_array($data) || ($data['identity'] ?? '') !== $identity || ! is_array($data['settings'] ?? null)) {
            throw new InvalidArgumentException('The website identity or managed WAF configuration changed.');
        }

        return self::normalize($data['settings']);
    }

    public static function replace(string $text, string $block): string
    {
        $remaining = preg_replace('/^'.preg_quote(self::BEGIN, '/').' [A-Za-z0-9+\/=]+\R.*?^'.preg_quote(self::END, '/').'\R?/ms', '', $text, -1, $count);
        if ($count > 1 || str_contains($remaining, self::BEGIN) || str_contains($remaining, self::END)) {
            throw new InvalidArgumentException('The managed WAF configuration was changed externally.');
        }

        return $remaining.($remaining !== '' && ! str_ends_with($remaining, "\n") ? "\n" : '').$block;
    }
}
