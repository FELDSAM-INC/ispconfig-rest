<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionApiTestCase;

/**
 * /mail/users/{id}/autoresponder, /cc, /spamfilter and /filters
 * (spec 005 US3).
 */
class MailUserSubresourceApiTest extends MailCompletionApiTestCase
{
    protected function seedBox(): int
    {
        $this->seedDomain();

        return $this->seedMailUser();
    }

    // ------------------------------------------------------------------
    // Cross-cutting: auth + missing parent
    // ------------------------------------------------------------------

    public function test_subresources_require_api_key_and_404_for_missing_mailbox(): void
    {
        $id = $this->seedBox();

        foreach ([
            ['GET', '/autoresponder'], ['PUT', '/autoresponder'], ['DELETE', '/autoresponder'],
            ['GET', '/cc'], ['PUT', '/cc'],
            ['GET', '/spamfilter'], ['PUT', '/spamfilter'],
            ['GET', '/filters'], ['POST', '/filters'],
        ] as [$method, $suffix]) {
            $this->json($method, '/api/v1/mail/users/'.$id.$suffix)
                ->assertStatus(401)
                ->assertHeader('Content-Type', 'application/problem+json');

            // Missing parent mailbox 404s before any validation.
            $this->json($method, '/api/v1/mail/users/999'.$suffix, [], $this->authHeaders())
                ->assertStatus(404)
                ->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    // ------------------------------------------------------------------
    // Autoresponder
    // ------------------------------------------------------------------

    public function test_autoresponder_get_put_and_validation(): void
    {
        $id = $this->seedBox();

        $this->getJson('/api/v1/mail/users/'.$id.'/autoresponder', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('email', 'box@example.com')
            ->assertJsonPath('autoresponder', false)
            ->assertJsonPath('autoresponder_subject', 'Out of office reply') // legacy default
            ->assertJsonPath('autoresponder_start_date', null);

        $this->putJson('/api/v1/mail/users/'.$id.'/autoresponder', [
            'autoresponder' => true,
            'autoresponder_start_date' => '2026-08-01 09:00:00',
            'autoresponder_end_date' => '2026-08-15 18:00:00',
            'autoresponder_subject' => 'Vacation',
            'autoresponder_text' => 'Back on the 15th.',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('autoresponder', true)
            ->assertJsonPath('autoresponder_subject', 'Vacation');

        $data = $this->assertDatalog('mail_user', 'u', 'mailuser_id:'.$id);
        $this->assertSame('n', $data['old']['autoresponder']);
        $this->assertSame('y', $data['new']['autoresponder']);
        $this->assertSame('2026-08-01 09:00:00', $data['new']['autoresponder_start_date']);

        // end_date <= start_date is the legacy validate_autoresponder error.
        $this->putJson('/api/v1/mail/users/'.$id.'/autoresponder', [
            'autoresponder' => true,
            'autoresponder_start_date' => '2026-08-15 09:00:00',
            'autoresponder_end_date' => '2026-08-01 18:00:00',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['autoresponder_end_date']]);

        // The stored start date also guards a partial PUT.
        $this->putJson('/api/v1/mail/users/'.$id.'/autoresponder', [
            'autoresponder' => true,
            'autoresponder_end_date' => '2026-07-01 18:00:00',
        ], $this->authHeaders())->assertStatus(422);
    }

    public function test_autoresponder_disable_clears_dates(): void
    {
        $id = $this->seedBox();
        DB::table('mail_user')->where('mailuser_id', $id)->update([
            'autoresponder' => 'y',
            'autoresponder_start_date' => '2026-08-01 09:00:00',
            'autoresponder_end_date' => '2026-08-15 18:00:00',
        ]);

        // PUT autoresponder=false clears both dates (legacy parity).
        $this->putJson('/api/v1/mail/users/'.$id.'/autoresponder', ['autoresponder' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('autoresponder', false)
            ->assertJsonPath('autoresponder_start_date', null)
            ->assertJsonPath('autoresponder_end_date', null);

        // Re-enable, then DELETE does the same and returns 204.
        DB::table('mail_user')->where('mailuser_id', $id)->update([
            'autoresponder' => 'y',
            'autoresponder_start_date' => '2026-08-01 09:00:00',
            'autoresponder_end_date' => '2026-08-15 18:00:00',
        ]);

        $this->deleteJson('/api/v1/mail/users/'.$id.'/autoresponder', [], $this->authHeaders())
            ->assertStatus(204);

        $row = DB::table('mail_user')->where('mailuser_id', $id)->first();
        $this->assertSame('n', $row->autoresponder);
        $this->assertNull($row->autoresponder_start_date);
        $this->assertNull($row->autoresponder_end_date);
        $this->assertDatabaseHas('mail_user', ['mailuser_id' => $id]); // never a row delete

        // Both writes are mail_user datalog updates.
        $this->assertSame(2, $this->datalogRows('mail_user')->where('action', 'u')->count());
    }

    // ------------------------------------------------------------------
    // CC
    // ------------------------------------------------------------------

    public function test_cc_get_put_normalization_and_validation(): void
    {
        $id = $this->seedBox();

        $this->getJson('/api/v1/mail/users/'.$id.'/cc', $this->authHeaders())
            ->assertOk()
            ->assertJson(['id' => $id, 'cc' => '', 'forward_in_lda' => false]);

        $this->putJson('/api/v1/mail/users/'.$id.'/cc', [
            'cc' => 'Copy@Example.COM, other@MüLLER.de',
            'forward_in_lda' => true,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('cc', 'copy@example.com,other@xn--mller-kva.de') // lowercase + punycode
            ->assertJsonPath('forward_in_lda', true);

        $data = $this->assertDatalog('mail_user', 'u', 'mailuser_id:'.$id);
        $this->assertSame('copy@example.com,other@xn--mller-kva.de', $data['new']['cc']);
        $this->assertSame('y', $data['new']['forward_in_lda']);

        $this->putJson('/api/v1/mail/users/'.$id.'/cc', ['cc' => 'not-an-email'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cc']]);

        // Empty cc disables copies.
        $this->putJson('/api/v1/mail/users/'.$id.'/cc', ['cc' => ''], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('cc', '');
    }

    // ------------------------------------------------------------------
    // Spamfilter settings
    // ------------------------------------------------------------------

    public function test_spamfilter_settings_get_put_and_validation(): void
    {
        $id = $this->seedBox();

        $this->getJson('/api/v1/mail/users/'.$id.'/spamfilter', $this->authHeaders())
            ->assertOk()
            ->assertJson([
                'id' => $id,
                'move_junk' => 'y',
                'purge_trash_days' => 0,
                'purge_junk_days' => 0,
            ]);

        $this->putJson('/api/v1/mail/users/'.$id.'/spamfilter', [
            'move_junk' => 'a',
            'purge_trash_days' => 30,
            'purge_junk_days' => 14,
            'custom_mailfilter' => 'if false { discard; }',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('move_junk', 'a')
            ->assertJsonPath('purge_trash_days', 30)
            ->assertJsonPath('custom_mailfilter', 'if false { discard; }');

        $data = $this->assertDatalog('mail_user', 'u', 'mailuser_id:'.$id);
        $this->assertSame('a', $data['new']['move_junk']); // exact 3-state flag

        $this->putJson('/api/v1/mail/users/'.$id.'/spamfilter', ['move_junk' => 'x'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['move_junk']]);

        $this->putJson('/api/v1/mail/users/'.$id.'/spamfilter', ['purge_junk_days' => -1], $this->authHeaders())
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Filter rules
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function filterPayload(array $overrides = []): array
    {
        return array_merge([
            'rulename' => 'Move newsletters',
            'source' => 'Subject',
            'op' => 'contains',
            'searchterm' => 'newsletter',
            'action' => 'move',
            'target' => 'Junk',
        ], $overrides);
    }

    public function test_filter_create_regenerates_custom_mailfilter_in_sieve(): void
    {
        $id = $this->seedBox();

        $response = $this->postJson('/api/v1/mail/users/'.$id.'/filters', $this->filterPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('rulename', 'Move newsletters')
            ->assertJsonPath('mailuser_id', $id)
            ->assertJsonPath('active', true);

        $filterId = $response->json('id');

        // mail_user_filter datalog 'i' + companion mail_user datalog 'u'.
        $this->assertDatalog('mail_user_filter', 'i', 'filter_id:'.$filterId);
        $userData = $this->assertDatalog('mail_user', 'u', 'mailuser_id:'.$id);

        $mailfilter = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
        $this->assertStringContainsString('### BEGIN FILTER_ID:'.$filterId, $mailfilter);
        $this->assertStringContainsString('### END FILTER_ID:'.$filterId, $mailfilter);
        // Server mail config declares sieve syntax (test fixture).
        $this->assertStringContainsString('if header :regex    "subject" [".*newsletter"] {', $mailfilter);
        $this->assertStringContainsString('fileinto "Junk";', $mailfilter);
        $this->assertSame($mailfilter, $userData['new']['custom_mailfilter']);
    }

    public function test_filter_new_rules_are_prepended_and_inactive_rules_render_nothing(): void
    {
        $id = $this->seedBox();

        $first = $this->postJson('/api/v1/mail/users/'.$id.'/filters', $this->filterPayload(), $this->authHeaders())->json('id');
        $second = $this->postJson('/api/v1/mail/users/'.$id.'/filters', $this->filterPayload([
            'rulename' => 'Delete spam', 'searchterm' => 'spam', 'action' => 'delete', 'target' => '',
        ]), $this->authHeaders())->json('id');

        $mailfilter = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');

        // Legacy prepends new rules.
        $this->assertLessThan(
            strpos($mailfilter, '### BEGIN FILTER_ID:'.$first),
            strpos($mailfilter, '### BEGIN FILTER_ID:'.$second)
        );

        // Deactivating a rule removes its block, keeps the row.
        $this->putJson('/api/v1/mail/users/'.$id.'/filters/'.$second, ['active' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false);

        $mailfilter = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
        $this->assertStringNotContainsString('### BEGIN FILTER_ID:'.$second, $mailfilter);
        $this->assertStringContainsString('### BEGIN FILTER_ID:'.$first, $mailfilter);
    }

    public function test_filter_update_replaces_block_and_delete_removes_it(): void
    {
        $id = $this->seedBox();
        $filterId = $this->postJson('/api/v1/mail/users/'.$id.'/filters', $this->filterPayload(), $this->authHeaders())->json('id');

        $this->putJson('/api/v1/mail/users/'.$id.'/filters/'.$filterId, ['searchterm' => 'weekly digest'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('searchterm', 'weekly digest');

        $mailfilter = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
        $this->assertStringContainsString('weekly digest', $mailfilter);
        $this->assertStringNotContainsString('.*newsletter', $mailfilter);
        $this->assertSame(1, substr_count($mailfilter, '### BEGIN FILTER_ID:'.$filterId)); // replaced, not duplicated

        $this->deleteJson('/api/v1/mail/users/'.$id.'/filters/'.$filterId, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('mail_user_filter', ['filter_id' => $filterId]);
        $this->assertDatalog('mail_user_filter', 'd', 'filter_id:'.$filterId);

        $mailfilter = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
        $this->assertStringNotContainsString('FILTER_ID:'.$filterId, $mailfilter);
    }

    public function test_filter_list_and_cross_mailbox_scoping(): void
    {
        $id = $this->seedBox();
        $otherId = $this->seedMailUser(['email' => 'other@example.com', 'login' => 'other@example.com']);

        $mine = $this->postJson('/api/v1/mail/users/'.$id.'/filters', $this->filterPayload(), $this->authHeaders())->json('id');
        $foreign = $this->postJson('/api/v1/mail/users/'.$otherId.'/filters', $this->filterPayload(['rulename' => 'Other']), $this->authHeaders())->json('id');

        $this->getJson('/api/v1/mail/users/'.$id.'/filters', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine)
            ->assertJsonMissingPath('data.0.sys_userid'); // contract exposes no sys fields

        $this->getJson('/api/v1/mail/users/'.$id.'/filters?active=true', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/mail/users/'.$id.'/filters?sort=evil', $this->authHeaders())
            ->assertStatus(400);

        // A filter of another mailbox 404s on every nested operation.
        $this->getJson('/api/v1/mail/users/'.$id.'/filters/'.$foreign, $this->authHeaders())->assertStatus(404);
        $this->putJson('/api/v1/mail/users/'.$id.'/filters/'.$foreign, ['rulename' => 'X'], $this->authHeaders())->assertStatus(404);
        $this->deleteJson('/api/v1/mail/users/'.$id.'/filters/'.$foreign, [], $this->authHeaders())->assertStatus(404);
        $this->assertDatabaseHas('mail_user_filter', ['filter_id' => $foreign]);
    }

    public function test_filter_validation_failures_return_422(): void
    {
        $id = $this->seedBox();

        $cases = [
            'missing rulename' => [$this->filterPayload(['rulename' => null]), 'rulename'],
            'rulename too long' => [$this->filterPayload(['rulename' => str_repeat('a', 65)]), 'rulename'],
            'bad source enum' => [$this->filterPayload(['source' => 'subject_txt']), 'source'], // UI key, not stored value (C-2)
            'bad op enum' => [$this->filterPayload(['op' => 'contains_txt']), 'op'],
            'bad action enum' => [$this->filterPayload(['action' => 'move_to_folder']), 'action'],
            'missing searchterm' => [$this->filterPayload(['searchterm' => null]), 'searchterm'],
            'bad target chars' => [$this->filterPayload(['target' => 'Bad<Target>']), 'target'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/mail/users/'.$id.'/filters', $payload, $this->authHeaders());
            $response->assertStatus(422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('mail_user_filter')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
