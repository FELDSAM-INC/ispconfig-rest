<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\WebBackupApiTestCase;

/**
 * GET /me/backups (spec 041, api/modules/me/backups.yaml): the account-wide
 * backup overview — one entry per vhost website the key may read, carrying the
 * newest backup of each type as the very same WebBackup representation the
 * per-website list returns, so a consumer renders its overview in one request.
 */
class MeBackupsApiTest extends WebBackupApiTestCase
{
    private const URL = '/api/v1/me/backups';

    public function test_endpoint_requires_api_key(): void
    {
        $this->website('clientA');

        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_lists_one_entry_per_vhost_website_ordered_by_domain(): void
    {
        $this->website('clientA', ['domain' => 'zeta.example.test']);
        $this->website('clientA', ['domain' => 'alpha.example.test']);

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('data.0.domain', 'alpha.example.test')
            ->assertJsonPath('data.1.domain', 'zeta.example.test');

        $entry = $response->json('data.0');
        $this->assertSame(
            ['web_domain_id', 'domain', 'server_id', 'backups_available', 'total', 'latest'],
            array_keys($entry)
        );
    }

    public function test_latest_holds_the_newest_backup_of_each_type_newest_first(): void
    {
        $website = $this->website('clientA');
        $this->database('clientA', $website, self::BACKUP_SERVER, 'c1_shop');

        $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP, 'filename' => 'web_old.tar.gz']);
        $newestWeb = $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP + 200, 'filename' => 'web_new.tar.gz']);
        $this->backup($website, [
            'backup_type' => 'mysql',
            'tstamp' => self::BACKUP_TSTAMP + 50,
            'filename' => 'db_c1_shop_2026-09-14_00-00.sql.gz',
        ]);
        $newestDb = $this->backup($website, [
            'backup_type' => 'mysql',
            'tstamp' => self::BACKUP_TSTAMP + 100,
            'filename' => 'db_c1_shop_2026-09-15_00-00.sql.gz',
        ]);

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();

        $this->assertSame(4, $response->json('data.0.total'));
        $this->assertCount(2, $response->json('data.0.latest'));
        // Newest first across types: the web backup (+200) before the database one (+100).
        $this->assertSame($newestWeb, $response->json('data.0.latest.0.id'));
        $this->assertSame('web', $response->json('data.0.latest.0.backup_type'));
        $this->assertSame($newestDb, $response->json('data.0.latest.1.id'));
        $this->assertSame('mysql', $response->json('data.0.latest.1.backup_type'));
        $this->assertSame('c1_shop', $response->json('data.0.latest.1.database_name'));
    }

    public function test_website_without_backups_is_present_and_empty(): void
    {
        $this->website('clientA', ['domain' => 'empty.example.test']);

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'empty.example.test')
            ->assertJsonPath('data.0.total', 0)
            ->assertJsonPath('data.0.latest', []);
    }

    public function test_backups_available_follows_the_servers_backup_dir(): void
    {
        $this->website('clientA', ['domain' => 'with.example.test', 'server_id' => self::BACKUP_SERVER]);
        $this->website('clientA', ['domain' => 'without.example.test', 'server_id' => self::PLAIN_SERVER]);

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'with.example.test')
            ->assertJsonPath('data.0.backups_available', true)
            ->assertJsonPath('data.1.domain', 'without.example.test')
            ->assertJsonPath('data.1.backups_available', false);
    }

    public function test_only_vhost_websites_appear(): void
    {
        $parent = $this->website('clientA', ['domain' => 'parent.example.test']);
        $this->website('clientA', ['domain' => 'sub.parent.example.test', 'type' => 'vhostsubdomain', 'parent_domain_id' => $parent]);
        $this->website('clientA', ['domain' => 'alias.example.test', 'type' => 'alias', 'parent_domain_id' => $parent]);

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'parent.example.test');
    }

    public function test_another_tenants_websites_never_appear(): void
    {
        $mine = $this->website('clientA', ['domain' => 'mine.example.test']);
        $this->backup($mine);
        $theirs = $this->website('clientB', ['domain' => 'theirs.example.test']);
        $this->backup($theirs);

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();

        $this->assertSame(['mine.example.test'], array_column($response->json('data'), 'domain'));
    }

    public function test_backup_on_a_foreign_server_is_not_downloadable(): void
    {
        $website = $this->website('clientA', ['server_id' => self::BACKUP_SERVER]);
        // Visible because the website has a database on that server, but stored elsewhere.
        $this->database('clientA', $website, self::PLAIN_SERVER, 'c1_remote');
        $this->backup($website, ['server_id' => self::PLAIN_SERVER, 'backup_type' => 'mysql', 'filename' => 'db_c1_remote_2026-09-15_00-00.sql.gz']);

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.latest.0.download_available', false);
    }

    public function test_latest_entry_equals_the_per_website_list_field_for_field(): void
    {
        $website = $this->website('clientA');
        $this->database('clientA', $website, self::BACKUP_SERVER, 'c1_shop');
        $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP + 10, 'filename' => 'manual-web_2026-09-15_00-00.tar.gz']);
        $this->backup($website, [
            'backup_type' => 'mysql',
            'tstamp' => self::BACKUP_TSTAMP + 5,
            'filename' => 'db_c1_shop_2026-09-15_00-00.sql.gz',
        ]);

        $overview = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk()->json('data.0.latest');
        $perWebsite = $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))->assertOk()->json('data');

        $byId = [];
        foreach ($perWebsite as $backup) {
            $byId[$backup['id']] = $backup;
        }

        $this->assertNotEmpty($overview);

        foreach ($overview as $entry) {
            $this->assertArrayHasKey($entry['id'], $byId, 'overview backup must exist in the per-website list');
            $this->assertSame($byId[$entry['id']], $entry, 'overview entry must equal the per-website representation');
        }
    }

    public function test_query_count_does_not_grow_with_the_number_of_websites(): void
    {
        $website = $this->website('clientA');
        $this->backup($website);

        // The first authenticated request of a run also writes the key's
        // last_used_at; that is auth bookkeeping, not the endpoint's work.
        $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();

        $one = $this->endpointQueries('clientA');

        for ($i = 0; $i < 4; $i++) {
            $extra = $this->website('clientA');
            $this->backup($extra);
            $this->backup($extra, ['backup_type' => 'mysql', 'filename' => 'db_c1_x_2026-09-15_00-00.sql.gz']);
        }

        $five = $this->endpointQueries('clientA');

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 5);

        $this->assertSame($one, $five, 'the overview must not query per website');
        $this->assertLessThanOrEqual(7, $five, 'the overview must stay within its fixed query budget');
    }

    /**
     * Queries the endpoint itself runs, excluding API-key authentication
     * bookkeeping (`api_keys`, `sys_user`), which is not per-website work.
     */
    private function endpointQueries(string $tenant): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::URL, $this->tenantHeaders($tenant))->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return count(array_filter(
            $queries,
            static fn (string $sql): bool => ! str_contains($sql, '"api_keys"') && ! str_contains($sql, '"sys_user"')
        ));
    }

    public function test_client_without_the_backup_limit_is_refused(): void
    {
        $this->website('clientA');
        $this->setLimitBackup('clientA', 'n');

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed')
            ->assertJsonPath('feature', 'limit_backup');
    }

    public function test_admin_key_is_not_limited_by_the_backup_flag(): void
    {
        $this->website('clientA', ['domain' => 'admin-view.example.test']);
        $this->setLimitBackup('clientA', 'n');

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientA')['client_id'], $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'admin-view.example.test');
    }

    public function test_admin_key_needs_a_client_id(): void
    {
        $this->website('clientA');

        $this->getJson(self::URL, $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_unknown_client_is_not_found_and_invalid_client_id_is_refused(): void
    {
        $this->website('clientA');

        $this->getJson(self::URL.'?client_id=999999', $this->tenantHeaders('admin'))->assertStatus(404);
        $this->getJson(self::URL.'?client_id=0', $this->tenantHeaders('admin'))->assertStatus(422);
        $this->getJson(self::URL.'?client_id=abc', $this->tenantHeaders('admin'))->assertStatus(422);
    }

    public function test_reseller_sees_its_own_and_its_clients_overview(): void
    {
        $this->website('clientA', ['domain' => 'client-of-reseller.example.test']);

        // The reseller's group list includes clientA's group (legacy add_group_to_user semantics).
        $this->getJson(self::URL, $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'client-of-reseller.example.test');

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientA')['client_id'], $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'client-of-reseller.example.test');
    }

    public function test_unknown_query_parameter_is_refused(): void
    {
        $this->website('clientA');

        $this->getJson(self::URL.'?foo=1', $this->tenantHeaders('clientA'))->assertStatus(400);
    }

    public function test_paging_keeps_the_total(): void
    {
        $this->website('clientA', ['domain' => 'a.example.test']);
        $this->website('clientA', ['domain' => 'b.example.test']);

        $this->getJson(self::URL.'?limit=1', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('data.0.domain', 'a.example.test');

        $this->getJson(self::URL.'?limit=1&offset=1', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.offset', 1)
            ->assertJsonPath('data.0.domain', 'b.example.test');
    }

    public function test_locked_account_can_still_read_the_overview(): void
    {
        $this->website('clientA', ['domain' => 'locked.example.test']);
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'locked.example.test');
    }
}
