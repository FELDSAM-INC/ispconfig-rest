<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Reader/writer for the serialized INI blob in server.config, byte-format
 * compatible with legacy ISPConfig
 * (source_code/interface/lib/classes/ini_parser.inc.php).
 *
 * Writes perform a read-merge-write: the stored blob is parsed, only the
 * requested keys of the requested sections are replaced, every other
 * section/key is preserved, and the blob is written back with a plain
 * `UPDATE server SET config = ?` — exactly like legacy
 * spamfilter_config_edit.php::onUpdateSave(), which emits NO sys_datalog
 * entry (documented constitution Principle II exception, spec 005 C-8).
 */
class ServerIniConfigService
{
    /**
     * Parse an ISPConfig server.config INI string (port of legacy
     * ini_parser::parse_ini_string).
     *
     * @return array<string, array<string, string>>
     */
    public function parse(?string $ini): array
    {
        $config = [];
        $section = null;

        $ini = str_replace("\r\n", "\n", (string) $ini);

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
     * Serialize a config array back to the legacy INI format (port of
     * legacy ini_parser::get_ini_string).
     *
     * @param  array<string, array<string, string>>  $config
     */
    public function serialize(array $config): string
    {
        $content = '';

        foreach ($config as $section => $data) {
            $content .= "[{$section}]\n";

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
     * The parsed config of one server row.
     *
     * @return array<string, array<string, string>>
     */
    public function getConfig(int $serverId): array
    {
        $blob = DB::table('server')->where('server_id', $serverId)->value('config');

        return $this->parse($blob === null ? '' : (string) $blob);
    }

    /**
     * One section of a server's config, [] when absent.
     *
     * @return array<string, string>
     */
    public function getSection(int $serverId, string $section): array
    {
        return $this->getConfig($serverId)[strtolower($section)] ?? [];
    }

    /**
     * Read-merge-write: replace ONLY the given keys inside the given
     * sections, preserving all other keys and sections, then write the blob
     * back (plain UPDATE, no datalog — legacy parity, C-8).
     *
     * @param  array<string, array<string, string>>  $sections  section => [key => value]
     */
    public function mergeSections(int $serverId, array $sections): void
    {
        $config = $this->getConfig($serverId);

        foreach ($sections as $section => $values) {
            $section = strtolower($section);

            foreach ($values as $key => $value) {
                $config[$section][$key] = (string) $value;
            }
        }

        DB::table('server')
            ->where('server_id', $serverId)
            ->update(['config' => $this->serialize($config)]);
    }
}
