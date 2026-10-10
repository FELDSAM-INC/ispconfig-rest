<?php

namespace App\Support;

use RuntimeException;

/**
 * Catalog zone (RFC 9432, schema version 2) of an ISPConfig DNS server, so secondaries provision and remove its zones
 * by themselves (spec 055). Members are the primary zones ISPConfig writes to named.conf.local, whatever created them:
 * the ISPConfig interface, its remote API or ispconfig-rest. A zone with its own allow-transfer list is left out: it
 * is meant for another secondary. Pure text work for the dns-catalog server tool, so it must not depend on Laravel.
 */
final class DnsCatalog
{
    public const KEY_FILE = '/etc/bind/ispcp-catalog.key';

    public const CONF_FILE = '/etc/bind/named.conf.ispcp-catalog';

    public const ZONE_FILE = '/var/lib/bind/ispcp-catalog.db';

    public const INCLUDE = 'include "'.self::CONF_FILE.'";';

    /**
     * Primary zones of `named-checkconf -p` output, and those left out for their own transfer list.
     *
     * @return array{members: string[], excluded: string[]}
     */
    public static function zones(string $checkconf, string $catalog = ''): array
    {
        $members = [];
        $excluded = [];
        $zone = null;
        foreach (preg_split('/\R/', $checkconf) as $line) {
            if (preg_match('/^zone "((?:[^"\\\\]|\\\\.)+)"(?: [A-Za-z]+)? \{$/', $line, $match) === 1) {
                $zone = ['name' => self::name(stripcslashes($match[1])), 'primary' => false, 'transfer' => false];
            } elseif ($zone !== null && preg_match('/^\ttype (master|primary);$/', $line) === 1) {
                $zone['primary'] = true;
            } elseif ($zone !== null && preg_match('/^\tallow-transfer\s/', $line) === 1) {
                $zone['transfer'] = true;
            } elseif ($zone !== null && $line === '};') {
                if ($zone['primary'] && $zone['name'] !== null && $zone['name'] !== self::name($catalog)) {
                    $zone['transfer'] ? $excluded[] = $zone['name'] : $members[] = $zone['name'];
                }
                $zone = null;
            }
        }
        sort($members);
        sort($excluded);

        return ['members' => array_values(array_unique($members)), 'excluded' => array_values(array_unique($excluded))];
    }

    /** Lower-case name without the final dot; null for anything that is not a plain DNS name. */
    public static function name(string $name): ?string
    {
        $name = strtolower(rtrim($name, '.'));

        return $name !== '' && strlen($name) <= 253 && preg_match('/\A(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\z/D', $name) === 1 ? $name : null;
    }

    /** Stable member label: the zone keeps its ID, so a secondary never resets it while it stays a member. */
    public static function memberId(string $zone): string
    {
        return sha1($zone.'.');
    }

    /** The catalog zone file; members sorted, so equal membership renders equal text apart from the serial. */
    public static function render(string $catalog, array $zones, int $serial): string
    {
        sort($zones);
        $lines = [
            '; Managed by ispconfig-dns-catalog: the primary zones of named.conf.local. Do not edit.',
            '$ORIGIN '.$catalog.'.',
            '$TTL 3600',
            // Without NOTIFY a secondary still sees changes within five minutes (refresh).
            '@ IN SOA invalid. invalid. ( '.$serial.' 300 60 604800 0 )',
            '@ IN NS invalid.',
            'version IN TXT "2"',
        ];
        foreach ($zones as $zone) {
            $lines[] = self::memberId($zone).'.zones IN PTR '.$zone.'.';
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array{serial: ?int, members: string[]} of a catalog zone file this tool wrote */
    public static function current(string $text): array
    {
        $serial = preg_match('/^@ IN SOA invalid\. invalid\. \( ([0-9]{1,10}) /m', $text, $match) === 1 ? (int) $match[1] : null;
        preg_match_all('/^[0-9a-f]{40}\.zones IN PTR (\S+)\.$/m', $text, $matches);
        $members = $matches[1];
        sort($members);

        return ['serial' => $serial, 'members' => $members];
    }

    /** Monotonic serial: the time, or one more than the last one when that is ahead. */
    public static function nextSerial(?int $current, int $now): int
    {
        $next = max(($current ?? 0) + 1, $now);
        if ($next > 4294967295) {
            throw new RuntimeException('The catalog zone serial is exhausted.');
        }

        return $next;
    }

    /**
     * The catalog zone's own configuration: transfers with the TSIG key only, NOTIFY to the registered secondaries.
     *
     * @param string[] $notify secondary addresses
     */
    public static function configuration(string $catalog, string $key, array $notify): string
    {
        $lines = [
            '// Managed by ispconfig-dns-catalog (spec 055). Change it with the ispconfig-dns-catalog command.',
            'include "'.self::KEY_FILE.'";',
            'zone "'.$catalog.'" {',
            "\ttype primary;",
            "\tfile \"".self::ZONE_FILE.'";',
            "\tallow-transfer { key \"".$key.'"; };',
        ];
        if ($notify === []) {
            $lines[] = "\tnotify no;";
        } else {
            $lines[] = "\tnotify explicit;";
            $lines[] = "\talso-notify { ".implode(' ', array_map(static fn (string $address): string => $address.' key "'.$key.'";', $notify)).' };';
        }
        $lines[] = '};';

        return implode("\n", $lines)."\n";
    }

    /**
     * named.conf.options with the key added to the global allow-transfer list, so secondaries may transfer every member
     * zone; null when it is there already. ISPConfig's `allow-transfer {none;};` becomes the key alone.
     */
    public static function allowTransfer(string $options, string $key): ?string
    {
        $entry = 'key "'.$key.'";';
        $text = (string) preg_replace('~//[^\n]*|#[^\n]*|/\*.*?\*/~s', '', $options);
        if (preg_match('/^\s*options\s*\{/m', $text) !== 1) {
            throw new RuntimeException('named.conf.options has no options block.');
        }
        if (preg_match('/allow-transfer\s*\{([^{}]*)\}\s*;/', $text, $match) === 1) {
            if (str_contains(preg_replace('/\s+/', ' ', $match[1]), $entry)) {
                return null;
            }
            // Edit the statement where it is written, never a commented copy of it.
            if (preg_match_all('/allow-transfer\s*\{([^{}]*)\}\s*;/', $options, $all, PREG_OFFSET_CAPTURE) === 0) {
                throw new RuntimeException('The allow-transfer statement of named.conf.options could not be located.');
            }
            foreach ($all[0] as $index => [$statement, $offset]) {
                $before = substr($options, 0, $offset);
                $line = substr($before, (int) strrpos("\n".$before, "\n"));
                if (preg_match('~(//|#)~', $line) === 1 || substr_count($before, '/*') > substr_count($before, '*/')) {
                    continue;
                }
                $inner = trim($all[1][$index][0]);
                $replacement = preg_match('/\A"?none"?\s*;?\z/', $inner) === 1 ? 'allow-transfer { '.$entry.' };' : 'allow-transfer { '.$entry.' '.$inner.' };';

                return substr($options, 0, $offset).$replacement.substr($options, $offset + strlen($statement));
            }
            throw new RuntimeException('The allow-transfer statement of named.conf.options could not be located.');
        }

        return (string) preg_replace('/^(\s*options\s*\{[^\n]*\n)/m', "$1\tallow-transfer { ".$entry." };\n", $options, 1);
    }

    public const NOTIFY_MARK = '// ispconfig-dns-catalog';

    /**
     * named.conf.options with the managed global also-notify line for the registered secondaries, so they hear of
     * member zone changes also when the zones' NS records do not name them (a hidden secondary); null when unchanged.
     * An also-notify list of the administrator's own is left alone (false).
     *
     * @param string[] $addresses
     * @return string|false|null
     */
    public static function alsoNotify(string $options, array $addresses, string $key)
    {
        $managed = '/^[ \t]*also-notify[^\n]*'.preg_quote(self::NOTIFY_MARK, '/').'[ \t]*\n/m';
        $without = (string) preg_replace($managed, '', $options);
        $code = (string) preg_replace('~//[^\n]*|#[^\n]*|/\*.*?\*/~s', '', $without);
        if (preg_match('/\balso-notify\s*\{/', $code) === 1) {
            return false;
        }
        $result = $without;
        if ($addresses !== []) {
            $line = "\talso-notify { ".implode(' ', array_map(static fn (string $address): string => $address.' key "'.$key.'";', $addresses)).' }; '.self::NOTIFY_MARK."\n";
            $result = (string) preg_replace('/^(\s*options\s*\{[^\n]*\n)/m', '$1'.str_replace('$', '\\$', $line), $without, 1);
        }

        return $result === $options ? null : $result;
    }

    /** named.conf with the include of the catalog configuration; null when it has it already. */
    public static function withInclude(string $namedConf): ?string
    {
        if (str_contains($namedConf, self::INCLUDE)) {
            return null;
        }

        return rtrim($namedConf, "\n")."\n".self::INCLUDE."\n";
    }
}
