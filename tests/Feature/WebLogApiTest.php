<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

final class WebLogApiTest extends SitesApiTestCase
{
    public function test_local_logs_are_authorized_bounded_and_cursors_bound_to_website_and_type(): void
    {
        $root = sys_get_temp_dir().'/log-api-'.bin2hex(random_bytes(8));
        mkdir($root.'/logs.test', 0700, true);
        file_put_contents($root.'/logs.test/error.log', "one\ntwo\n<script>alert(1)</script>\n");
        config(['web_logs.local_server_id' => 1, 'web_logs.root' => $root]);
        $id = $this->seedVhost(['domain' => 'logs.test']);
        try {
            $path = '/api/v1/sites/web-domains/'.$id.'/logs/error';
            $this->getJson($path)->assertUnauthorized();
            $response = $this->getJson($path.'?lines=1', $this->authHeaders())->assertOk()->assertJsonPath('text', "<script>alert(1)</script>\n")->assertHeader('Cache-Control', 'no-store, private');
            $cursor = $response->json('before');
            $this->getJson($path.'?lines=1&before='.urlencode($cursor), $this->authHeaders())->assertOk()->assertJsonPath('text', "two\n");
            $this->getJson(str_replace('/error', '/access', $path).'?before='.urlencode($cursor), $this->authHeaders())->assertUnprocessable();
            $other = $this->seedVhost();
            $this->getJson('/api/v1/sites/web-domains/'.$other.'/logs/error?before='.urlencode($cursor), $this->authHeaders())->assertUnprocessable();
            $this->getJson($path.'?lines=1001', $this->authHeaders())->assertUnprocessable();
            $this->getJson($path.'?path=/etc/passwd', $this->authHeaders())->assertBadRequest();
            $this->getJson('/api/v1/sites/web-domains/'.$id, $this->authHeaders())->assertOk()->assertJsonPath('logs_available', true);
        } finally {
            unlink($root.'/logs.test/error.log');
            rmdir($root.'/logs.test');
            rmdir($root);
        }
    }

    public function test_remote_reads_require_fresh_worker_and_reuse_pending_read(): void
    {
        $id = $this->seedVhost();
        $path = '/api/v1/sites/web-domains/'.$id.'/logs/access';
        $this->getJson($path, $this->authHeaders())->assertOk()->assertJsonPath('state', 'unavailable');
        DB::table('api_web_log_workers')->insert(['server_id' => 1, 'heartbeat' => time()]);
        $this->getJson($path, $this->authHeaders())->assertStatus(202)->assertJsonPath('state', 'pending');
        $this->getJson($path, $this->authHeaders())->assertStatus(202);
        $this->assertDatabaseCount('api_web_log_reads', 1);
        DB::table('api_web_log_reads')->update(['result' => json_encode(['text' => 'sample', 'before' => null, 'file' => 'access.log', 'rotated' => false, 'truncated' => false, 'lines' => 1])]);
        $this->getJson($path, $this->authHeaders())->assertOk()->assertJsonPath('text', 'sample');
    }

    public function test_pending_worker_read_is_not_requeued_during_cron_gap(): void
    {
        $id = $this->seedVhost();
        DB::table('api_web_log_workers')->insert(['server_id' => 1, 'heartbeat' => time()]);
        $path = '/api/v1/sites/web-domains/'.$id.'/logs/access';
        $this->getJson($path, $this->authHeaders())->assertStatus(202);
        $created = time() - 12;
        DB::table('api_web_log_reads')->update(['created_at' => $created]);
        $this->getJson($path, $this->authHeaders())->assertStatus(202);
        $this->assertDatabaseHas('api_web_log_reads', ['created_at' => $created]);
        DB::table('api_web_log_workers')->update(['heartbeat' => time() - 91]);
        $this->getJson($path, $this->authHeaders())->assertOk()->assertJsonPath('state', 'unavailable');
    }

    public function test_child_redirect_and_missing_site_are_not_log_resources(): void
    {
        $id = $this->seedVhost(['type' => 'alias']);
        $this->getJson('/api/v1/sites/web-domains/'.$id.'/logs/access', $this->authHeaders())->assertNotFound();
        $this->getJson('/api/v1/sites/web-domains/999999/logs/access', $this->authHeaders())->assertNotFound();
    }
}
