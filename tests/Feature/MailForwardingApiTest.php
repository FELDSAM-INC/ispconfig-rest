<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionApiTestCase;

/**
 * /mail/forwards + /mail/alias-domains — the mail_forwarding family
 * (spec 005 US2, C-1).
 */
class MailForwardingApiTest extends MailCompletionApiTestCase
{
    // ------------------------------------------------------------------
    // Forwards
    // ------------------------------------------------------------------

    public function test_forwards_crud_lifecycle(): void
    {
        $this->seedDomain(['sys_groupid' => 5]);

        $this->runCrudLifecycle(
            '/api/v1/mail/forwards',
            ['type' => 'forward', 'source' => 'info@example.com', 'destination' => 'target@other.tld'],
            ['active' => false],
            'mail_forwarding',
            'forwarding_id'
        );
    }

    public function test_forward_create_normalizes_destination_and_inherits_domain_ownership(): void
    {
        $this->seedDomain(['sys_groupid' => 5, 'server_id' => 1]);

        $response = $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward',
            'source' => 'Info@Example.COM',
            'destination' => 'a@x.com; b@y.com  c@z.com',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('source', 'info@example.com')
            ->assertJsonPath('destination', 'a@x.com, b@y.com, c@z.com') // split + re-joined ', '
            ->assertJsonPath('type', 'forward')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonPath('active', true) // legacy defaults
            ->assertJsonPath('greylisting', false)
            ->assertJsonPath('allow_send_as', false) // forward default n
            ->assertJsonPath('is_catchall', false);

        $data = $this->assertDatalog('mail_forwarding', 'i', 'forwarding_id:'.$response->json('id'));
        $this->assertSame('forward', $data['new']['type']);
        $this->assertSame('a@x.com, b@y.com, c@z.com', $data['new']['destination']);
        $this->assertSame('y', $data['new']['active']);
    }

    public function test_alias_defaults_allow_send_as_and_catchall_source_format(): void
    {
        $this->seedDomain();

        // alias: allow_send_as defaults to true (legacy mail_alias.tform).
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'alias', 'source' => 'sales@example.com', 'destination' => 'box@example.com',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('allow_send_as', true);

        // catchall: source must be @domain.tld.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'catchall', 'source' => 'not-at-domain', 'destination' => 'box@example.com',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['source']]);

        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'catchall', 'source' => '@example.com', 'destination' => 'box@example.com',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('is_catchall', true)
            ->assertJsonPath('allow_send_as', false);
    }

    public function test_forward_validation_collisions_and_unknown_domain(): void
    {
        $this->seedDomain();
        $this->seedMailUser(['email' => 'busy@example.com', 'postfix' => 'y']);
        DB::table('mail_forwarding')->insert([
            'server_id' => 1, 'source' => 'dup@example.com', 'destination' => 'x@y.tld',
            'type' => 'forward', 'active' => 'y',
        ]);

        // Active mailbox collision (legacy duplicate_mailbox_txt).
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'busy@example.com', 'destination' => 'x@y.tld',
        ], $this->authHeaders())->assertStatus(422);

        // source + type unique.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'dup@example.com', 'destination' => 'x@y.tld',
        ], $this->authHeaders())->assertStatus(422);

        // Empty/invalid destinations.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'new@example.com', 'destination' => '',
        ], $this->authHeaders())->assertStatus(422);

        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'new@example.com', 'destination' => 'ok@x.com, not-an-email',
        ], $this->authHeaders())->assertStatus(422);

        // Unknown source domain -> 400.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'a@nodomain.tld', 'destination' => 'x@y.tld',
        ], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_forward_source_and_type_immutable_on_update(): void
    {
        $this->seedDomain();
        $id = $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'info@example.com', 'destination' => 'x@y.tld',
        ], $this->authHeaders())->json('id');

        $this->putJson('/api/v1/mail/forwards/'.$id, ['source' => 'moved@example.com'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['source']]);

        $this->putJson('/api/v1/mail/forwards/'.$id, ['type' => 'alias'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['type']]);

        // Same values + a destination change go through.
        $this->putJson('/api/v1/mail/forwards/'.$id, [
            'source' => 'info@example.com', 'type' => 'forward', 'destination' => 'a@b.com;c@d.com',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('destination', 'a@b.com, c@d.com');
    }

    public function test_forwards_list_filters_and_aliasdomain_exclusion(): void
    {
        $this->seedDomain();
        DB::table('mail_forwarding')->insert([
            ['server_id' => 1, 'source' => 'a@example.com', 'destination' => 'x@y.tld', 'type' => 'forward', 'active' => 'y', 'allow_send_as' => 'n', 'greylisting' => 'n'],
            ['server_id' => 1, 'source' => '@example.com', 'destination' => 'x@y.tld', 'type' => 'catchall', 'active' => 'n', 'allow_send_as' => 'n', 'greylisting' => 'n'],
            ['server_id' => 1, 'source' => '@aliased.tld', 'destination' => '@example.com', 'type' => 'aliasdomain', 'active' => 'y', 'allow_send_as' => 'n', 'greylisting' => 'n'],
        ]);
        $aliasDomainId = (int) DB::table('mail_forwarding')->where('type', 'aliasdomain')->value('forwarding_id');

        // aliasdomain rows never appear on /mail/forwards.
        $this->getJson('/api/v1/mail/forwards', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/mail/forwards?type=catchall', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.is_catchall', true);

        $this->getJson('/api/v1/mail/forwards?type=aliasdomain', $this->authHeaders())
            ->assertStatus(400); // not a /mail/forwards type

        $this->getJson('/api/v1/mail/forwards?active=false', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/mail/forwards?source=a@example.com', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/mail/forwards/'.$aliasDomainId, $this->authHeaders())
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Alias domains
    // ------------------------------------------------------------------

    public function test_alias_domains_crud_lifecycle(): void
    {
        $this->seedDomain(['domain' => 'example.com']);
        $this->seedDomain(['domain' => 'aliased.tld']);

        $this->runCrudLifecycle(
            '/api/v1/mail/alias-domains',
            ['source' => '@aliased.tld', 'destination' => '@example.com'],
            ['active' => false],
            'mail_forwarding',
            'forwarding_id'
        );
    }

    public function test_alias_domain_create_stores_hidden_type_and_destination_ownership(): void
    {
        $this->seedDomain(['domain' => 'example.com', 'sys_groupid' => 7, 'server_id' => 1]);
        $this->seedDomain(['domain' => 'aliased.tld', 'sys_groupid' => 9, 'server_id' => 1]);

        // '@' prefixes are added automatically; IDN + lowercase applied.
        $response = $this->postJson('/api/v1/mail/alias-domains', [
            'source' => 'Aliased.TLD',
            'destination' => 'example.com',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('source', '@aliased.tld')
            ->assertJsonPath('destination', '@example.com')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 7) // from the DESTINATION domain
            ->assertJsonPath('active', true)
            ->assertJsonMissingPath('type') // hidden discriminator (C-1)
            ->assertJsonMissingPath('allow_send_as')
            ->assertJsonMissingPath('greylisting')
            ->assertJsonMissingPath('is_catchall');

        $id = $response->json('id');

        // Persisted as a mail_forwarding row with type='aliasdomain'.
        $this->assertDatabaseHas('mail_forwarding', ['forwarding_id' => $id, 'type' => 'aliasdomain']);

        $data = $this->assertDatalog('mail_forwarding', 'i', 'forwarding_id:'.$id);
        $this->assertSame('aliasdomain', $data['new']['type']);
        $this->assertSame('@aliased.tld', $data['new']['source']);
    }

    public function test_alias_domain_validation_and_missing_domains(): void
    {
        $this->seedDomain(['domain' => 'example.com']);
        $this->seedDomain(['domain' => 'aliased.tld']);

        // Destination not a mail domain -> 400 (spec acceptance scenario).
        $this->postJson('/api/v1/mail/alias-domains', [
            'source' => '@aliased.tld', 'destination' => '@missing.tld',
        ], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        // Source not a mail domain -> 400.
        $this->postJson('/api/v1/mail/alias-domains', [
            'source' => '@missing.tld', 'destination' => '@example.com',
        ], $this->authHeaders())->assertStatus(400);

        // source == destination -> 422.
        $this->postJson('/api/v1/mail/alias-domains', [
            'source' => '@example.com', 'destination' => '@example.com',
        ], $this->authHeaders())->assertStatus(422);

        // Unique source among alias domains -> 422.
        $this->postJson('/api/v1/mail/alias-domains', [
            'source' => '@aliased.tld', 'destination' => '@example.com',
        ], $this->authHeaders())->assertStatus(201);

        $this->postJson('/api/v1/mail/alias-domains', [
            'source' => '@aliased.tld', 'destination' => '@example.com',
        ], $this->authHeaders())->assertStatus(422);
    }

    public function test_alias_domain_update_source_immutable_and_destination_rederives_server(): void
    {
        $this->seedDomain(['domain' => 'example.com', 'server_id' => 1]);
        $this->seedDomain(['domain' => 'aliased.tld', 'server_id' => 1]);

        $id = $this->postJson('/api/v1/mail/alias-domains', [
            'source' => '@aliased.tld', 'destination' => '@example.com',
        ], $this->authHeaders())->json('id');

        $this->putJson('/api/v1/mail/alias-domains/'.$id, ['source' => '@other.tld'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['source']]);

        // New destination must exist -> 400 otherwise.
        $this->putJson('/api/v1/mail/alias-domains/'.$id, ['destination' => '@missing.tld'], $this->authHeaders())
            ->assertStatus(400);

        $this->seedDomain(['domain' => 'third.tld', 'server_id' => 1, 'sys_groupid' => 11]);
        $this->putJson('/api/v1/mail/alias-domains/'.$id, ['destination' => '@third.tld'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('destination', '@third.tld');

        // A forward row 404s on the alias-domain resource.
        $fwdId = (int) DB::table('mail_forwarding')->insertGetId([
            'server_id' => 1, 'source' => 'x@example.com', 'destination' => 'y@z.tld',
            'type' => 'forward', 'active' => 'y',
        ], 'forwarding_id');

        $this->getJson('/api/v1/mail/alias-domains/'.$fwdId, $this->authHeaders())->assertStatus(404);

        // And alias-domain lists exclude the forward family.
        $this->getJson('/api/v1/mail/alias-domains', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
