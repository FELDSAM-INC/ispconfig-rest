<?php

namespace App\Support;

/**
 * The canonical port of legacy ISPConfig's INI blob format
 * (source_code/interface/lib/classes/ini_parser.inc.php), shared by the
 * three services that read/write INI blobs: ServerConfigService and
 * ServerIniConfigService (the `server.config` column) and
 * SystemConfigService (the `sys_ini.config` column).
 *
 * parse() = ini_parser::parse_ini_string, serialize() =
 * ini_parser::get_ini_string. The pair is byte-faithful:
 * parse(serialize(parse($x))) is a fixed point, and serializing an
 * unmodified parse of a legacy-produced blob reproduces it byte for byte
 * (proven by the ServerConfigServiceTest round trip against a real panel
 * blob fixture).
 *
 * Format semantics (all from legacy, do not "fix"):
 *  - CRLF is normalized to LF, every line is trimmed;
 *  - [section] headers match /^\[([\w\d_]+)\]$/ and are LOWERCASED;
 *  - key=value lines match /^([\w\d_]+)=(.*)$/ (split on the FIRST '='),
 *    key and value are trimmed;
 *  - everything else — comments, junk lines, key=value lines before the
 *    first section header — is silently dropped;
 *  - serialize() writes "[section]\n", one "key=value\n" per pair (keys
 *    trimmed, empty keys skipped, values trimmed) and one blank line after
 *    every section.
 *
 * stripslashes-on-read is NOT part of parse(): legacy applies it in
 * getconf::get_server_config / get_global_config, i.e. at the read site,
 * so the services do too (see divergence 1).
 *
 * Divergences found between the three original per-service ports (the
 * ServerConfigService variant, fixture-proven, is canonical throughout):
 *
 *  1. stripslashes placement — SystemConfigService::parseBlob() had baked
 *     stripslashes() into its parser; ServerConfigService applies it at the
 *     read site around a pure parse. Canonical: pure parse; both services
 *     still stripslash their blobs before parsing. ServerIniConfigService
 *     never applied stripslashes on read at all — that (pre-existing)
 *     behavior is preserved unchanged in that service.
 *  2. null tolerance — ServerIniConfigService::parse() accepted ?string
 *     and cast null to ''. Canonical: parse(string); that service casts at
 *     the call site.
 *  3. guard order — SystemConfigService evaluated the key=value preg_match
 *     BEFORE the "inside a section yet?" check; the other two checked the
 *     section first. Same observable result in every case; canonical keeps
 *     the section-first order.
 *  4. non-array section data — ServerConfigService and
 *     ServerIniConfigService cast each section's data with (array) before
 *     iterating in serialize(); SystemConfigService::buildBlob() did not
 *     (a scalar section would have been a TypeError). Canonical keeps the
 *     cast. Unreachable through parse(), which only produces arrays.
 *  5. section-name normalization on merge — only
 *     ServerIniConfigService::mergeSections() lowercased incoming section
 *     names. Canonical mergeSection() lowercases (it matches what parse()
 *     produces); the other two callers only ever pass lowercase constants,
 *     so nothing changes for them.
 */
class IniConfig
{
    /**
     * Exact port of legacy ini_parser::parse_ini_string() — see the class
     * docblock for the format semantics. Callers that mirror a legacy
     * getconf read path must stripslashes() the blob BEFORE calling this.
     *
     * @return array<string, array<string, string>> ordered sections => ordered key/value pairs
     */
    public static function parse(string $ini): array
    {
        $config = [];
        $section = null;

        $ini = str_replace("\r\n", "\n", $ini);

        foreach (explode("\n", $ini) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[([\w\d_]+)\]$/', $line, $matches)) {
                $section = strtolower($matches[1]);
            } elseif ($section !== null && preg_match('/^([\w\d_]+)=(.*)$/', $line, $matches)) {
                $config[$section][trim($matches[1])] = trim($matches[2]);
            }
        }

        return $config;
    }

    /**
     * Exact port of legacy ini_parser::get_ini_string() — see the class
     * docblock. parse(serialize(parse($x))) is a fixed point.
     *
     * @param  array<string, array<string, mixed>>  $config
     */
    public static function serialize(array $config): string
    {
        $content = '';

        foreach ($config as $section => $data) {
            $content .= "[$section]\n";

            foreach ((array) $data as $item => $value) {
                $item = trim((string) $item);

                if ($item !== '') {
                    $content .= $item.'='.trim((string) $value)."\n";
                }
            }

            $content .= "\n";
        }

        return $content;
    }

    /**
     * Byte-safe single-section merge, the read-merge-write core the legacy
     * *_config_edit.php::onUpdateSave() handlers share: set/overwrite ONLY
     * the given keys inside the given section and leave everything else —
     * other sections, unknown keys, and their order — untouched, so an
     * unmodified remainder re-serializes byte-for-byte. Existing keys keep
     * their position; new keys are appended in input order; a section not
     * yet in $config is appended at the end. The section name is lowercased
     * to match parse() output (divergence 5).
     *
     * @param  array<string, array<string, string>>  $config  a parse() result
     * @param  array<string, mixed>  $values  key => value to set (values cast to string)
     * @return array<string, array<string, string>>
     */
    public static function mergeSection(array $config, string $section, array $values): array
    {
        $section = strtolower($section);

        foreach ($values as $key => $value) {
            $config[$section][$key] = (string) $value;
        }

        return $config;
    }
}
