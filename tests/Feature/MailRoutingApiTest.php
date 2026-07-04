<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionApiTestCase;

/**
 * /mail/transports, /mail/relay-domains, /mail/relay-recipients,
 * /mail/access-rules, /mail/content-filters, /mail/fetchmail
 * (spec 005 US5).
 */
class MailRoutingApiTest extends MailCompletionApiTestCase
{
    // ------------------------------------------------------------------
    // Transports
    // ------------------------------------------------------------------

    public function test_transports_crud_lifecycle(): void
    {
        $this->runCrudLifecycle(
            '/api/v1/mail/transports',
            ['server_id' => 1, 'domain' => 'remote.tld', 'transport' => 'smtp:[mail.remote.tld]:587'],
            ['transport' => 'smtp:[mx2.remote.tld]:25'],
            'mail_transport',
            'transport_id'
        );
    }

    public function test_transport_defaults_normalization_and_validation(): void
    {
        $this->seedDomain(['domain' => 'local.tld']);

        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1, 'domain' => 'Remote.TLD', 'transport' => 'smtp:[mail.remote.tld]',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'remote.tld') // lowercased
            ->assertJsonPath('sort_order', 5) // legacy default (C-5)
            ->assertJsonPath('active', true)
            ->assertJsonMissingPath('sys_userid'); // contract exposes no sys fields

        // Duplicate domain per server -> 409 (DB unique key).
        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1, 'domain' => 'remote.tld', 'transport' => 'smtp:x',
        ], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        // A local mail domain is rejected (legacy validate_isnot_maildomain).
        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1, 'domain' => 'local.tld', 'transport' => 'smtp:x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['domain']]);

        // server_id must be a mail server (FR-036).
        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 2, 'domain' => 'other.tld', 'transport' => 'smtp:x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);
    }

    public function test_transport_domain_and_server_immutable_on_update(): void
    {
        $id = $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1, 'domain' => 'remote.tld', 'transport' => 'smtp:x',
        ], $this->authHeaders())->json('id');

        $this->putJson('/api/v1/mail/transports/'.$id, ['domain' => 'moved.tld'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['domain']]);

        $this->putJson('/api/v1/mail/transports/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        $this->putJson('/api/v1/mail/transports/'.$id, [
            'server_id' => 1, 'domain' => 'remote.tld', 'sort_order' => 7,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('sort_order', 7);
    }

    // ------------------------------------------------------------------
    // Relay domains
    // ------------------------------------------------------------------

    public function test_relay_domains_crud_lifecycle(): void
    {
        $this->runCrudLifecycle(
            '/api/v1/mail/relay-domains',
            ['server_id' => 1, 'domain' => 'relay.tld'],
            ['active' => false],
            'mail_relay_domain',
            'relay_domain_id'
        );
    }

    public function test_relay_domain_defaults_conflict_and_immutability(): void
    {
        $created = $this->postJson('/api/v1/mail/relay-domains', [
            'server_id' => 1, 'domain' => 'relay.tld',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('access', 'OK') // hidden legacy default (C-4)
            ->assertJsonPath('active', true);

        $data = $this->assertDatalog('mail_relay_domain', 'i', 'relay_domain_id:'.$created->json('id'));
        $this->assertSame('OK', $data['new']['access']);
        $this->assertSame('y', $data['new']['active']);

        $this->postJson('/api/v1/mail/relay-domains', [
            'server_id' => 1, 'domain' => 'relay.tld',
        ], $this->authHeaders())->assertStatus(409);

        $this->putJson('/api/v1/mail/relay-domains/'.$created->json('id'), ['domain' => 'other.tld'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['domain']]);
    }

    // ------------------------------------------------------------------
    // Relay recipients
    // ------------------------------------------------------------------

    public function test_relay_recipients_crud_lifecycle(): void
    {
        $this->runCrudLifecycle(
            '/api/v1/mail/relay-recipients',
            ['server_id' => 1, 'source' => 'user@relay.tld'],
            ['access' => 'REJECT'],
            'mail_relay_recipient',
            'relay_recipient_id'
        );
    }

    public function test_relay_recipient_defaults_and_immutability(): void
    {
        $id = $this->postJson('/api/v1/mail/relay-recipients', [
            'server_id' => 1, 'source' => '@relay.tld',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('access', 'OK')
            ->assertJsonPath('active', true) // hidden column default (C-4)
            ->json('id');

        $this->putJson('/api/v1/mail/relay-recipients/'.$id, ['source' => '@moved.tld'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['source']]);

        $this->putJson('/api/v1/mail/relay-recipients/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Access rules
    // ------------------------------------------------------------------

    public function test_access_rules_crud_lifecycle(): void
    {
        $this->runCrudLifecycle(
            '/api/v1/mail/access-rules',
            ['server_id' => 1, 'source' => 'spammer@bad.tld', 'type' => 'sender'],
            ['access' => 'OK'],
            'mail_access',
            'access_id'
        );
    }

    public function test_access_rule_defaults_uniqueness_and_immutability(): void
    {
        // access defaults to REJECT, type to recipient (contract/blacklist form).
        $id = $this->postJson('/api/v1/mail/access-rules', [
            'server_id' => 1, 'source' => 'spammer@bad.tld',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('access', 'REJECT')
            ->assertJsonPath('type', 'recipient')
            ->assertJsonPath('active', true)
            ->json('id');

        // source + type unique per server -> 409 on create...
        $this->postJson('/api/v1/mail/access-rules', [
            'server_id' => 1, 'source' => 'spammer@bad.tld', 'type' => 'recipient',
        ], $this->authHeaders())->assertStatus(409);

        // ...but another type for the same source is fine.
        $otherId = $this->postJson('/api/v1/mail/access-rules', [
            'server_id' => 1, 'source' => 'spammer@bad.tld', 'type' => 'sender', 'access' => 'OK',
        ], $this->authHeaders())->assertStatus(201)->json('id');

        // ...and 409 on an update that would collide.
        $this->putJson('/api/v1/mail/access-rules/'.$otherId, ['type' => 'recipient'], $this->authHeaders())
            ->assertStatus(409);

        $this->putJson('/api/v1/mail/access-rules/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        $this->postJson('/api/v1/mail/access-rules', [
            'server_id' => 1, 'source' => 'x@y.tld', 'type' => 'bogus',
        ], $this->authHeaders())->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Content filters
    // ------------------------------------------------------------------

    public function test_content_filters_crud_lifecycle(): void
    {
        $this->runCrudLifecycle(
            '/api/v1/mail/content-filters',
            ['server_id' => 1, 'type' => 'header', 'pattern' => '/^X-Spam-Flag: YES/', 'action' => 'REJECT'],
            ['action' => 'DISCARD'],
            'mail_content_filter',
            'content_filter_id'
        );
    }

    public function test_content_filter_enums_and_immutability(): void
    {
        $id = $this->postJson('/api/v1/mail/content-filters', [
            'server_id' => 1, 'type' => 'header', 'pattern' => '/^Subject: spam/', 'action' => 'PREPEND',
            'data' => 'X-Flagged: yes',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('active', true)
            ->assertJsonPath('data', 'X-Flagged: yes')
            ->json('id');

        $this->postJson('/api/v1/mail/content-filters', [
            'server_id' => 1, 'type' => 'bogus', 'pattern' => 'x', 'action' => 'REJECT',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['type']]);

        $this->postJson('/api/v1/mail/content-filters', [
            'server_id' => 1, 'type' => 'header', 'pattern' => 'x', 'action' => 'reject',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['action']]); // enum is uppercase Postfix actions

        $this->putJson('/api/v1/mail/content-filters/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);
    }

    // ------------------------------------------------------------------
    // Fetchmail (mail_get)
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function fetchmailPayload(array $overrides = []): array
    {
        return array_merge([
            'server_id' => 1,
            'type' => 'pop3',
            'source_server' => 'pop.remote.tld',
            'source_username' => 'remoteuser',
            'source_password' => 'remotepass',
            'destination' => 'box@example.com',
        ], $overrides);
    }

    public function test_fetchmail_crud_lifecycle(): void
    {
        $this->seedDomain();
        $this->seedMailUser();

        $this->runCrudLifecycle(
            '/api/v1/mail/fetchmail',
            $this->fetchmailPayload(),
            ['type' => 'imap'],
            'mail_get',
            'mailget_id'
        );
    }

    public function test_fetchmail_defaults_password_handling_and_validation(): void
    {
        $this->seedDomain();
        $this->seedMailUser();

        $response = $this->postJson('/api/v1/mail/fetchmail', $this->fetchmailPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('source_delete', false) // legacy form default n (DB default y)
            ->assertJsonPath('source_read_all', true)
            ->assertJsonPath('active', true)
            ->assertJsonMissingPath('source_password'); // write-only

        $id = $response->json('id');
        $this->assertSame('remotepass', DB::table('mail_get')->where('mailget_id', $id)->value('source_password'));

        $data = $this->assertDatalog('mail_get', 'i', 'mailget_id:'.$id);
        $this->assertSame('n', $data['new']['source_delete']);
        $this->assertSame('y', $data['new']['source_read_all']);

        // Update without password keeps the stored one; with password replaces it.
        $this->putJson('/api/v1/mail/fetchmail/'.$id, ['source_username' => 'renamed'], $this->authHeaders())->assertOk();
        $this->assertSame('remotepass', DB::table('mail_get')->where('mailget_id', $id)->value('source_password'));

        $this->putJson('/api/v1/mail/fetchmail/'.$id, ['source_password' => 'newpass'], $this->authHeaders())->assertOk();
        $this->assertSame('newpass', DB::table('mail_get')->where('mailget_id', $id)->value('source_password'));

        // server_id immutable.
        $this->putJson('/api/v1/mail/fetchmail/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        // Validation: type enum, source_server regex, destination must be an
        // existing mailbox email (C-3), credentials required.
        $cases = [
            'bad type' => [$this->fetchmailPayload(['type' => 'nntp']), 'type'],
            'bad source_server' => [$this->fetchmailPayload(['source_server' => 'not a host!']), 'source_server'],
            'missing username' => [$this->fetchmailPayload(['source_username' => null]), 'source_username'],
            'missing password' => [$this->fetchmailPayload(['source_password' => null]), 'source_password'],
            'unknown destination mailbox' => [$this->fetchmailPayload(['destination' => 'ghost@example.com']), 'destination'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $failed = $this->postJson('/api/v1/mail/fetchmail', $payload, $this->authHeaders());
            $failed->assertStatus(422);
            $this->assertArrayHasKey($errorField, $failed->json('errors'), "case: {$label}");
        }
    }
}
