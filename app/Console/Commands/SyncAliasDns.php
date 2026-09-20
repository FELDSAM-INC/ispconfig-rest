<?php

namespace App\Console\Commands;

use App\Services\AliasServicesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAliasDns extends Command
{
    protected $signature = 'aliases:sync-dns';

    protected $description = 'Reconcile synchronized alias DNS zones, including changes made in ISPConfig';

    public function handle(AliasServicesService $services): int
    {
        if (! $services->available()) {
            $this->error('Run the API database migrations first.');

            return self::FAILURE;
        }
        $failed = false;
        foreach (DB::table('api_alias_services')->where('dns_sync', true)->orderBy('web_domain_id')->pluck('web_domain_id') as $id) {
            try {
                $services->reconcile((int) $id);
            } catch (\Throwable $error) {
                $failed = true;
                report($error);
                $this->error('Alias '.$id.' could not be synchronized. See the API log.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
