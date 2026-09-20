<?php

declare(strict_types=1);

namespace IspconfigRest\Worker;

/** Lex SQL without interpreting string data. Normalize object header DEFINERs only. */
final class SqlDump
{
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
