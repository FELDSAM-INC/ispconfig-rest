<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;

/**
 * Spec 019 US2 — canceled toggles the client's control-panel login
 * (legacy func_client_cancel; cancel on create is an intentional deviation).
 */
class ClientCancelApiTest extends ClientApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Inc.',
            'contact_name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'username' => 'johndoe',
            'password' => 's3cr3tP@ssw0rd',
        ], $overrides);
    }

    protected function loginActive(int $clientId): int
    {
        return (int) DB::table('sys_user')->where('client_id', $clientId)->value('active');
    }

    public function test_create_with_canceled_creates_an_inactive_login(): void
    {
        $canceled = $this->postJson('/api/v1/clients', $this->validPayload(['canceled' => true]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('canceled', true)
            ->json('id');

        $regular = $this->postJson('/api/v1/clients', $this->validPayload([
            'username' => 'janedoe', 'email' => 'jane.doe@example.com',
        ]), $this->authHeaders())
            ->assertStatus(201)
            ->json('id');

        $this->assertSame(0, $this->loginActive($canceled));
        $this->assertSame(1, $this->loginActive($regular));
        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', 'sys_user')->count());
    }

    public function test_changing_canceled_toggles_the_login(): void
    {
        $clientId = $this->seedClient(['username' => 'jdoe']);
        $this->seedClientLogin($clientId, 'jdoe');

        $this->putJson('/api/v1/clients/'.$clientId, ['canceled' => true], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('canceled', true);
        $this->assertSame(0, $this->loginActive($clientId));

        $this->putJson('/api/v1/clients/'.$clientId, ['canceled' => false], $this->authHeaders())->assertOk();
        $this->assertSame(1, $this->loginActive($clientId));

        $rows = DB::table('sys_datalog')->count();
        DB::table('sys_user')->where('client_id', $clientId)->update(['active' => 0]);

        // Unchanged value: no side effect, even when the login row drifted.
        $this->putJson('/api/v1/clients/'.$clientId, ['canceled' => false], $this->authHeaders())->assertOk();
        $this->assertSame(0, $this->loginActive($clientId));
        $this->assertSame($rows, DB::table('sys_datalog')->count());
        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', 'sys_user')->count());
    }

    public function test_cancel_is_independent_of_lock(): void
    {
        $snapshot = serialize(['prev_active' => ['web_domain' => [5 => ['active' => 'n']]]]);
        $clientId = $this->seedClient(['username' => 'jdoe', 'locked' => 'y', 'tmp_data' => $snapshot]);
        $this->seedClientLogin($clientId, 'jdoe');

        $this->putJson('/api/v1/clients/'.$clientId, ['canceled' => true], $this->authHeaders())->assertOk();

        $this->assertSame(0, $this->loginActive($clientId));
        $this->assertSame('y', DB::table('client')->where('client_id', $clientId)->value('locked'));
        $this->assertSame($snapshot, DB::table('client')->where('client_id', $clientId)->value('tmp_data'));
        $this->assertSame(['client'], DB::table('sys_datalog')->pluck('dbtable')->unique()->values()->all());
    }

    public function test_keys_of_canceled_and_locked_clients_keep_working(): void
    {
        $clientId = $this->seedClient(['username' => 'jdoe', 'locked' => 'y', 'canceled' => 'y']);
        ['groupId' => $groupId, 'userId' => $userId] = $this->seedClientLogin($clientId, 'jdoe');
        DB::table('sys_user')->where('userid', $userId)->update(['active' => 0]);

        [, $plaintext] = ApiKey::mint('client key', $userId, $groupId);

        $this->getJson('/api/v1/me', ['X-API-Key' => $plaintext])
            ->assertOk()
            ->assertJsonFragment(['scope' => 'client', 'client_id' => $clientId]);
    }
}
