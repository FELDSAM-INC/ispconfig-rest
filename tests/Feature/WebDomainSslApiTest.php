<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class WebDomainSslApiTest extends SitesApiTestCase
{
    private static ?array $certPair = null;

    /**
     * Self-signed cert + matching key generated once per process.
     *
     * @return array{cert: string, key: string}
     */
    protected static function certPair(): array
    {
        if (self::$certPair === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'ssl.example.com'], $key);
            $x509 = openssl_csr_sign($csr, null, $key, 30);
            openssl_x509_export($x509, $certPem);
            openssl_pkey_export($key, $keyPem);
            self::$certPair = ['cert' => $certPem, 'key' => $keyPem];
        }

        return self::$certPair;
    }

    public function test_ssl_endpoints_require_api_key(): void
    {
        $id = $this->seedVhost();

        $this->getJson("/api/v1/sites/web-domains/{$id}/ssl")->assertStatus(401);
        $this->postJson("/api/v1/sites/web-domains/{$id}/ssl/renew")->assertStatus(401);
    }

    public function test_get_returns_204_when_no_certificate(): void
    {
        $id = $this->seedVhost();

        $this->getJson("/api/v1/sites/web-domains/{$id}/ssl", $this->authHeaders())
            ->assertStatus(204);
    }

    public function test_get_returns_stored_certificate(): void
    {
        $id = $this->seedVhost();
        DB::table('web_domain')->where('domain_id', $id)->update([
            'ssl' => 'y',
            'ssl_cert' => 'CERT-PEM',
            'ssl_key' => 'KEY-PEM',
            'ssl_bundle' => 'BUNDLE-PEM',
            'ssl_letsencrypt' => 'n',
        ]);

        $this->getJson("/api/v1/sites/web-domains/{$id}/ssl", $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'ssl_cert' => 'CERT-PEM',
                'ssl_key' => 'KEY-PEM',
                'ssl_bundle' => 'BUNDLE-PEM',
                'ssl_letsencrypt' => false,
            ]);
    }

    public function test_upload_stores_certificate_with_ssl_action_save(): void
    {
        $id = $this->seedVhost();
        $pair = self::certPair();

        // Request strings pass Laravel's TrimStrings middleware, so the
        // stored PEM loses its trailing newline — compare trimmed.
        $this->postJson("/api/v1/sites/web-domains/{$id}/ssl", [
            'ssl_cert' => $pair['cert'],
            'ssl_key' => $pair['key'],
            'ssl_bundle' => $pair['cert'],
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ssl_cert', trim($pair['cert']))
            ->assertJsonPath('ssl_letsencrypt', false);

        $this->assertDatabaseHas('web_domain', ['domain_id' => $id, 'ssl_action' => 'save']);

        $rows = $this->datalogRows('web_domain');
        $this->assertCount(1, $rows);
        $this->assertSame('u', $rows[0]->action);
        $data = unserialize($rows[0]->data);
        $this->assertSame('save', $data['new']['ssl_action']);
        $this->assertSame(trim($pair['cert']), $data['new']['ssl_cert']);
        $this->assertSame(trim($pair['key']), $data['new']['ssl_key']);
    }

    public function test_upload_rejects_mismatched_or_malformed_pem(): void
    {
        $id = $this->seedVhost();
        $pair = self::certPair();

        // Malformed cert.
        $this->postJson("/api/v1/sites/web-domains/{$id}/ssl", [
            'ssl_cert' => 'garbage',
            'ssl_key' => $pair['key'],
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ssl_cert']]);

        // Key that does not match the cert.
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $otherKeyPem);

        $this->postJson("/api/v1/sites/web-domains/{$id}/ssl", [
            'ssl_cert' => $pair['cert'],
            'ssl_key' => $otherKeyPem,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ssl_key']]);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_delete_clears_certificate_with_ssl_action_del(): void
    {
        $id = $this->seedVhost();
        DB::table('web_domain')->where('domain_id', $id)->update([
            'ssl_cert' => 'CERT-PEM', 'ssl_key' => 'KEY-PEM', 'ssl_bundle' => 'B',
        ]);

        $this->deleteJson("/api/v1/sites/web-domains/{$id}/ssl", [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseHas('web_domain', ['domain_id' => $id, 'ssl_action' => 'del', 'ssl_cert' => '']);

        $rows = $this->datalogRows('web_domain');
        $this->assertCount(1, $rows);
        $this->assertSame('u', $rows[0]->action);
        $data = unserialize($rows[0]->data);
        $this->assertSame('del', $data['new']['ssl_action']);
        $this->assertSame('CERT-PEM', $data['old']['ssl_cert']);
    }

    public function test_renew_requires_letsencrypt_and_ssl(): void
    {
        $id = $this->seedVhost(); // ssl_letsencrypt=n

        $this->postJson("/api/v1/sites/web-domains/{$id}/ssl/renew", [], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        // LE enabled but ssl disabled -> still 400.
        DB::table('web_domain')->where('domain_id', $id)->update(['ssl_letsencrypt' => 'y', 'ssl' => 'n']);
        $this->postJson("/api/v1/sites/web-domains/{$id}/ssl/renew", [], $this->authHeaders())
            ->assertStatus(400);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_renew_writes_forced_no_change_datalog_update(): void
    {
        $id = $this->seedVhost();
        DB::table('web_domain')->where('domain_id', $id)->update(['ssl_letsencrypt' => 'y', 'ssl' => 'y']);

        $this->postJson("/api/v1/sites/web-domains/{$id}/ssl/renew", [], $this->authHeaders())
            ->assertOk()
            ->assertExactJson(['ssl_letsencrypt' => 'y', 'queued' => true]);

        // A forced datalog u despite zero column changes (resync mechanism).
        $rows = $this->datalogRows('web_domain');
        $this->assertCount(1, $rows);
        $this->assertSame('u', $rows[0]->action);
        $this->assertSame('domain_id:'.$id, $rows[0]->dbidx);

        $data = unserialize($rows[0]->data);
        $this->assertSame(['new', 'old'], array_keys($data)); // forced path serializes new first
        $this->assertSame($data['new'], $data['old']); // no actual change
        $this->assertSame('y', $data['new']['ssl_letsencrypt']);
    }
}
