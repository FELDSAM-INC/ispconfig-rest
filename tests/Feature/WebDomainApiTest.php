<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class WebDomainApiTest extends SitesApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'server_id' => 1,
            'domain' => 'example.com',
            'sys_groupid' => 5,
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/web-domains')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);

        $this->postJson('/api/v1/sites/web-domains', $this->validPayload())
            ->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_with_search(): void
    {
        $this->seedVhost(['domain' => 'alpha.com']);
        $this->seedVhost(['domain' => 'beta.com']);
        // Child domains never leak into the web-domains list.
        $this->seedVhost(['domain' => 'sub.alpha.com', 'type' => 'subdomain']);

        $this->getJson('/api/v1/sites/web-domains', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.domain', 'alpha.com')
            ->assertJsonPath('data.0.server_name', 'web1')
            ->assertJsonMissingPath('data.0.domain_id')
            ->assertJsonMissingPath('data.0.stats_password')
            ->assertJsonMissingPath('data.0.ssl_key');

        $this->getJson('/api/v1/sites/web-domains?search=beta', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'beta.com');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil_column', 'order=upwards', 'limit=0', 'offset=-1'] as $param) {
            $this->getJson('/api/v1/sites/web-domains?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_contract_shape(): void
    {
        $id = $this->seedVhost(['domain' => 'shown.com']);

        $this->getJson('/api/v1/sites/web-domains/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('domain', 'shown.com')
            ->assertJsonPath('active', true)
            ->assertJsonPath('system_user', "web{$id}")
            ->assertJsonMissingPath('domain_id')
            ->assertJsonMissingPath('ssl_cert')
            ->assertJsonMissingPath('ssl_action');
    }

    public function test_show_missing_and_child_domain_return_404(): void
    {
        $this->getJson('/api/v1/sites/web-domains/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');

        $childId = $this->seedVhost(['domain' => 'sub.x.com', 'type' => 'subdomain']);

        $this->getJson('/api/v1/sites/web-domains/'.$childId, $this->authHeaders())
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Create — derived fields + datalog
    // ------------------------------------------------------------------

    public function test_create_generates_provisioning_fields_and_datalogs_them(): void
    {
        $response = $this->postJson('/api/v1/sites/web-domains', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'example.com')
            ->assertJsonPath('type', 'vhost')
            ->assertJsonPath('active', true)
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonMissingPath('stats_password');

        $id = (int) $response->json('id');

        // Derived per legacy: docroot template, web{id}, client{client_id},
        // php_open_basedir template, allow_override + log_retention from
        // the server config, added_date/added_by.
        $response->assertJsonPath('document_root', "/var/www/clients/client3/web{$id}")
            ->assertJsonPath('system_user', "web{$id}")
            ->assertJsonPath('system_group', 'client3')
            ->assertJsonPath('php_open_basedir', "/var/www/clients/client3/web{$id}/web:/var/www/clients/client3/web{$id}/tmp:/usr/share/php")
            ->assertJsonPath('allow_override', 'All')
            ->assertJsonPath('log_retention', 30)
            ->assertJsonPath('added_by', 'apiadmin')
            ->assertJsonPath('added_date', date('Y-m-d'));

        // Exactly one datalog i row carrying the COMPLETE derived record.
        $rows = $this->datalogRows('web_domain');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('domain_id:'.$id, $rows[0]->dbidx);
        $this->assertSame(1, (int) $rows[0]->server_id);
        $this->assertSame('apiadmin', $rows[0]->user);

        $data = unserialize($rows[0]->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame("/var/www/clients/client3/web{$id}", $data['new']['document_root']);
        $this->assertSame("web{$id}", $data['new']['system_user']);
        $this->assertSame('client3', $data['new']['system_group']);
        $this->assertSame('example.com', $data['new']['domain']);
        $this->assertSame('vhost', $data['new']['type']);
        $this->assertSame('riud', $data['new']['sys_perm_user']);
        $this->assertSame('30', $data['new']['log_retention']);
    }

    public function test_create_normalizes_idn_and_uppercase_domains(): void
    {
        $this->postJson('/api/v1/sites/web-domains', $this->validPayload(['domain' => 'MüLLER.De']), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'xn--mller-kva.de');
    }

    public function test_create_validation_failures_return_422(): void
    {
        $cases = [
            'missing domain' => [$this->validPayload(['domain' => null]), 'domain'],
            'malformed domain' => [$this->validPayload(['domain' => 'not_a_domain']), 'domain'],
            'unknown server' => [$this->validPayload(['server_id' => 99]), 'server_id'],
            'zero hd_quota on vhost' => [$this->validPayload(['hd_quota' => 0]), 'hd_quota'],
            'bad php mode' => [$this->validPayload(['php' => 'fpm']), 'php'],
            'bad subdomain' => [$this->validPayload(['subdomain' => 'mail']), 'subdomain'],
            'proxy redirect with path' => [$this->validPayload(['redirect_type' => 'proxy', 'redirect_path' => '/foo/']), 'redirect_path'],
            'bad custom_php_ini' => [$this->validPayload(['custom_php_ini' => "system('rm -rf /')"]), 'custom_php_ini'],
            'child vhost without parent' => [$this->validPayload(['type' => 'vhostsubdomain', 'domain' => 'blog.example.com']), 'parent_domain_id'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/sites/web-domains', $payload, $this->authHeaders());
            $response->assertStatus(422)->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('web_domain')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_rejects_invalid_dynamic_fpm_pool(): void
    {
        $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'pm' => 'dynamic',
            'pm_max_children' => 2,
            'pm_max_spare_servers' => 5,
        ]), $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['pm_max_children']]);
    }

    public function test_create_duplicate_domain_returns_409(): void
    {
        $this->seedVhost(['domain' => 'example.com', 'ip_address' => null]);

        $this->postJson('/api/v1/sites/web-domains', $this->validPayload(), $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);
    }

    public function test_create_enforces_sni_limit_when_disabled(): void
    {
        // server 2 has enable_sni=n; an ssl vhost already exists on the IP.
        $this->seedVhost(['server_id' => 2, 'domain' => 'first-ssl.com', 'ssl' => 'y', 'ip_address' => '10.0.0.2']);

        $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'server_id' => 2,
            'domain' => 'second-ssl.com',
            'ssl' => true,
            'ip_address' => '10.0.0.2',
        ]), $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ssl']]);
    }

    public function test_create_resets_server_php_id_for_incompatible_php_mode(): void
    {
        $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'php' => 'mod',
            'server_php_id' => 7,
        ]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('server_php_id', 0);
    }

    public function test_create_rejects_invalid_nginx_rewrite_rules(): void
    {
        $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'server_id' => 2,
            'rewrite_rules' => 'evil_directive on;',
        ]), $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['rewrite_rules']]);

        // Valid legacy-whitelisted rules pass.
        $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'server_id' => 2,
            'rewrite_rules' => "if (!-e \$request_filename) {\nrewrite ^/(.*)$ /index.php?q=\$1 last;\n}",
        ]), $this->authHeaders())->assertStatus(201);
    }

    public function test_create_vhostsubdomain_inherits_parent_provisioning(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);

        $response = $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'type' => 'vhostsubdomain',
            'domain' => 'blog.parent.com',
            'parent_domain_id' => $parentId,
            'web_folder' => 'blog',
            'hd_quota' => 500, // forced to 0 for child vhosts
        ]), $this->authHeaders())->assertStatus(201);

        $response->assertJsonPath('system_user', "web{$parentId}")
            ->assertJsonPath('system_group', 'client3')
            ->assertJsonPath('document_root', "/var/www/clients/client3/web{$parentId}")
            ->assertJsonPath('hd_quota', 0)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 5);
    }

    public function test_create_hashes_stats_password_as_crypt(): void
    {
        $response = $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'stats_password' => 'super-secret',
        ]), $this->authHeaders())->assertStatus(201);

        $stored = DB::table('web_domain')
            ->where('domain_id', (int) $response->json('id'))
            ->value('stats_password');

        $this->assertStringStartsWith('$6$rounds=5000$', $stored);
        $this->assertStringNotContainsString('super-secret', $stored);
        $this->assertStringNotContainsString('super-secret', json_encode($response->json()));
        $this->assertStringNotContainsString('super-secret', DB::table('sys_datalog')->value('data'));
    }

    // ------------------------------------------------------------------
    // Let's Encrypt two-step create
    // ------------------------------------------------------------------

    public function test_letsencrypt_create_writes_insert_then_enable_update(): void
    {
        $response = $this->postJson('/api/v1/sites/web-domains', $this->validPayload([
            'ssl' => true,
            'ssl_letsencrypt' => true,
        ]), $this->authHeaders())->assertStatus(201);

        $id = (int) $response->json('id');

        // Final state: both enabled.
        $response->assertJsonPath('ssl', true)->assertJsonPath('ssl_letsencrypt', true);

        $rows = $this->datalogRows('web_domain');
        $this->assertCount(2, $rows);

        // Step 1: datalog i with both flags 'n' (legacy
        // _letsencrypt_on_insert — LE cannot activate before the site exists).
        $this->assertSame('i', $rows[0]->action);
        $insert = unserialize($rows[0]->data);
        $this->assertSame('n', $insert['new']['ssl']);
        $this->assertSame('n', $insert['new']['ssl_letsencrypt']);
        $this->assertSame("web{$id}", $insert['new']['system_user']);

        // Step 2: datalog u enabling ssl + ssl_letsencrypt.
        $this->assertSame('u', $rows[1]->action);
        $update = unserialize($rows[1]->data);
        $this->assertSame(['old', 'new'], array_keys($update));
        $this->assertSame('n', $update['old']['ssl']);
        $this->assertSame('y', $update['new']['ssl']);
        $this->assertSame('n', $update['old']['ssl_letsencrypt']);
        $this->assertSame('y', $update['new']['ssl_letsencrypt']);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_diff(): void
    {
        $id = $this->seedVhost(['domain' => 'example.com']);

        $this->putJson('/api/v1/sites/web-domains/'.$id, ['active' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false);

        $rows = $this->datalogRows('web_domain');
        $this->assertCount(1, $rows);
        $this->assertSame('u', $rows[0]->action);

        $data = unserialize($rows[0]->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('y', $data['old']['active']);
        $this->assertSame('n', $data['new']['active']);
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedVhost(['domain' => 'example.com']);

        $this->putJson('/api/v1/sites/web-domains/'.$id, ['active' => true, 'domain' => 'example.com'], $this->authHeaders())
            ->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_rejects_server_change_and_preserves_web_folder(): void
    {
        $id = $this->seedVhost(['domain' => 'example.com', 'web_folder' => 'keepme']);

        $this->putJson('/api/v1/sites/web-domains/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        // Same value passes (idempotent PUT).
        $this->putJson('/api/v1/sites/web-domains/'.$id, ['server_id' => 1], $this->authHeaders())
            ->assertOk();

        // web_folder is silently preserved server-side.
        $this->putJson('/api/v1/sites/web-domains/'.$id, ['web_folder' => 'other'], $this->authHeaders())
            ->assertOk();
        $this->assertSame('keepme', DB::table('web_domain')->where('domain_id', $id)->value('web_folder'));
    }

    public function test_update_missing_returns_404(): void
    {
        $this->putJson('/api/v1/sites/web-domains/999', ['active' => false], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete — THE cascade
    // ------------------------------------------------------------------

    public function test_delete_cascades_children_and_detaches_databases(): void
    {
        $id = $this->seedVhost(['domain' => 'example.com']);

        $childId = $this->seedVhost(['domain' => 'sub.example.com', 'type' => 'subdomain', 'parent_domain_id' => $id]);
        $ftpId = (int) DB::table('ftp_user')->insertGetId(['parent_domain_id' => $id, 'server_id' => 1, 'username' => 'u1'], 'ftp_user_id');
        $shellId = (int) DB::table('shell_user')->insertGetId(['parent_domain_id' => $id, 'server_id' => 1, 'username' => 's1'], 'shell_user_id');
        $cronId = (int) DB::table('cron')->insertGetId(['parent_domain_id' => $id, 'server_id' => 1, 'command' => 'https://example.com/'], 'id');
        $davId = (int) DB::table('webdav_user')->insertGetId(['parent_domain_id' => $id, 'server_id' => 1, 'username' => 'd1'], 'webdav_user_id');
        $backupId = (int) DB::table('web_backup')->insertGetId(['parent_domain_id' => $id, 'server_id' => 1], 'backup_id');
        $folderId = (int) DB::table('web_folder')->insertGetId(['parent_domain_id' => $id, 'server_id' => 1, 'path' => '/protected'], 'web_folder_id');
        $folderUserId = (int) DB::table('web_folder_user')->insertGetId(['web_folder_id' => $folderId, 'server_id' => 1, 'username' => 'fu1'], 'web_folder_user_id');
        $dbId = (int) DB::table('web_database')->insertGetId(['parent_domain_id' => $id, 'server_id' => 1, 'database_name' => 'c3keepdb'], 'database_id');

        $this->deleteJson('/api/v1/sites/web-domains/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        // Deleted rows.
        foreach ([
            ['web_domain', 'domain_id', $childId],
            ['web_domain', 'domain_id', $id],
            ['ftp_user', 'ftp_user_id', $ftpId],
            ['shell_user', 'shell_user_id', $shellId],
            ['cron', 'id', $cronId],
            ['webdav_user', 'webdav_user_id', $davId],
            ['web_backup', 'backup_id', $backupId],
            ['web_folder', 'web_folder_id', $folderId],
            ['web_folder_user', 'web_folder_user_id', $folderUserId],
        ] as [$table, $pk, $rowId]) {
            $this->assertDatabaseMissing($table, [$pk => $rowId]);
        }

        // The database survives, DETACHED (parent_domain_id -> 0).
        $this->assertDatabaseHas('web_database', ['database_id' => $dbId, 'parent_domain_id' => 0]);

        // Every cascade member datalogged.
        foreach ([
            ['web_domain', 'domain_id:'.$childId, 'd'],
            ['ftp_user', 'ftp_user_id:'.$ftpId, 'd'],
            ['shell_user', 'shell_user_id:'.$shellId, 'd'],
            ['cron', 'id:'.$cronId, 'd'],
            ['webdav_user', 'webdav_user_id:'.$davId, 'd'],
            ['web_backup', 'backup_id:'.$backupId, 'd'],
            ['web_folder', 'web_folder_id:'.$folderId, 'd'],
            ['web_folder_user', 'web_folder_user_id:'.$folderUserId, 'd'],
            ['web_database', 'database_id:'.$dbId, 'u'],
            ['web_domain', 'domain_id:'.$id, 'd'],
        ] as [$table, $dbidx, $action]) {
            $this->assertSame(1, DB::table('sys_datalog')
                ->where('dbtable', $table)->where('dbidx', $dbidx)->where('action', $action)->count(),
                "missing datalog {$action} row for {$table} {$dbidx}");
        }

        // One request = one session id for the whole cascade.
        $this->assertSame(1, DB::table('sys_datalog')->distinct()->count('session_id'));

        // The detach payload shows parent_domain_id going to 0.
        $detach = DB::table('sys_datalog')->where('dbtable', 'web_database')->first();
        $data = unserialize($detach->data);
        $this->assertSame((string) $id, $data['old']['parent_domain_id']);
        $this->assertSame('0', $data['new']['parent_domain_id']);
    }

    public function test_delete_missing_returns_404(): void
    {
        $this->deleteJson('/api/v1/sites/web-domains/999', [], $this->authHeaders())
            ->assertStatus(404);
    }
}
