<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\MonitorSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 022 — Let's Encrypt issuance outcome (GET /sites/web-domains/{id}/ssl/status).
 *
 * Legacy: apache2_plugin.inc.php 1305-1330 requests the certificate while
 * processing the enabling change and reverts ssl_letsencrypt without a journal
 * entry on failure; letsencrypt.inc.php logs the reasons as warnings.
 */
class WebDomainSslStatusApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected const KEYS = [
        'website_id', 'domain', 'https_enabled', 'letsencrypt_enabled', 'state', 'requested_at',
        'change_set_id', 'change_status', 'failure', 'excluded_domains', 'certificate',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        MonitorSchema::create();
        MonitorCompletionSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'web1', 'web_server' => 1, 'mirror_server_id' => 0,
            'active' => 1, 'updated' => 0, 'config' => "[web]\nserver_type=apache\n",
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function site(array $attrs = [], string $owner = 'clientA'): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'domain' => 'shop'.uniqid().'.example.com', 'type' => 'vhost', 'parent_domain_id' => 0,
            'vhost_type' => 'name', 'active' => 'y', 'subdomain' => 'www', 'ssl' => 'n', 'ssl_letsencrypt' => 'n',
            'hd_quota' => -1, 'traffic_quota' => -1,
        ], $attrs)), 'domain_id');

        if (! array_key_exists('document_root', $attrs)) {
            DB::table('web_domain')->where('domain_id', $id)->update(['document_root' => "/nonexistent/clients/client/web{$id}"]);
        }

        return $id;
    }

    /**
     * Journal entry for a website change (serialized legacy {new, old} payload).
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $row
     */
    protected function journal(int $siteId, array $new, array $old = [], array $row = []): int
    {
        $domain = (string) DB::table('web_domain')->where('domain_id', $siteId)->value('domain');
        $base = ['domain_id' => (string) $siteId, 'domain' => $domain, 'ssl' => 'n', 'ssl_letsencrypt' => 'n', 'subdomain' => 'www'];

        return (int) DB::table('sys_datalog')->insertGetId(array_merge([
            'server_id' => 1,
            'dbtable' => 'web_domain',
            'dbidx' => 'domain_id:'.$siteId,
            'action' => 'u',
            'tstamp' => 1_789_000_000,
            'user' => 'clienta',
            'data' => serialize(['new' => array_merge($base, $new), 'old' => $old === [] && ($row['action'] ?? 'u') === 'i' ? [] : array_merge($base, $old)]),
            'status' => 'ok',
            'error' => null,
            'session_id' => 'cs'.uniqid(),
        ], $row), 'datalog_id');
    }

    protected function enableEntry(int $siteId, array $row = []): int
    {
        return $this->journal($siteId, ['ssl' => 'y', 'ssl_letsencrypt' => 'y'], ['ssl' => 'n', 'ssl_letsencrypt' => 'n'], $row);
    }

    protected function processedUpTo(int $datalogId): void
    {
        DB::table('server')->where('server_id', 1)->update(['updated' => $datalogId]);
    }

    protected function flags(int $siteId, string $ssl, string $le): void
    {
        DB::table('web_domain')->where('domain_id', $siteId)->update(['ssl' => $ssl, 'ssl_letsencrypt' => $le]);
    }

    protected function sslStatus(int $siteId, string $tenant = 'clientA')
    {
        return $this->getJson("/api/v1/sites/web-domains/{$siteId}/ssl/status", $this->tenantHeaders($tenant));
    }

    protected function iso(int $tstamp): string
    {
        return CarbonImmutable::createFromTimestamp($tstamp, (string) config('app.timezone'))->toIso8601String();
    }

    // ------------------------------------------------------------------
    // US1 — state
    // ------------------------------------------------------------------

    public function test_requested_while_the_enabling_change_is_pending(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $entry = $this->enableEntry($site, ['session_id' => 'set-pending', 'tstamp' => 1_789_000_100]);
        $this->processedUpTo($entry - 1);

        $response = $this->sslStatus($site)->assertOk();

        $this->assertSame(self::KEYS, array_keys($response->json()));
        $response->assertJson([
            'website_id' => $site,
            'https_enabled' => true,
            'letsencrypt_enabled' => true,
            'state' => 'requested',
            'requested_at' => $this->iso(1_789_000_100),
            'change_set_id' => 'set-pending',
            'change_status' => 'pending',
            'failure' => null,
            'excluded_domains' => [],
            'certificate' => null,
        ]);
        $response->assertHeaderMissing('X-Change-Set-Id');
    }

    public function test_requested_with_stalled_status_when_no_server_processes_the_change(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $this->enableEntry($site);
        DB::table('server')->where('server_id', 1)->update(['active' => 0]);

        $this->sslStatus($site)->assertOk()
            ->assertJsonPath('state', 'requested')
            ->assertJsonPath('change_status', 'stalled');
    }

    public function test_issued_when_processed_and_still_enabled(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $entry = $this->enableEntry($site, ['session_id' => 'set-issued']);
        $this->processedUpTo($entry);

        $this->sslStatus($site)->assertOk()->assertJson([
            'state' => 'issued',
            'change_set_id' => 'set-issued',
            'change_status' => 'applied',
            'failure' => null,
            'certificate' => null,
        ]);
    }

    public function test_failed_when_processed_and_the_server_switched_it_off(): void
    {
        $site = $this->site();
        $entry = $this->enableEntry($site);
        $this->processedUpTo($entry);
        // The plugin reverted both flags without a journal entry.
        $this->flags($site, 'n', 'n');

        $response = $this->sslStatus($site)->assertOk()
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('https_enabled', false)
            ->assertJsonPath('letsencrypt_enabled', false)
            ->assertJsonPath('change_status', 'applied');

        $this->assertIsArray($response->json('failure'));
        $this->assertNull($response->json('certificate'));
    }

    public function test_insert_with_letsencrypt_counts_as_request(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $entry = $this->journal($site, ['ssl' => 'y', 'ssl_letsencrypt' => 'y'], [], ['action' => 'i']);
        $this->processedUpTo($entry - 1);

        $this->sslStatus($site)->assertOk()->assertJsonPath('state', 'requested');
    }

    public function test_newer_off_entry_reports_none(): void
    {
        $site = $this->site();
        $this->enableEntry($site);
        $off = $this->journal($site, ['ssl' => 'n', 'ssl_letsencrypt' => 'n'], ['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $this->processedUpTo($off - 1);

        $this->sslStatus($site)->assertOk()->assertJson([
            'state' => 'none',
            'requested_at' => null,
            'change_set_id' => null,
            'change_status' => null,
        ]);
    }

    public function test_never_enabled_reports_none(): void
    {
        $site = $this->site();

        $this->sslStatus($site)->assertOk()->assertJsonPath('state', 'none')->assertJsonPath('failure', null);
    }

    public function test_enabled_without_journal_entry_reports_issued(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);

        $this->sslStatus($site)->assertOk()->assertJson([
            'state' => 'issued',
            'requested_at' => null,
            'change_set_id' => null,
            'change_status' => null,
        ]);
    }

    public function test_uploaded_certificate_reports_none_with_https_enabled(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'n']);

        $this->sslStatus($site)->assertOk()
            ->assertJsonPath('state', 'none')
            ->assertJsonPath('https_enabled', true);
    }

    public function test_unrelated_entries_and_other_websites_are_ignored(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $other = $this->site();
        $entry = $this->enableEntry($site, ['session_id' => 'set-site']);
        // Newer PHP change of the same website and a request of another website.
        $this->journal($site, ['ssl' => 'y', 'ssl_letsencrypt' => 'y', 'php' => 'php-fpm'], ['ssl' => 'y', 'ssl_letsencrypt' => 'y', 'php' => 'fast-cgi']);
        $otherEntry = $this->enableEntry($other);
        DB::table('sys_datalog')->insert([
            'server_id' => 1, 'dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$site, 'action' => 'u', 'tstamp' => 1,
            'data' => serialize(['new' => ['ssl' => 'y', 'ssl_letsencrypt' => 'y'], 'old' => []]), 'session_id' => 'x',
        ]);
        $this->processedUpTo($otherEntry);

        $this->sslStatus($site)->assertOk()
            ->assertJsonPath('state', 'issued')
            ->assertJsonPath('change_set_id', 'set-site');
        $this->sslStatus($other)->assertOk()->assertJsonPath('state', 'failed');
    }

    public function test_corrupt_payload_is_skipped(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $entry = $this->enableEntry($site, ['session_id' => 'set-good']);
        DB::table('sys_datalog')->insert([
            'server_id' => 1, 'dbtable' => 'web_domain', 'dbidx' => 'domain_id:'.$site, 'action' => 'u',
            'tstamp' => 1_789_000_500, 'data' => 'not-serialized', 'session_id' => 'set-corrupt',
        ]);
        $this->processedUpTo($entry + 5);

        $this->sslStatus($site)->assertOk()
            ->assertJsonPath('state', 'issued')
            ->assertJsonPath('change_set_id', 'set-good');
    }

    public function test_requires_api_key(): void
    {
        $site = $this->site();

        $this->getJson("/api/v1/sites/web-domains/{$site}/ssl/status")->assertStatus(401);
    }

    public function test_other_tenant_gets_404_and_admin_can_read(): void
    {
        $site = $this->site();

        $this->sslStatus($site, 'clientB')->assertStatus(404);
        $this->sslStatus($site, 'admin')->assertOk()->assertJsonPath('state', 'none');
        $this->sslStatus($site, 'reseller')->assertOk();
    }

    public function test_non_vhost_type_and_unknown_id_get_404(): void
    {
        $alias = $this->site(['type' => 'alias']);

        $this->sslStatus($alias)->assertStatus(404);
        $this->sslStatus(999_999)->assertStatus(404);
    }
    // ------------------------------------------------------------------
    // US2 — failure reasons (letsencrypt.inc.php warnings in sys_log)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $row
     */
    protected function log(string $message, array $row = []): void
    {
        DB::table('sys_log')->insert(array_merge([
            'server_id' => 1, 'datalog_id' => 0, 'loglevel' => 1, 'tstamp' => 1_789_000_050, 'message' => $message,
        ], $row));
    }

    /**
     * A failed request: returns [site id, domain, entry id].
     *
     * @return array{0: int, 1: string, 2: int}
     */
    protected function failedRequest(): array
    {
        $site = $this->site();
        $entry = $this->enableEntry($site, ['tstamp' => 1_789_000_000]);
        $this->processedUpTo($entry);
        $domain = (string) DB::table('web_domain')->where('domain_id', $site)->value('domain');

        return [$site, $domain, $entry];
    }

    public function test_unreachable_domains_are_reported(): void
    {
        [$site, $domain, $entry] = $this->failedRequest();
        $this->log("Could not verify domain {$domain}, so excluding it from let's encrypt request.", ['datalog_id' => $entry]);
        $this->log("Could not verify domain www.{$domain}, so excluding it from let's encrypt request.", ['datalog_id' => $entry]);

        $this->sslStatus($site)->assertOk()->assertJsonPath('failure', [
            'reason' => 'domain_not_reachable',
            'detail' => 'The domain does not point to this server yet, so the certificate authority could not verify it.',
            'domains' => [$domain, "www.{$domain}"],
        ]);
    }

    public function test_issuance_failure_matched_by_domain_after_the_request_hides_the_command(): void
    {
        [$site, $domain] = $this->failedRequest();
        $this->log("Let's Encrypt SSL Cert for {$domain} via acme.sh could not be issued. Used command: /root/.acme.sh/acme.sh --issue -w /usr/local/ispconfig/interface/acme -d {$domain} --secret-token");

        $response = $this->sslStatus($site)->assertOk()
            ->assertJsonPath('failure.reason', 'issuance_failed')
            ->assertJsonPath('failure.domains', [$domain]);

        foreach (['acme.sh', 'Used command', '/root', '/usr/local', 'secret-token'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_missing_client_takes_precedence(): void
    {
        [$site, $domain, $entry] = $this->failedRequest();
        $this->log("Let's Encrypt SSL Cert for {$domain} via certbot could not be issued. Used command: certbot certonly", ['datalog_id' => $entry]);
        $this->log('Unable to install acme.sh.  Cannot proceed, no Let\'s Encrypt client found.', ['datalog_id' => $entry, 'loglevel' => 1]);

        $this->sslStatus($site)->assertOk()
            ->assertJsonPath('failure.reason', 'client_unavailable')
            ->assertJsonPath('failure.domains', []);
    }

    public function test_certificate_not_found_is_reported(): void
    {
        [$site, , $entry] = $this->failedRequest();
        $this->log("Let's Encrypt Cert file: could not find the issued certificate", ['datalog_id' => $entry]);

        $this->sslStatus($site)->assertOk()->assertJsonPath('failure.reason', 'certificate_not_found');
    }

    public function test_unreachable_domain_takes_precedence_over_issuance_failure(): void
    {
        [$site, $domain, $entry] = $this->failedRequest();
        $this->log("Let's Encrypt SSL Cert for {$domain} via acme.sh could not be issued. Used command: x", ['datalog_id' => $entry]);
        $this->log("Could not verify domain {$domain}, so excluding it from let's encrypt request.", ['datalog_id' => $entry]);

        $this->sslStatus($site)->assertOk()->assertJsonPath('failure.reason', 'domain_not_reachable');
    }

    public function test_unrelated_log_rows_are_ignored(): void
    {
        [$site, $domain] = $this->failedRequest();
        $this->log("Could not verify domain {$domain}, so excluding it from let's encrypt request.", ['server_id' => 2]);
        $this->log("Could not verify domain other.example.org, so excluding it from let's encrypt request.");
        $this->log("Let's Encrypt SSL Cert for {$domain} via acme.sh could not be issued. Used command: x", ['tstamp' => 1_788_000_000]);
        $this->log("Let's Encrypt SSL Cert for {$domain} via acme.sh could not be issued. Used command: x", ['loglevel' => 0]);

        $this->sslStatus($site)->assertOk()->assertJsonPath('failure', [
            'reason' => 'unknown',
            'detail' => 'The certificate could not be issued. Make sure the domain points to this server and try again.',
            'domains' => [],
        ]);
    }

    public function test_invalid_domain_names_are_not_returned(): void
    {
        [$site, , $entry] = $this->failedRequest();
        $this->log("Could not verify domain ../../etc/passwd, so excluding it from let's encrypt request.", ['datalog_id' => $entry]);

        $this->sslStatus($site)->assertOk()
            ->assertJsonPath('failure.reason', 'domain_not_reachable')
            ->assertJsonPath('failure.domains', []);
    }

    public function test_issued_certificate_lists_excluded_domains(): void
    {
        $site = $this->site(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $domain = (string) DB::table('web_domain')->where('domain_id', $site)->value('domain');
        $entry = $this->enableEntry($site);
        $this->processedUpTo($entry);
        $this->log("Could not verify domain www.{$domain}, so excluding it from let's encrypt request.", ['datalog_id' => $entry]);

        $this->sslStatus($site)->assertOk()
            ->assertJsonPath('state', 'issued')
            ->assertJsonPath('failure', null)
            ->assertJsonPath('excluded_domains', ["www.{$domain}"]);
    }

    public function test_later_failure_of_an_issued_certificate_uses_rows_after_the_request(): void
    {
        [$site, $domain] = $this->failedRequest();
        // Written while processing another website's change (no datalog id of this request).
        $this->log("Let's Encrypt SSL Cert for {$domain} via acme.sh could not be issued. Used command: x", ['tstamp' => 1_789_900_000, 'datalog_id' => 777]);

        $this->sslStatus($site)->assertOk()->assertJsonPath('failure.reason', 'issuance_failed');
    }
    // ------------------------------------------------------------------
    // US3 — certificate details
    // ------------------------------------------------------------------

    /** @var array<int, string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/ssl/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir.'/ssl');
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * Document root with a self-signed certificate at ssl/<file>-le.crt.
     *
     * @param  array<int, string>  $sans
     */
    protected function documentRootWithCertificate(string $fileDomain, string $commonName, array $sans = [], ?string $content = null): string
    {
        $dir = sys_get_temp_dir().'/le022-'.uniqid();
        mkdir($dir.'/ssl', 0755, true);
        $this->tempDirs[] = $dir;

        if ($content === null) {
            $options = ['digest_alg' => 'sha256'];

            if ($sans !== []) {
                $config = $dir.'/openssl.cnf';
                file_put_contents($config, implode("\n", [
                    '[req]', 'distinguished_name=dn', '[dn]', '[v3]',
                    'subjectAltName='.implode(',', array_map(fn (string $san): string => 'DNS:'.$san, $sans)),
                ]));
                $options += ['config' => $config, 'x509_extensions' => 'v3'];
            }

            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => $commonName, 'organizationName' => 'Test Encrypt'], $key, $options);
            $x509 = openssl_csr_sign($csr, null, $key, 90, $options);
            openssl_x509_export($x509, $content);
            @unlink($dir.'/openssl.cnf');
        }

        file_put_contents($dir.'/ssl/'.$fileDomain.'-le.crt', $content);

        return $dir;
    }

    /**
     * An issued website with the given attributes: returns [site id, domain].
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function issuedSite(array $attrs): int
    {
        $site = $this->site(array_merge(['ssl' => 'y', 'ssl_letsencrypt' => 'y'], $attrs));
        $this->processedUpTo($this->enableEntry($site));

        return $site;
    }

    public function test_issued_certificate_details_are_read_from_the_certificate_file(): void
    {
        $domain = 'shop'.uniqid().'.example.com';
        $root = $this->documentRootWithCertificate($domain, $domain, [$domain, "www.{$domain}"]);
        $site = $this->issuedSite(['domain' => $domain, 'document_root' => $root]);

        $info = openssl_x509_parse((string) file_get_contents($root."/ssl/{$domain}-le.crt"));

        $this->sslStatus($site)->assertOk()->assertJsonPath('certificate', [
            'valid_from' => $this->iso((int) $info['validFrom_time_t']),
            'expires_at' => $this->iso((int) $info['validTo_time_t']),
            'issuer' => 'Test Encrypt',
            'domains' => [$domain, "www.{$domain}"],
        ]);
    }

    public function test_common_name_is_used_without_subject_alternative_names(): void
    {
        $domain = 'cn'.uniqid().'.example.com';
        $root = $this->documentRootWithCertificate($domain, $domain);
        $site = $this->issuedSite(['domain' => $domain, 'document_root' => $root]);

        $this->sslStatus($site)->assertOk()->assertJsonPath('certificate.domains', [$domain]);
    }

    public function test_wildcard_domain_reads_the_bare_domain_file(): void
    {
        $bare = 'wild'.uniqid().'.example.com';
        $root = $this->documentRootWithCertificate($bare, $bare);
        $site = $this->issuedSite(['domain' => '*.'.$bare, 'document_root' => $root]);

        $this->sslStatus($site)->assertOk()->assertJsonPath('certificate.domains', [$bare]);
    }

    public function test_missing_or_invalid_certificate_file_gives_null(): void
    {
        $domain = 'bad'.uniqid().'.example.com';
        $invalid = $this->documentRootWithCertificate($domain, $domain, [], 'not a certificate');
        $missing = sys_get_temp_dir().'/le022-missing-'.uniqid();

        $this->sslStatus($this->issuedSite(['domain' => $domain, 'document_root' => $invalid]))
            ->assertOk()->assertJsonPath('certificate', null);
        $this->sslStatus($this->issuedSite(['domain' => $domain, 'document_root' => $missing]))
            ->assertOk()->assertJsonPath('certificate', null);
    }

    public function test_unsafe_document_roots_are_not_read(): void
    {
        $domain = 'safe'.uniqid().'.example.com';
        $root = $this->documentRootWithCertificate($domain, $domain);

        $this->sslStatus($this->issuedSite(['domain' => $domain, 'document_root' => $root.'/ssl/..']))
            ->assertOk()->assertJsonPath('certificate', null);
        $this->sslStatus($this->issuedSite(['domain' => $domain, 'document_root' => ltrim($root, '/')]))
            ->assertOk()->assertJsonPath('certificate', null);
    }

    public function test_requested_and_failed_states_never_return_details(): void
    {
        $domain = 'old'.uniqid().'.example.com';
        $root = $this->documentRootWithCertificate($domain, $domain);

        $requested = $this->site(['domain' => $domain, 'document_root' => $root, 'ssl' => 'y', 'ssl_letsencrypt' => 'y']);
        $entry = $this->enableEntry($requested);
        $this->processedUpTo($entry - 1);
        $this->sslStatus($requested)->assertOk()
            ->assertJsonPath('state', 'requested')
            ->assertJsonPath('certificate', null);

        $failed = $this->site(['domain' => 'x'.$domain, 'document_root' => $root]);
        $this->processedUpTo($this->enableEntry($failed));
        $this->sslStatus($failed)->assertOk()
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('certificate', null);
    }
}
