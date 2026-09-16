<?php

namespace Tests\Feature;

use App\Services\WebBackupService;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebBackupApiTestCase;

/**
 * GET/HEAD /sites/web-domains/{id}/backups/{backup}/download (spec 042):
 * streams the copy ISPConfig's download action delivers into the website's
 * `backup` folder, but only when this API process can read it — and says
 * precisely why when it cannot.
 *
 * The tests use a real temporary document root, because the path guards
 * (realpath, prefix, symlink, regular file) are exactly what must be proven
 * against a real file system.
 */
class WebBackupDownloadApiTest extends WebBackupApiTestCase
{
    /** @var array<int, string> */
    private array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->removeTree($root);
        }

        $this->tempRoots = [];

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0644);
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        @chmod($path, 0755);

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    /**
     * A website whose document_root is a real temporary directory.
     *
     * @return array{0: int, 1: string} website id and its document root
     */
    private function websiteWithRoot(string $owner, array $attrs = []): array
    {
        $root = sys_get_temp_dir().'/ispc-042-'.bin2hex(random_bytes(6));
        mkdir($root.'/backup', 0755, true);
        $this->tempRoots[] = $root;

        $website = $this->website($owner, array_merge(['document_root' => $root], $attrs));

        return [$website, $root];
    }

    /**
     * Write a prepared copy into the website's backup folder.
     */
    private function prepareCopy(string $root, string $filename, string $contents = 'archive-bytes', ?int $mtime = null): string
    {
        $path = $root.'/backup/'.$filename;
        file_put_contents($path, $contents);

        if ($mtime !== null) {
            touch($path, $mtime);
        }

        return $path;
    }

    private function downloadUrl(int $website, int $backup): string
    {
        return $this->url($website, "/backups/{$backup}/download");
    }

    public function test_endpoint_requires_api_key(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz');

        $this->getJson($this->downloadUrl($website, $backup))->assertStatus(401);
    }

    public function test_readable_copy_is_streamed_with_file_headers(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $bytes = random_bytes(2048);
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz', $bytes);

        $response = $this->get($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Length', (string) strlen($bytes))
            ->assertHeader('Content-Disposition', 'attachment; filename="web_2025-09-14_00-00.tar.gz"');

        // Directives, not a byte-exact header: Symfony decides their order.
        $cacheControl = $response->headers->get('Cache-Control') ?? '';
        $this->assertStringContainsString('private', $cacheControl, 'a customer archive must not be cached by a shared cache');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);

        $this->assertSame($bytes, $response->streamedContent(), 'the downloaded bytes must equal the file');
    }

    public function test_head_returns_the_same_headers_without_a_body(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz', str_repeat('x', 4096));

        $response = $this->call('HEAD', $this->downloadUrl($website, $backup), [], [], [], $this->transformHeadersToServerVars($this->tenantHeaders('clientA')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('4096', $response->headers->get('Content-Length'));
        $this->assertSame('attachment; filename="web_2025-09-14_00-00.tar.gz"', $response->headers->get('Content-Disposition'));
        $this->assertSame('', $response->getContent() === false ? '' : $response->getContent());
    }

    public function test_download_writes_no_journal_and_no_remote_action(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz');

        $this->get($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count(), 'a download must not journal');
        $this->assertSame(0, DB::table('sys_remoteaction')->count(), 'a download must not queue an action');
    }

    public function test_no_path_is_disclosed_in_a_refusal(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);

        $response = $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(409);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString($root, $body, 'the document root must never appear in a response');
        $this->assertStringNotContainsString('/backup/', $body);
    }

    public function test_missing_copy_is_not_prepared(): void
    {
        [$website] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);

        $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#download-not-prepared');
    }

    public function test_expired_copy_is_not_prepared(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz', 'old', time() - WebBackupService::DOWNLOAD_RETENTION - 60);

        $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#download-not-prepared');
    }

    public function test_unreadable_copy_is_not_readable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read any file, so the readability guard cannot be exercised');
        }

        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $path = $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz');
        chmod($path, 0000);

        $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#download-not-readable');
    }

    public function test_backup_on_another_server_is_not_readable(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA', ['server_id' => self::BACKUP_SERVER]);
        $this->database('clientA', $website, self::PLAIN_SERVER, 'c1_remote');
        $backup = $this->backup($website, [
            'server_id' => self::PLAIN_SERVER,
            'backup_type' => 'mysql',
            'filename' => 'db_c1_remote_2025-09-14_00-00.sql.gz',
        ]);
        $this->prepareCopy($root, 'db_c1_remote_2025-09-14_00-00.sql.gz');

        $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#download-not-readable');
    }

    public function test_symlink_escaping_the_folder_is_never_opened(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);

        $secret = sys_get_temp_dir().'/ispc-042-secret-'.bin2hex(random_bytes(4));
        file_put_contents($secret, 'not yours');
        $this->tempRoots[] = $secret;
        symlink($secret, $root.'/backup/web_2025-09-14_00-00.tar.gz');

        $response = $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(409);

        $this->assertStringNotContainsString('not yours', $response->getContent() ?: '');
    }

    public function test_directory_with_the_archive_name_is_not_served(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        mkdir($root.'/backup/web_2025-09-14_00-00.tar.gz');

        $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#download-not-prepared');
    }

    public function test_another_tenants_backup_is_not_found(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz');

        $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientB'))->assertStatus(404);
    }

    public function test_plan_without_backups_is_refused(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz');
        $this->setLimitBackup('clientA', 'n');

        $this->getJson($this->downloadUrl($website, $backup), $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed')
            ->assertJsonPath('feature', 'limit_backup');
    }

    public function test_representation_reports_ready_state_and_retention(): void
    {
        [$website, $root] = $this->websiteWithRoot('clientA');
        $this->backup($website);
        $mtime = time() - 3600;
        $this->prepareCopy($root, 'web_2025-09-14_00-00.tar.gz', 'bytes', $mtime);

        $entry = $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->json('data.0.download');

        $this->assertSame('ready', $entry['state']);
        $this->assertTrue($entry['http']);
        $this->assertSame('web_2025-09-14_00-00.tar.gz', $entry['filename']);
        $this->assertNotNull($entry['available_until']);
        $this->assertSame(
            $mtime + WebBackupService::DOWNLOAD_RETENTION,
            strtotime((string) $entry['available_until']),
            'available_until must be the copy mtime plus the retention'
        );
    }

    public function test_representation_reports_not_prepared_and_preparing(): void
    {
        [$website] = $this->websiteWithRoot('clientA');
        $backup = $this->backup($website);

        $entry = $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->json('data.0.download');
        $this->assertSame('not_prepared', $entry['state']);
        $this->assertNull($entry['filename']);
        $this->assertNull($entry['available_until']);

        $this->remoteAction([
            'action_type' => 'backup_download',
            'action_param' => (string) $backup,
        ]);

        $entry = $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->json('data.0.download');
        $this->assertSame('preparing', $entry['state']);
    }

    public function test_representation_reports_unavailable_for_a_foreign_server_backup(): void
    {
        [$website] = $this->websiteWithRoot('clientA', ['server_id' => self::BACKUP_SERVER]);
        $this->database('clientA', $website, self::PLAIN_SERVER, 'c1_remote');
        $this->backup($website, [
            'server_id' => self::PLAIN_SERVER,
            'backup_type' => 'mysql',
            'filename' => 'db_c1_remote_2025-09-14_00-00.sql.gz',
        ]);

        $entry = $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->json('data.0.download');

        $this->assertSame('unavailable', $entry['state']);
        $this->assertFalse($entry['http']);
    }
}
