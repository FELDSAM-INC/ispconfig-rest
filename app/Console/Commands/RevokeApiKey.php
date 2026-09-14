<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class RevokeApiKey extends Command
{
    protected $signature = 'api:key:revoke {id : Id of the API key to revoke}';

    protected $description = 'Revoke (deactivate) an API key; it is rejected on its next request';

    public function handle(): int
    {
        $id = (string) $this->argument('id');

        $key = filter_var($id, FILTER_VALIDATE_INT) === false ? null : ApiKey::query()->find((int) $id);

        if ($key === null) {
            $this->error("API key {$id} not found.");

            return self::FAILURE;
        }

        if (! $key->active) {
            $this->warn("API key #{$key->id} ({$key->name}) is already inactive.");

            return self::SUCCESS;
        }

        $key->forceFill(['active' => false])->save();

        $this->info("API key #{$key->id} ({$key->name}) revoked.");

        return self::SUCCESS;
    }
}
