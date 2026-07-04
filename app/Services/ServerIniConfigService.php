<?php

namespace App\Services;

use App\Support\IniConfig;
use Illuminate\Support\Facades\DB;

/**
 * Reader/writer for the serialized INI blob in server.config, byte-format
 * compatible with legacy ISPConfig — the mail module's spamfilter-config
 * view over the blob. Parsing/serialization delegate to the canonical
 * App\Support\IniConfig port of ini_parser.inc.php. Unlike the read paths
 * of ServerConfigService/SystemConfigService, this service does NOT apply
 * stripslashes() to the stored blob before parsing (see IniConfig
 * divergence 1); that pre-existing behavior is preserved.
 *
 * Writes perform a read-merge-write: the stored blob is parsed, only the
 * requested keys of the requested sections are replaced, every other
 * section/key is preserved, and the blob is written back with a plain
 * `UPDATE server SET config = ?` — exactly like legacy
 * spamfilter_config_edit.php::onUpdateSave(), which emits NO sys_datalog
 * entry (documented constitution Principle II exception, spec 005 C-8).
 *
 * WRITE DISCIPLINE — server.config has TWO writers, on purpose: this
 * service writes WITHOUT a datalog (spamfilter panel parity, above), while
 * ServerConfigService::updateSection() writes the same column THROUGH
 * Server::save() and therefore DATALOGS (server-config panel parity,
 * legacy tform datalogUpdate). Do NOT "unify" the two disciplines.
 */
class ServerIniConfigService
{
    /**
     * Parse an ISPConfig server.config INI string (legacy
     * ini_parser::parse_ini_string — canonical implementation in
     * App\Support\IniConfig::parse(); null is read as an empty blob).
     *
     * @return array<string, array<string, string>>
     */
    public function parse(?string $ini): array
    {
        return IniConfig::parse((string) $ini);
    }

    /**
     * Serialize a config array back to the legacy INI format (legacy
     * ini_parser::get_ini_string — canonical implementation in
     * App\Support\IniConfig::serialize()).
     *
     * @param  array<string, array<string, string>>  $config
     */
    public function serialize(array $config): string
    {
        return IniConfig::serialize($config);
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
            $config = IniConfig::mergeSection($config, $section, $values);
        }

        // Deliberately NO datalog here — see the class docblock (dual write
        // discipline shared with ServerConfigService).
        DB::table('server')
            ->where('server_id', $serverId)
            ->update(['config' => $this->serialize($config)]);
    }
}
