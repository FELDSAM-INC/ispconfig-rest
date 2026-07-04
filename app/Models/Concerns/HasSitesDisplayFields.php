<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Read-only convenience fields the sites schemas declare on responses
 * (`server_name`, `parent_domain`, `web_folder_path`). Simple primary-key
 * lookups — deliberately uncached so responses always reflect the current
 * rows (list volumes in this API are bounded by the shared `limit`
 * parameter, max 100).
 */
trait HasSitesDisplayFields
{
    protected function lookupServerName(?int $serverId): ?string
    {
        if ($serverId === null || $serverId <= 0) {
            return null;
        }

        return DB::table('server')->where('server_id', $serverId)->value('server_name');
    }

    protected function lookupDomainName(?int $domainId): ?string
    {
        if ($domainId === null || $domainId <= 0) {
            return null;
        }

        return DB::table('web_domain')->where('domain_id', $domainId)->value('domain');
    }

    protected function lookupWebFolder(?int $webFolderId): ?object
    {
        if ($webFolderId === null || $webFolderId <= 0) {
            return null;
        }

        return DB::table('web_folder')->where('web_folder_id', $webFolderId)->first();
    }
}
