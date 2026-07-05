<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionApiTestCase;

/**
 * Spec 013 US4 (FR-019): `op=regex` searchterms must compile as POSIX ERE —
 * a documented stricter-than-legacy deviation (3.3.1p1 has no compile
 * check, mail_user_filter.tform.php:99-113): one invalid pattern makes
 * Dovecot reject the mailbox's entire custom_mailfilter sieve script.
 */
class MailUserFilterHardeningTest extends MailCompletionApiTestCase
{
    protected function seedBox(): int
    {
        $this->seedDomain();

        return $this->seedMailUser();
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterPayload(array $overrides = []): array
    {
        return array_merge([
            'rulename' => 'Regex rule',
            'source' => 'Subject',
            'op' => 'regex',
            'searchterm' => '^\\[SPAM\\]',
            'action' => 'move',
            'target' => 'Junk',
        ], $overrides);
    }

    /**
     * A filter row seeded raw (bypassing the API, as legacy could).
     */
    protected function seedFilter(int $mailuserId, array $overrides = []): int
    {
        return (int) DB::table('mail_user_filter')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'mailuser_id' => $mailuserId,
            'rulename' => 'Legacy rule',
            'source' => 'Subject',
            'op' => 'regex',
            'searchterm' => '[', // stored bad pattern
            'action' => 'move',
            'target' => 'Junk',
            'active' => 'y',
        ], $overrides), 'filter_id');
    }

    public function test_non_compiling_regex_searchterms_are_rejected(): void
    {
        $id = $this->seedBox();

        foreach (['[', '(', 'a{2,1}', '(?i)spam'] as $pattern) {
            $response = $this->postJson(
                '/api/v1/mail/users/'.$id.'/filters',
                $this->filterPayload(['searchterm' => $pattern]),
                $this->authHeaders()
            );

            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey('searchterm', $response->json('errors'), "pattern: {$pattern}");
        }

        $this->assertSame(0, DB::table('mail_user_filter')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_valid_posix_ere_is_accepted_and_rendered_unchanged(): void
    {
        $id = $this->seedBox();

        $filterId = $this->postJson(
            '/api/v1/mail/users/'.$id.'/filters',
            $this->filterPayload(),
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        // The rendered sieve block is unchanged from the pre-013 output —
        // the compile check must not alter rendering (SC-006).
        $mailfilter = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
        $this->assertStringContainsString('### BEGIN FILTER_ID:'.$filterId, $mailfilter);
        $this->assertStringContainsString('if header :regex    "subject" ["^\\[SPAM\\]"] {', $mailfilter);
    }

    public function test_other_ops_accept_regex_metacharacters_unchanged(): void
    {
        $id = $this->seedBox();

        // Metacharacters are legal search text for non-regex ops — they are
        // escaped at render time (MailUserFilterService), never rejected.
        $this->postJson(
            '/api/v1/mail/users/'.$id.'/filters',
            $this->filterPayload(['op' => 'contains', 'searchterm' => '[50% OFF] (limited*)']),
            $this->authHeaders()
        )->assertStatus(201)->assertJsonPath('searchterm', '[50% OFF] (limited*)');
    }

    public function test_deactivating_a_filter_with_a_stored_bad_pattern_succeeds(): void
    {
        $id = $this->seedBox();
        $filterId = $this->seedFilter($id);

        // FR-012 tolerance: the recovery flow must never be blocked by
        // stored garbage in untouched fields.
        $this->putJson(
            '/api/v1/mail/users/'.$id.'/filters/'.$filterId,
            ['active' => false],
            $this->authHeaders()
        )->assertOk()->assertJsonPath('active', false);

        $this->assertSame('[', DB::table('mail_user_filter')->where('filter_id', $filterId)->value('searchterm'));
    }

    public function test_switching_op_to_regex_revalidates_the_stored_searchterm(): void
    {
        $id = $this->seedBox();
        $filterId = $this->seedFilter($id, ['op' => 'contains']); // stored searchterm '[' was fine for 'contains'

        $response = $this->putJson(
            '/api/v1/mail/users/'.$id.'/filters/'.$filterId,
            ['op' => 'regex'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('op', $response->json('errors'));

        // Submitting a compiling searchterm along with the op change works.
        $this->putJson(
            '/api/v1/mail/users/'.$id.'/filters/'.$filterId,
            ['op' => 'regex', 'searchterm' => '^\\[SPAM\\]'],
            $this->authHeaders()
        )->assertOk()->assertJsonPath('op', 'regex');
    }

    public function test_updating_searchterm_on_a_stored_regex_filter_is_validated(): void
    {
        $id = $this->seedBox();
        $filterId = $this->seedFilter($id, ['searchterm' => '^valid$']);

        $response = $this->putJson(
            '/api/v1/mail/users/'.$id.'/filters/'.$filterId,
            ['searchterm' => 'a{2,1}'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('searchterm', $response->json('errors'));
    }
}
