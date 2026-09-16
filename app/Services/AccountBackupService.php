<?php

namespace App\Services;

use App\Models\WebBackup;
use App\Models\WebDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Account-wide backup overview (spec 041): folds one page of the account's
 * vhost websites into "newest backup per type" entries.
 *
 * Every visibility and representation rule is delegated to WebBackupService,
 * the spec 018 authority, so the overview can never show a website or a backup
 * that GET /sites/web-domains/{id}/backups would refuse the same key. What
 * lives here is only the fold: the page's websites, their database servers,
 * their backups and their servers' availability are read with a fixed number
 * of queries, never one per website (research R3, FR-008).
 */
class AccountBackupService
{
    public function __construct(protected WebBackupService $backups) {}

    /**
     * Overview entries for one page of websites.
     *
     * @param  Collection<int, WebDomain>|iterable<int, WebDomain>  $websites
     * @return array<int, array<string, mixed>>
     */
    public function overview(iterable $websites): array
    {
        /** @var Collection<int, WebDomain> $page */
        $page = $websites instanceof Collection ? $websites : collect($websites);

        if ($page->isEmpty()) {
            return [];
        }

        $websiteIds = $page->map(fn (WebDomain $site): int => (int) $site->getKey())->all();

        // One grouped query for the database servers of every website of the page (R4).
        $databaseServers = $this->backups->databaseServerIdsOfMany($websiteIds);

        // One query for the backups of the whole page, newest first.
        $rows = WebBackup::query()
            ->whereIn('parent_domain_id', $websiteIds)
            ->orderByDesc('tstamp')
            ->orderByDesc('backup_id')
            ->get();

        $byWebsite = [];
        foreach ($rows as $row) {
            $byWebsite[(int) $row->getAttributes()['parent_domain_id']][] = $row;
        }

        // Availability once per distinct server of the page (R6).
        $available = [];
        foreach ($page as $site) {
            $serverId = (int) $site->getAttributes()['server_id'];
            $available[$serverId] ??= $this->backups->backupsAvailable($serverId);
        }

        $entries = [];

        foreach ($page as $site) {
            $websiteId = (int) $site->getKey();
            $serverId = (int) $site->getAttributes()['server_id'];

            // The same rule the per-website list uses: the website's server
            // plus the servers of its databases (R2).
            $visibleServers = array_values(array_filter(
                array_unique(array_merge([$serverId], $databaseServers[$websiteId] ?? [])),
                fn (int $id): bool => $id > 0
            ));

            $visible = array_values(array_filter(
                $byWebsite[$websiteId] ?? [],
                fn (WebBackup $backup): bool => in_array((int) $backup->getAttributes()['server_id'], $visibleServers, true)
            ));

            $latest = [];
            foreach ($visible as $backup) {
                $type = (string) $backup->getAttributes()['backup_type'];

                if (isset($latest[$type])) {
                    continue;
                }

                $latest[$type] = $this->backups->backupRepresentation($backup, $site);
            }

            $entries[] = [
                'web_domain_id' => $websiteId,
                'domain' => (string) $site->getAttributes()['domain'],
                'server_id' => $serverId,
                'backups_available' => $available[$serverId],
                'total' => count($visible),
                'latest' => array_values($latest),
            ];
        }

        return $entries;
    }

    /**
     * Restrict a website query to the rows owned by one client — needed for
     * admin and reseller keys, whose read predicate is wider than the client
     * whose overview was requested (parity with HostingAddressService).
     *
     * @param  Builder  $query
     */
    public function restrictToClient($query, int $clientId)
    {
        $groupIds = DB::table('sys_group')->where('client_id', $clientId)->pluck('groupid')->all();

        return $groupIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('sys_groupid', $groupIds);
    }
}
