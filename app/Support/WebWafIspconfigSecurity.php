<?php

namespace App\Support;

use RuntimeException;

/** Retain ISPConfig's directive blacklist, exempting only our literal root-owned includes. */
final class WebWafIspconfigSecurity
{
    public const INCLUDES = [
        'Include /etc/ispconfig-waf/base.conf',
        'Include /etc/ispconfig-waf/owasp.conf',
        'Include /etc/ispconfig-waf/atomic.conf',
    ];

    public static function merge(string $vendor, string $custom = ''): string
    {
        if (trim($vendor) === '') {
            throw new RuntimeException('The ISPConfig vendor blacklist is empty.');
        }
        $output = [];
        // A .custom file replaces the vendor list in ISPConfig. Re-merge vendor rules
        // on every install so an upgrade does not silently lose new upstream blocks.
        foreach (explode("\n", $vendor."\n".$custom) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (@preg_match($rule, '') === false) {
                throw new RuntimeException('Invalid ISPConfig directive blacklist expression; existing files were left unchanged.');
            }
            if (array_filter(self::INCLUDES, static fn ($line) => preg_match($rule, $line) === 1)) {
                $delimiter = $rule[0];
                // Do not guess the structure of paired delimiters or exotic expressions.
                if (! in_array($delimiter, ['/', '~', '#', '%', '@', '!'], true)) {
                    throw new RuntimeException('Unsupported custom blacklist delimiter; review its WAF include restrictions manually.');
                }
                $end = strrpos($rule, $delimiter);
                $allowed = implode('|', array_map(static fn ($line) => preg_quote($line, $delimiter), self::INCLUDES));
                // SKIP/FAIL prevents an unanchored custom pattern retrying inside an allowed
                // line. No capturing groups are added; existing backreferences stay intact.
                // Disable inherited case/multiline/extended modes for the exact exception.
                $rule = $delimiter.'(?-imsx:\A(?:'.$allowed.')\z)(*SKIP)(*F)|(?:'.substr($rule, 1, $end - 1).')'.substr($rule, $end);
                if (@preg_match($rule, '') === false) {
                    throw new RuntimeException('Cannot safely adapt this custom blacklist expression; existing files were left unchanged.');
                }
            }
            $output[$rule] = true;
        }

        return implode("\n", array_keys($output))."\n";
    }
}
