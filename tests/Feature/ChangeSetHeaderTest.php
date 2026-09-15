<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * X-Change-Set-Id response header on journaling writes (spec 015 FR-001, US1).
 */
class ChangeSetHeaderTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        $this->assignServers('clientA', ['mail' => [1]]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'server_id' => 1,
            'domain' => 'example.com',
            'active' => true,
            'local_delivery' => true,
            'dkim' => false,
        ], $overrides);
    }

    private function lastDatalogId(): int
    {
        return (int) DB::table('sys_datalog')->max('datalog_id');
    }

    /**
     * @return array<int, string>
     */
    private function sessionIdsAfter(int $datalogId): array
    {
        return DB::table('sys_datalog')
            ->where('datalog_id', '>', $datalogId)
            ->pluck('session_id')
            ->unique()
            ->values()
            ->all();
    }

    public function test_create_returns_the_session_id_of_the_written_rows(): void
    {
        $before = $this->lastDatalogId();

        $response = $this->postJson('/api/v1/mail/domains', $this->payload(), $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->assertHeader('X-Change-Set-Id');

        $this->assertSame([$response->headers->get('X-Change-Set-Id')], $this->sessionIdsAfter($before));
    }

    public function test_client_keys_receive_the_header_too(): void
    {
        $before = $this->lastDatalogId();

        $response = $this->postJson('/api/v1/mail/domains', $this->payload(), $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->assertHeader('X-Change-Set-Id');

        $this->assertSame([$response->headers->get('X-Change-Set-Id')], $this->sessionIdsAfter($before));
    }

    public function test_update_returns_a_new_change_set(): void
    {
        $created = $this->postJson('/api/v1/mail/domains', $this->payload(), $this->tenantHeaders('admin'))->assertStatus(201);
        $id = $created->json('id');
        $before = $this->lastDatalogId();

        $updated = $this->putJson('/api/v1/mail/domains/'.$id, $this->payload(['active' => false]), $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertHeader('X-Change-Set-Id');

        $this->assertSame([$updated->headers->get('X-Change-Set-Id')], $this->sessionIdsAfter($before));
        $this->assertNotSame($created->headers->get('X-Change-Set-Id'), $updated->headers->get('X-Change-Set-Id'));
    }

    public function test_no_change_update_has_no_header(): void
    {
        $id = $this->postJson('/api/v1/mail/domains', $this->payload(), $this->tenantHeaders('admin'))->assertStatus(201)->json('id');
        $before = $this->lastDatalogId();

        $this->putJson('/api/v1/mail/domains/'.$id, $this->payload(), $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertHeaderMissing('X-Change-Set-Id');

        $this->assertSame([], $this->sessionIdsAfter($before));
    }

    public function test_cascading_delete_rows_share_the_header_value(): void
    {
        $id = $this->postJson('/api/v1/mail/domains', $this->payload(), $this->tenantHeaders('admin'))->assertStatus(201)->json('id');

        DB::table('mail_user')->insert(['sys_userid' => 1, 'sys_groupid' => 1, 'server_id' => 1, 'email' => 'info@example.com', 'login' => 'info@example.com']);
        DB::table('mail_forwarding')->insert(['sys_userid' => 1, 'sys_groupid' => 1, 'server_id' => 1, 'source' => 'alias@example.com', 'destination' => 'info@example.com', 'type' => 'alias', 'active' => 'y']);
        $before = $this->lastDatalogId();

        $response = $this->deleteJson('/api/v1/mail/domains/'.$id, [], $this->tenantHeaders('admin'))
            ->assertNoContent()
            ->assertHeader('X-Change-Set-Id');

        $this->assertGreaterThanOrEqual(3, DB::table('sys_datalog')->where('datalog_id', '>', $before)->count());
        $this->assertSame([$response->headers->get('X-Change-Set-Id')], $this->sessionIdsAfter($before));
    }

    public function test_validation_failure_has_no_header(): void
    {
        $this->postJson('/api/v1/mail/domains', $this->payload(['domain' => '']), $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertHeaderMissing('X-Change-Set-Id');
    }

    public function test_read_requests_have_no_header(): void
    {
        $this->postJson('/api/v1/mail/domains', $this->payload(), $this->tenantHeaders('admin'))->assertStatus(201);

        $this->getJson('/api/v1/mail/domains', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertHeaderMissing('X-Change-Set-Id');
    }
}
