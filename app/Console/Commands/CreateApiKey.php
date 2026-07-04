<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class CreateApiKey extends Command
{
    protected $signature = 'api:key:create {name : Label identifying the key holder}
                            {--sys-userid=1 : ISPConfig sys_userid the key acts as}
                            {--sys-groupid=1 : ISPConfig sys_groupid the key acts as}';

    protected $description = 'Mint a new API key (the plaintext is shown once and stored hashed)';

    public function handle(): int
    {
        [$key, $plaintext] = ApiKey::mint(
            $this->argument('name'),
            (int) $this->option('sys-userid'),
            (int) $this->option('sys-groupid'),
        );

        $this->info("API key #{$key->id} ({$key->name}) created.");
        $this->newLine();
        $this->line('  <options=bold>'.$plaintext.'</>');
        $this->newLine();
        $this->warn('Store it now — the plaintext cannot be retrieved again.');

        return self::SUCCESS;
    }
}
