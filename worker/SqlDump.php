<?php

declare(strict_types=1);

namespace IspconfigRest\Worker;

/** Lex SQL without interpreting string data. Normalize object header DEFINERs only. */
final class SqlDump
{
    /** Stream generated dumps with bounded memory, including multi-gigabyte string/BLOB literals. */
    public static function stream($input, $output, ?string $source = null, ?string $target = null, ?callable $progress = null): void
    {
        $buffer = '';
        $state = 'code';
        $quote = '';
        $header = false;
        $bytes = 0;
        do {
            $read = fread($input, 131072);
            if ($read === false) {
                throw new \RuntimeException('invalid_dump');
            }
            $buffer .= $read;
            $final = feof($input);
            // Lookahead is bounded: native identifiers/accounts are at most a few hundred bytes.
            $limit = $final ? strlen($buffer) : max(0, strlen($buffer) - 4096);
            $i = 0;
            $result = '';
            while ($i < $limit) {
                if ($state === 'quoted') {
                    $run = strcspn($buffer, $quote.'\\', $i, $limit - $i);
                    $result .= substr($buffer, $i, $run);
                    $i += $run;
                    if ($i >= $limit) {
                        continue;
                    }
                    $character = $buffer[$i++];
                    $result .= $character;
                    if ($character === '\\' || ($buffer[$i] ?? '') === $quote) {
                        if ($i < strlen($buffer)) {
                            $result .= $buffer[$i++];
                        }
                    } else {
                        $state = 'code';
                    }

                    continue;
                }
                if ($state === 'comment' || $state === 'line') {
                    $marker = $state === 'comment' ? '*/' : "\n";
                    $end = strpos($buffer, $marker, $i);
                    if ($end === false || $end >= $limit) {
                        $result .= substr($buffer, $i, $limit - $i);
                        $i = $limit;
                    } else {
                        $end += strlen($marker);
                        $result .= substr($buffer, $i, $end - $i);
                        $i = $end;
                        $state = 'code';
                    }

                    continue;
                }
                if ($state === 'word') {
                    $run = strspn($buffer, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_', $i, $limit - $i);
                    $result .= substr($buffer, $i, $run);
                    $i += $run;
                    if ($i < $limit) {
                        $state = 'code';
                    }

                    continue;
                }
                if (substr($buffer, $i, 2) === '/*') {
                    if (preg_match('/\G\/\*(?:!|M!)[0-9]*\s*/A', $buffer, $m, 0, $i)) {
                        $result .= $m[0];
                        $i += strlen($m[0]);
                    } else {
                        $result .= '/*';
                        $i += 2;
                        $state = 'comment';
                    }

                    continue;
                }
                if ($buffer[$i] === '#' || (substr($buffer, $i, 2) === '--' && ord($buffer[$i + 2] ?? "\n") <= 32)) {
                    $state = 'line';

                    continue;
                }
                if ($header && preg_match('/\GDEFINER\s*=\s*(?:`(?:``|[^`])*`|\x27(?:\\\\.|[^\x27])*\x27|[a-zA-Z0-9_.%-]+)\s*@\s*(?:`(?:``|[^`])*`|\x27(?:\\\\.|[^\x27])*\x27|[a-zA-Z0-9_.%-]+)/Ai', $buffer, $m, 0, $i)) {
                    $result .= 'DEFINER=CURRENT_USER';
                    $i += strlen($m[0]);

                    continue;
                }
                if ($buffer[$i] === '`' && preg_match('/\G`(?:``|[^`])*`/A', $buffer, $m, 0, $i)) {
                    $token = $m[0];
                    $i += strlen($token);
                    if ($source !== null && $target !== null && $token === '`'.$source.'`' && preg_match('/\G\s*\./A', $buffer, $dot, 0, $i)) {
                        $token = $target === '' ? '' : '`'.$target.'`';
                        if ($target === '') {
                            $i += strlen($dot[0]);
                        }
                    }
                    $result .= $token;

                    continue;
                }
                if (in_array($buffer[$i], ["'", '"', '`'], true)) {
                    $quote = $buffer[$i++];
                    $result .= $quote;
                    $state = 'quoted';

                    continue;
                }
                if (preg_match('/\G[a-zA-Z_][a-zA-Z0-9_]*/A', $buffer, $m, 0, $i)) {
                    $token = $m[0];
                    if (strlen($token) > 4096) {
                        // Hex BLOBs can continue across buffers; never treat their fragments as keywords.
                        $state = 'word';

                        continue;
                    }
                    $i += strlen($token);
                    if (strtoupper($token) === 'CREATE') {
                        $header = true;
                    } elseif (in_array(strtoupper($token), ['VIEW', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT', 'TABLE', 'DATABASE'], true)) {
                        $header = false;
                    }
                    if ($source !== null && $target !== null && $token === $source && preg_match('/\G\s*\./A', $buffer, $dot, 0, $i)) {
                        $token = $target === '' ? '' : '`'.$target.'`';
                        if ($target === '') {
                            $i += strlen($dot[0]);
                        }
                    }
                    $result .= $token;

                    continue;
                }
                if ($buffer[$i] === ';') {
                    $header = false;
                }
                $result .= $buffer[$i++];
            }
            $buffer = substr($buffer, $i);
            if (fwrite($output, $result) !== strlen($result)) {
                throw new \RuntimeException('operation_failed');
            }
            $bytes += strlen($result);
            if ($progress !== null) {
                $progress($bytes);
            }
        } while (! $final);
        if ($state === 'quoted' || $state === 'comment') {
            throw new \RuntimeException('invalid_dump');
        }
    }

    public static function portable(string $sql, ?string $source = null, ?string $target = null): string
    {
        $header = false;

        return self::scan($sql, $header, $source, $target);
    }

    private static function scan(string $sql, bool &$header, ?string $source, ?string $target): string
    {
        $output = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            // Executable comments contain SQL tokens; ordinary comments remain opaque.
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException('invalid_dump');
                }
                $comment = substr($sql, $i, $end + 2 - $i);
                if (preg_match('/^(\/\*(?:!|M!)[0-9]*\s*)(.*)(\*\/)$/s', $comment, $m)) {
                    $comment = $m[1].self::scan($m[2], $header, $source, $target).$m[3];
                }
                $output .= $comment;
                $i = $end + 2;

                continue;
            }
            if ($sql[$i] === '#' || (substr($sql, $i, 2) === '--' && ($i + 2 === $length || ord($sql[$i + 2]) <= 32))) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end + 1;
                $output .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }
            if (in_array($sql[$i], ["'", '"', '`'], true)) {
                $start = $i;
                $quote = $sql[$i++];
                while ($i < $length) {
                    if ($sql[$i] === '\\') {
                        $i += 2;
                    } elseif ($sql[$i++] === $quote) {
                        if ($i < $length && $sql[$i] === $quote) {
                            $i++;
                        } else {
                            break;
                        }
                    }
                }
                $token = substr($sql, $start, $i - $start);
                if ($quote === '`' && $source !== null && $target !== null && $token === '`'.$source.'`'
                    && preg_match('/\G\s*\./A', $sql, $unused, 0, $i)) {
                    $token = $target === '' ? '' : '`'.$target.'`';
                    if ($target === '') {
                        $i += strlen($unused[0]);
                    }
                }
                $output .= $token;

                continue;
            }
            if ($header && preg_match('/\GDEFINER\s*=\s*(?:`(?:``|[^`])*`|\x27(?:\\\\.|[^\x27])*\x27|[a-zA-Z0-9_.%-]+)\s*@\s*(?:`(?:``|[^`])*`|\x27(?:\\\\.|[^\x27])*\x27|[a-zA-Z0-9_.%-]+)/Ai', $sql, $m, 0, $i)) {
                $output .= 'DEFINER=CURRENT_USER';
                $i += strlen($m[0]);

                continue;
            }
            if (preg_match('/\G[a-zA-Z_][a-zA-Z0-9_]*/A', $sql, $m, 0, $i)) {
                $word = strtoupper($m[0]);
                if ($word === 'CREATE') {
                    $header = true;
                } elseif (in_array($word, ['VIEW', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT', 'TABLE', 'DATABASE'], true)) {
                    $header = false;
                }
                $token = $m[0];
                if ($source !== null && $target !== null && $token === $source
                    && preg_match('/\G\s*\./A', $sql, $unused, 0, $i + strlen($token))) {
                    $token = $target === '' ? '' : '`'.$target.'`';
                    if ($target === '') {
                        $i += strlen($unused[0]);
                    }
                }
                $output .= $token;
                $i += strlen($m[0]);

                continue;
            }
            if ($sql[$i] === ';') {
                $header = false;
            }
            $output .= $sql[$i++];
        }

        return $output;
    }
}
