<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

class SslCertificateAccessTest extends SitesApiTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        TenantSchema::create();
        $this->seedTenants();
    }

    public function test_read_only_website_does_not_expose_private_key(): void
    {
        $id = $this->seedVhost();
        DB::table('web_domain')->where('domain_id', $id)->update($this->ownedBy('clientB', [
            'sys_perm_other' => 'r', 'ssl_cert' => 'fixture-cert', 'ssl_key' => 'fixture-private-key',
        ]));
        $this->getJson('/api/v1/sites/web-domains/'.$id.'/ssl', $this->tenantHeaders('clientA'))->assertForbidden()->assertDontSee('fixture-private-key');
        $this->getJson('/api/v1/sites/web-domains/'.$id.'/ssl', $this->tenantHeaders('clientB'))->assertOk()->assertJsonPath('ssl_key', 'fixture-private-key');
        $this->getJson('/api/v1/sites/web-domains/'.$id.'/ssl', $this->tenantHeaders('admin'))->assertOk();
    }
}
