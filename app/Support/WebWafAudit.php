<?php

namespace App\Support;

use RuntimeException;

/** Bounded JSON audit reader. Only normalized metadata may leave the web server. */
final class WebWafAudit
{
    private const PARSER_VERSION = 2;

    public static function catalog(string $directory = '/usr/share/modsecurity-crs/rules'): array
    {
        $result = [];
        foreach (glob($directory.'/*.conf') ?: [] as $file) {
            $stat = lstat($file);
            if (! $stat || is_link($file) || $stat['uid'] !== 0 || ($stat['mode'] & 0022) || $stat['size'] > 2097152) {
                continue;
            }
            $text = preg_replace('/\\\\\r?\n[ \t]*/', ' ', file_get_contents($file));
            foreach (explode("\n", $text) as $line) {
                if (str_starts_with(ltrim($line), '#')) {
                    continue;
                }
                if (preg_match('/\bid:[\x27"]?([0-9]+)/', $line, $id) && preg_match('/\bmsg:\x27((?:\\\\.|[^\x27])*)\x27/', $line, $msg)) {
                    // Use the static rule template, not an interpolated attacker-controlled message.
                    $result[(int) $id[1]] = self::text(preg_replace('/%\{[^}]*\}/', '[value]', $msg[1]), 512);
                }
            }
        }

        return $result;
    }

    private static function text(string $value, int $limit): string
    {
        return mb_strcut(preg_replace('/[\x00-\x1f\x7f]/', ' ', mb_convert_encoding($value, 'UTF-8', 'UTF-8')), 0, $limit, 'UTF-8');
    }

    /** Only known CRS summary formats may supply dynamic values, and only bounded integers. */
    public static function description(int $id, string $template, string $rendered = ''): string
    {
        $labels = [949110 => 'Inbound anomaly score exceeded', 959100 => 'Outbound anomaly score exceeded',
            980130 => 'Inbound anomaly score exceeded', 980140 => 'Outbound anomaly score exceeded'];
        if (! isset($labels[$id])) {
            return $template;
        }
        $number = '([0-9]{1,9})';
        $categories = ['SQLI', 'XSS', 'RFI', 'LFI', 'RCE', 'PHPI', 'HTTP', 'SESS'];
        if ($id === 980130) {
            $pattern = 'Inbound Anomaly Score Exceeded \(Total Inbound Score: '.$number.' - '.implode(',', array_map(fn ($name) => $name.'='.$number, $categories)).'\): individual paranoia level scores: '.implode(', ', array_fill(0, 4, $number));
        } elseif ($id === 980140) {
            $pattern = 'Outbound Anomaly Score Exceeded \(score '.$number.'\): individual paranoia level scores: '.implode(', ', array_fill(0, 4, $number));
        } else {
            $pattern = ($id === 949110 ? 'Inbound' : 'Outbound').' Anomaly Score Exceeded \(Total Score: '.$number.'\)';
        }
        if (! preg_match('/\A'.$pattern.'\z/D', $rendered, $scores)) {
            return $labels[$id];
        }
        $details = ['total: '.(int) $scores[1]];
        if ($id === 980130) {
            foreach ($categories as $index => $name) {
                if ((int) $scores[$index + 2] > 0) {
                    $details[] = $name.': '.(int) $scores[$index + 2];
                }
            }
        }

        return $labels[$id].' ('.implode('; ', $details).')';
    }

    public static function blockedTransactions(string $text): array
    {
        $ids = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/ModSecurity: Access denied[^\r\n]*\[unique_id "([A-Za-z0-9_.@\-]{1,128})"\]/', $line, $m)) {
                $ids[$m[1]] = true;
            }
        }

        return $ids;
    }

    public static function events(array $record, array $catalog = [], array $blockedTransactions = []): array
    {
        $tx = $record['transaction'] ?? [];
        if (! is_array($tx)) {
            return [];
        }
        $nginx = isset($tx['messages']);
        $messages = $nginx ? $tx['messages'] : ($record['audit_data']['messages'] ?? []);
        if (! is_array($messages)) {
            return [];
        }
        $unique = (string) ($tx['unique_id'] ?? $tx['transaction_id'] ?? '');
        if ($unique === '' || strlen($unique) > 128) {
            return [];
        }
        $mode = $nginx ? ($tx['producer']['secrules_engine'] ?? '') : ($record['audit_data']['engine_mode'] ?? '');
        $blocked = $nginx ? isset($blockedTransactions[$unique]) : str_contains(implode("\n", array_filter($messages, 'is_string')), 'Access denied with code');
        $blocked = $blocked && in_array(strtolower((string) $mode), ['on', 'enabled'], true);
        $request = $nginx ? ($tx['request'] ?? []) : ($record['request'] ?? []);
        $line = explode(' ', (string) ($request['request_line'] ?? ''), 3);
        $method = (string) ($request['method'] ?? $line[0] ?? '');
        $uri = (string) ($request['uri'] ?? $line[1] ?? '/');
        $path = self::text(rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/')), 512);
        $ip = (string) ($tx['client_ip'] ?? $tx['remote_address'] ?? '');
        $date = (string) ($tx['time_stamp'] ?? $tx['time'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s.u O', $date);
        $occurred = $parsed ? $parsed->getTimestamp() : strtotime($date);
        if ($occurred === false || $occurred > time() + 300 || $occurred < time() - 7 * 86400) {
            return [];
        }
        $out = [];
        foreach (array_slice($messages, 0, 100) as $message) {
            if ($nginx && is_array($message)) {
                $details = $message['details'] ?? [];
                $id = (int) ($details['ruleId'] ?? 0);
                $data = (string) ($details['data'] ?? '');
                $severity = (string) ($details['severity'] ?? '');
                $rendered = is_string($message['message'] ?? null) ? $message['message'] : '';
            } elseif (is_string($message)) {
                if (! preg_match('/\[id "([0-9]+)"\]/', $message, $match)) {
                    continue;
                }
                $id = (int) $match[1];
                $data = $message;
                preg_match('/\[severity "([A-Z0-9_]+)"\]/', $message, $level);
                $severity = $level[1] ?? '';
                // Deliberately reject escaped/dynamic text instead of decoding arbitrary log values.
                preg_match('/\[msg "([^"\\\\]*)"\]/', $message, $renderedMessage);
                $rendered = $renderedMessage[1] ?? '';
            } else {
                continue;
            }
            if ($id < 1 || $id > 2147483647) {
                continue;
            }
            // Apache ends the operator message with a full stop, before its
            // metadata fields. Consume exactly that delimiter, not real dots
            // in the argument name (including a genuine trailing dot).
            if ($nginx || ! preg_match('/\bat ARGS:([A-Za-z0-9_.\-]{1,128})\.(?= \[(?:file|id|line) ")/', $data, $parameter)) {
                preg_match('/\bARGS:([A-Za-z0-9_.\-]{1,128})(?=[:\s\x27"\)])/', $data, $parameter);
            }
            $out[$id] = ['event_key' => hash('sha256', $unique.':'.$id), 'occurred_at' => $occurred, 'rule_id' => $id,
                'outcome' => $blocked ? 'blocked' : 'detected', 'client_ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
                'method' => preg_match('/\A[A-Z]{1,16}\z/D', $method) ? $method : '', 'path' => $path,
                'parameter' => $parameter[1] ?? '', 'message' => self::description($id, $catalog[$id] ?? 'Rule '.$id.' matched', $rendered),
                'severity' => preg_match('/\A[A-Za-z0-9_]{0,32}\z/D', $severity) ? $severity : '',
                'source' => isset($catalog[$id]) ? 'owasp' : ($id >= 300000 && $id <= 399999 ? 'atomic' : 'other')];
        }

        return array_values($out);
    }

    /** Root-owned fixed identity paths only. Never accepts a path from the client or a log record. */
    private static function open(string $identity, string $suffix, string $root)
    {
        if (! preg_match('/\A[1-9][0-9]*-[a-f0-9]{24}\z/D', $identity) || ! in_array($suffix, ['json', 'error'], true)) {
            throw new RuntimeException('waf_log_unavailable');
        }
        $dir = lstat($root);
        if (! $dir || is_link($root) || $dir['uid'] !== 0 || ($dir['mode'] & 0077)) {
            throw new RuntimeException('waf_log_unavailable');
        }
        $path = $root.'/'.$identity.'.'.$suffix;
        $stat = @lstat($path);
        if (! $stat) {
            return null;
        }
        if (is_link($path) || $stat['uid'] !== 0 || ($stat['mode'] & 0022) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('waf_log_unavailable');
        }
        $handle = fopen($path, 'rb');
        $opened = fstat($handle);
        if ($opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']) {
            fclose($handle);
            throw new RuntimeException('waf_log_unavailable');
        }

        return $handle;
    }

    public static function read(string $identity, array $position, array $catalog, string $root = '/var/log/ispconfig-waf'): array
    {
        $blocked = [];
        $error = self::open($identity, 'error', $root);
        if ($error) {
            $stat = fstat($error);
            fseek($error, max(0, $stat['size'] - 2097152));
            $blocked = self::blockedTransactions(stream_get_contents($error, 2097152));
            fclose($error);
        }
        $file = self::open($identity, 'json', $root);
        if (! $file) {
            return ['events' => [], 'position' => []];
        }
        try {
            $stat = fstat($file);
            $offset = ($position['parser_version'] ?? null) === self::PARSER_VERSION
                && ($position['inode'] ?? null) === $stat['ino'] && ($position['offset'] ?? 0) <= $stat['size'] ? (int) ($position['offset'] ?? 0) : 0;
            // Bound work even after a burst/rotation. Newline-delimited audit JSON is emitted by both engines.
            if ($stat['size'] - $offset > 4194304) {
                $offset = $stat['size'] - 4194304;
                fseek($file, $offset);
                fgets($file, 2097153);
                $offset = ftell($file);
            }
            fseek($file, $offset);
            $events = [];
            $bytes = 0;
            while ($bytes < 2097152 && count($events) < 1000 && ($line = fgets($file, 2097153)) !== false) {
                $bytes += strlen($line);
                if (! str_ends_with($line, "\n")) {
                    if (feof($file)) {
                        break;
                    } $offset = ftell($file);

                    continue;
                }
                $offset = ftell($file);
                $record = json_decode($line, true, 32);
                if (is_array($record)) {
                    $events = array_merge($events, self::events($record, $catalog, $blocked));
                }
            }

            return ['events' => $events, 'position' => ['inode' => $stat['ino'], 'offset' => $offset, 'parser_version' => self::PARSER_VERSION]];
        } finally {
            fclose($file);
        }
    }
}
