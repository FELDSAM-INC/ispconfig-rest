<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 024 US2 (sites): parent references in write bodies follow the read
 * scope for non-admin keys (legacy web_childdomain_edit.php:187-188,
 * web_vhost_domain_edit.php:916-917, ftp_user_edit.php:98-100,
 * shell_user_edit.php:110-111, webdav_user_edit.php:105-106,
 * cron_edit.php:139-140, web_folder_edit.php:58-59, database_edit.php:
 * 179-180, web_folder_user_edit.php:58-59; database user datasources in
 * database.tform.php:158,169). A foreign id fails exactly like a
 * nonexistent one and writes nothing.
 */
class ScopedReferenceSitesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected const MISSING = 999999;

    /** @var array<string, int> */
    protected array $vhost = [];

    /** @var array<string, int> */
    protected array $dbUser = [];

    /** @var array<string, int> */
    protected array $folder = [];

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'web1', 'web_server' => 1, 'db_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1], 'db' => [1]]);
        }

        foreach (['limit_cron', 'limit_shell_user', 'limit_webdav_user'] as $limit) {
            $this->setClientLimit('clientA', $limit, -1);
        }

        foreach (['clientA' => 'a', 'clientB' => 'b'] as $owner => $tag) {
            $this->vhost[$owner] = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, [
                'server_id' => 1, 'domain' => "{$tag}-site.test", 'type' => 'vhost', 'active' => 'y',
                'document_root' => "/var/www/clients/client{$tag}/web{$tag}", 'system_user' => "web{$tag}", 'system_group' => "client{$tag}",
            ]), 'domain_id');

            $this->dbUser[$owner] = (int) DB::table('web_database_user')->insertGetId($this->ownedBy($owner, [
                'server_id' => 1, 'database_user' => "{$tag}_user",
            ]), 'database_user_id');

            $this->folder[$owner] = (int) DB::table('web_folder')->insertGetId($this->ownedBy($owner, [
                'server_id' => 1, 'parent_domain_id' => $this->vhost[$owner], 'path' => "protected/{$tag}", 'active' => 'y',
            ]), 'web_folder_id');
        }
    }

    /**
     * The foreign id must fail on $field exactly like a nonexistent id, and
     * neither attempt may write a datalog row.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function assertForeignLikeMissing(string $method, string $uri, array $payload, string $field, int $foreign): void
    {
        $datalog = DB::table('sys_datalog')->count();
        $headers = $this->tenantHeaders('clientA');

        $foreignResponse = $this->json($method, $uri, array_merge($payload, [$field => $foreign]), $headers);
        $missingResponse = $this->json($method, $uri, array_merge($payload, [$field => self::MISSING]), $headers);

        $foreignResponse->assertStatus(422);
        $this->assertNotEmpty($foreignResponse->json("errors.{$field}"), "{$method} {$uri}: foreign {$field} must be rejected");
        $this->assertSame(
            $missingResponse->json("errors.{$field}"),
            $foreignResponse->json("errors.{$field}"),
            "{$method} {$uri}: foreign {$field} must look like a missing one"
        );
        $this->assertSame($datalog, DB::table('sys_datalog')->count(), "{$method} {$uri}: no datalog on rejection");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertReferencePasses(string $method, string $uri, array $payload, string $field, int $value, string $tenant = 'clientA'): void
    {
        $response = $this->json($method, $uri, array_merge($payload, [$field => $value]), $this->tenantHeaders($tenant));

        $this->assertNull($response->json("errors.{$field}"), "{$method} {$uri} as {$tenant}: {$field} {$value} must pass");
    }

    public function test_creates_reject_foreign_parent_websites(): void
    {
        $cases = [
            ['/api/v1/sites/web-child-domains', ['domain' => 'shop', 'type' => 'subdomain']],
            ['/api/v1/sites/web-domains', ['type' => 'vhostsubdomain', 'domain' => 'shop.a-site.test']],
            ['/api/v1/sites/ftp-users', ['username' => 'intruder', 'password' => 'S3cretPass!']],
            ['/api/v1/sites/shell-users', ['username' => 'intruder', 'password' => 'S3cretPass!']],
            ['/api/v1/sites/webdav-users', ['username' => 'intruder', 'password' => 'S3cretPass!', 'dir' => 'dav']],
            ['/api/v1/sites/cron-jobs', ['run_min' => '*', 'run_hour' => '*', 'run_mday' => '*', 'run_month' => '*', 'run_wday' => '*', 'command' => 'https://a-site.test/cron']],
            ['/api/v1/sites/web-folders', ['path' => 'members']],
            ['/api/v1/sites/databases', ['database_name' => 'intruder', 'database_user_id' => $this->dbUser['clientA']]],
        ];

        foreach ($cases as [$uri, $payload]) {
            $this->assertForeignLikeMissing('POST', $uri, $payload, 'parent_domain_id', $this->vhost['clientB']);
            $this->assertReferencePasses('POST', $uri, $payload, 'parent_domain_id', $this->vhost['clientA']);
        }
    }

    public function test_database_creates_reject_foreign_database_users(): void
    {
        $payload = ['database_name' => 'intruder', 'parent_domain_id' => $this->vhost['clientA']];

        $this->assertForeignLikeMissing('POST', '/api/v1/sites/databases', $payload + ['database_user_id' => $this->dbUser['clientA']], 'database_ro_user_id', $this->dbUser['clientB']);
        $this->assertForeignLikeMissing('POST', '/api/v1/sites/databases', $payload, 'database_user_id', $this->dbUser['clientB']);
        $this->assertReferencePasses('POST', '/api/v1/sites/databases', $payload, 'database_user_id', $this->dbUser['clientA']);
    }

    public function test_folder_user_creates_reject_foreign_folders(): void
    {
        $payload = ['username' => 'member', 'password' => 'FolderSecret1'];

        $this->assertForeignLikeMissing('POST', '/api/v1/sites/web-folder-users', $payload, 'web_folder_id', $this->folder['clientB']);
        $this->assertReferencePasses('POST', '/api/v1/sites/web-folder-users', $payload, 'web_folder_id', $this->folder['clientA']);
    }

    public function test_updates_cannot_move_records_under_foreign_parents(): void
    {
        $parentA = $this->vhost['clientA'];

        $ftp = (int) DB::table('ftp_user')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'parent_domain_id' => $parentA, 'username' => 'a-ftp', 'active' => 'y',
        ]), 'ftp_user_id');
        $shell = (int) DB::table('shell_user')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'parent_domain_id' => $parentA, 'username' => 'a-shell', 'active' => 'y',
        ]), 'shell_user_id');
        $cron = (int) DB::table('cron')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'parent_domain_id' => $parentA, 'type' => 'url', 'command' => 'https://a-site.test/cron',
            'run_min' => '*', 'run_hour' => '*', 'run_mday' => '*', 'run_month' => '*', 'run_wday' => '*', 'active' => 'y',
        ]));
        $child = (int) DB::table('web_domain')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'domain' => 'sub.a-site.test', 'type' => 'subdomain', 'parent_domain_id' => $parentA, 'active' => 'y',
        ]), 'domain_id');
        $vhostSub = (int) DB::table('web_domain')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'domain' => 'blog.a-site.test', 'type' => 'vhostsubdomain', 'parent_domain_id' => $parentA, 'active' => 'y',
        ]), 'domain_id');
        $database = (int) DB::table('web_database')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'parent_domain_id' => $parentA, 'type' => 'mysql', 'database_name' => 'a_db',
            'database_user_id' => $this->dbUser['clientA'], 'active' => 'y',
        ]), 'database_id');

        $foreign = $this->vhost['clientB'];

        $this->assertForeignLikeMissing('PUT', "/api/v1/sites/ftp-users/{$ftp}", [], 'parent_domain_id', $foreign);
        $this->assertForeignLikeMissing('PUT', "/api/v1/sites/shell-users/{$shell}", [], 'parent_domain_id', $foreign);
        $this->assertForeignLikeMissing('PUT', "/api/v1/sites/cron-jobs/{$cron}", [], 'parent_domain_id', $foreign);
        $this->assertForeignLikeMissing('PUT', "/api/v1/sites/web-child-domains/{$child}", [], 'parent_domain_id', $foreign);
        $this->assertForeignLikeMissing('PUT', "/api/v1/sites/web-domains/{$vhostSub}", ['type' => 'vhostsubdomain'], 'parent_domain_id', $foreign);
        $this->assertForeignLikeMissing('PUT', "/api/v1/sites/databases/{$database}", ['database_user_id' => $this->dbUser['clientA']], 'parent_domain_id', $foreign);
        $this->assertForeignLikeMissing('PUT', "/api/v1/sites/databases/{$database}", [], 'database_user_id', $this->dbUser['clientB']);
    }

    public function test_admin_references_are_unrestricted(): void
    {
        $this->assertReferencePasses('POST', '/api/v1/sites/ftp-users', ['username' => 'admin-ftp', 'password' => 'S3cretPass!'], 'parent_domain_id', $this->vhost['clientB'], 'admin');
        $this->assertReferencePasses('POST', '/api/v1/sites/web-folder-users', ['username' => 'admin-member', 'password' => 'FolderSecret1'], 'web_folder_id', $this->folder['clientB'], 'admin');
    }

    public function test_reseller_may_reference_its_clients_websites(): void
    {
        $this->assertReferencePasses('POST', '/api/v1/sites/web-folders', ['path' => 'reseller'], 'parent_domain_id', $this->vhost['clientA'], 'reseller');
    }
}
