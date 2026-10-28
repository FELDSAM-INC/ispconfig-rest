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

        // Sieve unquoting must preserve the logical POSIX ERE pattern.
        $mailfilter = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
        $this->assertStringContainsString('### BEGIN FILTER_ID:'.$filterId, $mailfilter);
        $this->assertStringContainsString('if header :regex    "subject" ["^\\\\[SPAM\\\\]"] {', $mailfilter);
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

    public function test_line_breaks_and_nul_cannot_inject_script_or_filter_markers(): void
    {
        $id = $this->seedBox();
        foreach (["bad\" { redirect \"other@example.com\"; }\nif false { #", "x\r\n### END FILTER_ID:1", "x\0y"] as $value) {
            $this->postJson('/api/v1/mail/users/'.$id.'/filters',
                $this->filterPayload(['source' => 'From', 'op' => 'domain', 'searchterm' => $value]),
                $this->authHeaders())->assertStatus(422);
        }
        $this->assertSame(0, DB::table('mail_user_filter')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_quotes_and_backslashes_stay_inside_sieve_literals_for_every_operator(): void
    {
        $id = $this->seedBox();
        $searchterm = '"; redirect "other@example.com"; \\ [text]';
        foreach (['domain', 'localpart', 'contains', 'is', 'begins', 'ends', 'regex'] as $op) {
            $filterId = $this->postJson('/api/v1/mail/users/'.$id.'/filters',
                $this->filterPayload(['source' => 'From', 'op' => $op, 'searchterm' => $searchterm, 'action' => 'keep']),
                $this->authHeaders())->assertCreated()->json('id');
            $script = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
            $this->assertSame(1, preg_match('/^if .*$/m', $script, $lines));
            // Lex the Sieve string boundary independently of the renderer.
            $tokens = [];
            $outside = preg_replace_callback('/"((?:\\\\.|[^"\\\\])*)"/', function (array $match) use (&$tokens): string {
                $tokens[] = preg_replace('/\\\\(.)/s', '$1', $match[1]);

                return 'STRING';
            }, $lines[0]);
            $this->assertStringNotContainsString('redirect', $outside, $op);
            $this->assertCount(2, $tokens, $op);
            if (in_array($op, ['domain', 'localpart', 'regex'], true)) {
                $this->assertSame($searchterm, $tokens[1], $op);
            } else {
                $this->assertSame(1, preg_match('~'.str_replace('~', '\\~', $tokens[1]).'~', $searchterm), $op);
            }
            $this->deleteJson('/api/v1/mail/users/'.$id.'/filters/'.$filterId, [], $this->authHeaders())->assertNoContent();
        }
    }

    public function test_custom_header_name_cannot_end_the_sieve_literal(): void
    {
        $id = $this->seedBox();
        $this->postJson('/api/v1/mail/users/'.$id.'/filters',
            $this->filterPayload(['source' => 'Header', 'op' => 'contains',
                'searchterm' => 'X-Test"], true) { discard; } # : value', 'action' => 'keep']),
            $this->authHeaders())->assertCreated();
        $script = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('custom_mailfilter');
        $outside = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', 'STRING', $script);
        $this->assertStringNotContainsString('discard;', $outside);
    }

    public function test_stored_line_breaks_cannot_be_reactivated_but_can_be_disabled(): void
    {
        $id = $this->seedBox();
        $filterId = $this->seedFilter($id, ['op' => 'domain', 'searchterm' => "bad\nvalue", 'active' => 'n']);
        $url = '/api/v1/mail/users/'.$id.'/filters/'.$filterId;
        $this->putJson($url, ['active' => true], $this->authHeaders())->assertStatus(422);
        $this->assertSame('n', DB::table('mail_user_filter')->where('filter_id', $filterId)->value('active'));
        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->putJson($url, ['active' => false], $this->authHeaders())->assertOk();
    }
}
