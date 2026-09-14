<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class CreateApiKey extends Command
{
    protected $signature = 'api:key:create {name : Label identifying the key holder}
                            {--sys-userid=1 : ISPConfig sys_userid the key acts as}
                            {--sys-groupid=1 : ISPConfig sys_groupid the key acts as}
                            {--client-id= : Bind the key to this client\'s control-panel identity (resolves sys_userid/sys_groupid; mutually exclusive with --sys-userid/--sys-groupid)}';

    protected $description = 'Mint a new API key (the plaintext is shown once and stored hashed)';

    public function handle(ApiKeyService $service): int
    {
        $sysUserId = (int) $this->option('sys-userid');
        $sysGroupId = (int) $this->option('sys-groupid');

        $clientIdOption = $this->option('client-id');

        if ($clientIdOption !== null && $clientIdOption !== '') {
            // Mutually exclusive with an explicit identity (spec 011 FR-019).
            if ($sysUserId !== 1 || $sysGroupId !== 1) {
                $this->error('--client-id cannot be combined with --sys-userid/--sys-groupid.');

                return self::FAILURE;
            }

            $identity = $this->resolveClientIdentity($service, (int) $clientIdOption);

            if ($identity === null) {
                return self::FAILURE;
            }

            [$sysUserId, $sysGroupId] = $identity;
        }

        [$key, $plaintext] = ApiKey::mint(
            $this->argument('name'),
            $sysUserId,
            $sysGroupId,
        );

        $this->info("API key #{$key->id} ({$key->name}) created (sys_userid {$key->sys_userid}, sys_groupid {$key->sys_groupid}).");
        $this->newLine();
        $this->line('  <options=bold>'.$plaintext.'</>');
        $this->newLine();
        $this->warn('Store it now — the plaintext cannot be retrieved again.');

        return self::SUCCESS;
    }

    /**
     * Resolve a client's control-panel identity (spec 011 FR-019) through
     * ApiKeyService, reporting which lookup step failed.
     *
     * @return array{0: int, 1: int}|null [sys_userid, sys_groupid]
     */
    protected function resolveClientIdentity(ApiKeyService $service, int $clientId): ?array
    {
        if ($clientId < 1) {
            $this->error('--client-id must be a positive integer.');

            return null;
        }

        $groupId = $service->resolveClientGroupId($clientId);

        if ($groupId === null) {
            $this->error("Client {$clientId} not found (no sys_group with client_id = {$clientId}).");

            return null;
        }

        $userId = $service->resolveGroupUserId($groupId);

        if ($userId === null) {
            $this->error("Client {$clientId} has no control-panel user (no sys_user with default_group = {$groupId}).");

            return null;
        }

        return [$userId, $groupId];
    }
}
