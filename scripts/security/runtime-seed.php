<?php

use App\Models\ApiKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

// Only runtime.py may invoke this fixture, against its new disposable database.
if (getenv('SECURITY_RUNTIME_FIXTURE') !== '1' || getenv('DB_CONNECTION') !== 'sqlite') {
    fwrite(STDERR, "This fixture requires the isolated security runtime runner.\n");
    exit(1);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
MailSchema::create();
TenantSchema::create();

$fixtures = new class
{
    use TenantFixtures;

    public function seed(): array
    {
        $this->seedTenants();
        $domains = [];
        foreach (['clientA', 'clientB'] as $owner) {
            $domains[$owner] = DB::table('mail_domain')->insertGetId(
                $this->ownedBy($owner, ['domain' => strtolower($owner).'.example.test', 'server_id' => 1, 'active' => 'y']),
                'domain_id'
            );
        }
        [$revoked, $key] = ApiKey::mint('runtime revoked');
        $revoked->update(['active' => false]);

        return ['tenants' => $this->tenants, 'domains' => $domains, 'revoked' => $key];
    }
};

echo json_encode($fixtures->seed(), JSON_THROW_ON_ERROR);
