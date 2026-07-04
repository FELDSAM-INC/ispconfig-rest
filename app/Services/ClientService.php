<?php

namespace App\Services;

use App\Models\Client;
use App\Support\IspContext;
use App\Support\LegacyCrypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Client/reseller lifecycle side effects, mirroring legacy
 * source_code/interface/web/client/client_edit.php, reseller_edit.php and
 * client_del.php:
 *
 *  - create: CRYPT-hashed password, sys_group creation (datalogged, exactly
 *    like legacy datalogInsert), sys_user control-panel login creation
 *    (plain INSERT — legacy does not datalog sys_user either; documented
 *    Principle II exception), reseller ownership resolution, default server
 *    lists, template application;
 *  - update: sys_user username/password/language/modules sync, sys_group
 *    rename (datalogged like legacy), re-parenting between resellers;
 *  - delete: legacy client_del.php cascade — remove the client group from
 *    the parent reseller's user, drop sys_group/sys_user (plain DELETEs,
 *    as legacy), datalog-delete every record owned by the client's group,
 *    then the client row itself.
 *
 * Legacy behaviors intentionally NOT ported (out of the API contract's
 * scope): welcome e-mails, customer_no counter templates, ssh key
 * generation, lock/cancel record snapshots (func_client_lock/cancel).
 */
class ClientService
{
    /**
     * Legacy client_del.php table list (table => primary key column):
     * every record with the client group's sys_groupid is datalog-deleted.
     * Primary keys verified against ispconfig3.sql.
     *
     * @var array<string, string>
     */
    protected const CASCADE_TABLES = [
        'cron' => 'id',
        'client' => 'client_id',
        'dns_rr' => 'id',
        'dns_soa' => 'id',
        'dns_slave' => 'id',
        'domain' => 'domain_id',
        'ftp_user' => 'ftp_user_id',
        'mail_access' => 'access_id',
        'mail_content_filter' => 'content_filter_id',
        'mail_forwarding' => 'forwarding_id',
        'mail_get' => 'mailget_id',
        'mail_mailinglist' => 'mailinglist_id',
        'mail_user' => 'mailuser_id',
        'mail_user_filter' => 'filter_id',
        'mail_domain' => 'domain_id',
        'shell_user' => 'shell_user_id',
        'spamfilter_users' => 'id',
        'spamfilter_wblist' => 'wblist_id',
        'support_message' => 'support_message_id',
        'web_domain' => 'domain_id',
        'web_folder' => 'web_folder_id',
        'web_folder_user' => 'web_folder_user_id',
        'web_database_user' => 'database_user_id',
        'web_database' => 'database_id',
    ];

    public function __construct(
        protected DatalogService $datalog,
        protected ClientTemplateService $templates,
        protected IspContext $context,
    ) {}

    /**
     * Create a client (or reseller) with the legacy side effects.
     *
     * @param  array<string, mixed>  $payload  validated request payload
     * @param  bool  $asReseller  reseller flavor (reseller_edit.php parity:
     *                            sys_user modules always include 'client')
     */
    public function createClient(Client $client, array $payload, bool $asReseller = false): Client
    {
        $plainPassword = (string) $payload['password'];
        $payload['password'] = LegacyCrypt::hash($plainPassword);
        unset($plainPassword);

        $client->fill($payload);

        // Reseller ownership: sys_userid/sys_groupid from the parent
        // reseller's sys_user (client_edit.php onAfterInsert + spec FR-003).
        $parentClientId = (int) ($payload['parent_client_id'] ?? 0);
        $resellerUser = null;

        if ($parentClientId > 0) {
            $resellerUser = $this->resolveResellerUser($parentClientId);
            $client->setAttribute('sys_userid', (int) $resellerUser->userid);
            $client->setAttribute('sys_groupid', (int) $resellerUser->default_group);
        }

        $this->applyDefaultServers($client, $payload, $asReseller);
        $this->applyCreationDefaults($client, $payload);

        $client->save(); // datalog 'i' with the CRYPT hash, never plaintext

        $clientId = (int) $client->getKey();

        // Create the group for the client — datalogged, exactly like legacy
        // ($app->db->datalogInsert('sys_group', ...)).
        $groupId = $this->datalog->insertRecord('sys_group', 'groupid', [
            'name' => (string) $payload['username'],
            'description' => '',
            'client_id' => $clientId,
        ]);

        $this->createSysUser($client, $groupId, $asReseller);

        // Give the parent reseller's control-panel user access to the new
        // client's group (legacy add_group_to_user; plain UPDATE, no datalog).
        if ($resellerUser !== null) {
            $this->addGroupToUser((int) $resellerUser->userid, $groupId);
        }

        // Apply master + additional templates (legacy clients_template_plugin
        // runs on every insert; no-op while template_master is 0).
        $this->templates->applyClientTemplates($clientId);

        return $client->refresh();
    }

    /**
     * Update a client with the legacy sys_user/sys_group sync and
     * re-parenting side effects (client_edit.php::onAfterUpdate).
     *
     * @param  array<string, mixed>  $payload  validated request payload
     */
    public function updateClient(Client $client, array $payload): Client
    {
        $old = $client->getRawOriginal();
        $clientId = (int) $client->getKey();

        if (array_key_exists('password', $payload) && ($payload['password'] === null || $payload['password'] === '')) {
            unset($payload['password']); // legacy skips empty password fields
        }

        $passwordHash = null;

        if (isset($payload['password'])) {
            $passwordHash = LegacyCrypt::hash((string) $payload['password']);
            $payload['password'] = $passwordHash;
        }

        $client->fill($payload);

        // Re-parenting (legacy: admin moves a client between resellers).
        $parentChanged = array_key_exists('parent_client_id', $payload)
            && (int) $payload['parent_client_id'] !== (int) ($old['parent_client_id'] ?? 0);

        if ($parentChanged) {
            $this->reparent($client, (int) ($old['parent_client_id'] ?? 0), (int) $payload['parent_client_id']);
        }

        $client->save(); // datalog 'u' (suppressed when nothing changed)

        // --- sys_user / sys_group sync (legacy plain queries) ---

        $newUsername = $payload['username'] ?? null;

        if (is_string($newUsername) && $newUsername !== '' && $newUsername !== ($old['username'] ?? null)) {
            DB::table('sys_user')->where('client_id', $clientId)->update(['username' => $newUsername]);

            // Legacy datalogs the group rename (datalogUpdate on sys_group).
            $group = DB::table('sys_group')->where('client_id', $clientId)->first();

            if ($group !== null) {
                $this->datalog->updateRecord('sys_group', 'groupid', $group->groupid, ['name' => $newUsername]);
            }
        }

        if ($passwordHash !== null) {
            DB::table('sys_user')->where('client_id', $clientId)->update(['passwort' => $passwordHash]);
        }

        $newLanguage = $payload['language'] ?? null;

        if (is_string($newLanguage) && $newLanguage !== '' && $newLanguage !== ($old['language'] ?? null)) {
            DB::table('sys_user')->where('client_id', $clientId)->update(['language' => $newLanguage]);
        }

        if (array_key_exists('limit_client', $payload)
            && (int) $payload['limit_client'] !== (int) ($old['limit_client'] ?? 0)) {
            $modules = $this->interfaceModules();

            if ((int) $payload['limit_client'] > 0) {
                $modules .= ',client';
            }

            DB::table('sys_user')->where('client_id', $clientId)->update(['modules' => $modules]);
        }

        // Re-apply templates (legacy clients_template_plugin on_after_update;
        // no-op while template_master is 0).
        $this->templates->applyClientTemplates($clientId);

        return $client->refresh();
    }

    /**
     * Delete a client with the full legacy cascade
     * (client_del.php::onBeforeDelete), everything grouped under one datalog
     * session id:
     *
     *  1. remove the client's group from the parent reseller's user;
     *  2. plain-DELETE the client's sys_group and sys_user rows (legacy does
     *     not datalog these — documented Principle II exception);
     *  3. datalog-delete every record owned by the client's group across the
     *     legacy table list (plus the web_traffic/mail_traffic rows that have
     *     no sys_groupid);
     *  4. datalog-delete the client row itself.
     */
    public function deleteClient(Client $client): void
    {
        $clientId = (int) $client->getKey();
        $record = $client->getRawOriginal();

        $parentClientId = (int) ($record['parent_client_id'] ?? 0);
        $group = DB::table('sys_group')->where('client_id', $clientId)->first();
        $groupId = $group !== null ? (int) $group->groupid : 0;

        // 1. Remove the group from the parent reseller's user (legacy looks
        //    the parent user up by its client_id; no-op when there is none).
        if ($groupId > 0) {
            $parentUser = DB::table('sys_user')->where('client_id', $parentClientId)->first();

            if ($parentUser !== null) {
                $this->removeGroupFromUser((int) $parentUser->userid, $groupId);
            }
        }

        // 2. Drop the client's login artifacts (plain DELETEs, like legacy).
        DB::table('sys_group')->where('client_id', $clientId)->delete();
        DB::table('sys_user')->where('client_id', $clientId)->delete();

        // 3. Datalog-delete all records owned by the client's group.
        if ($groupId > 1) {
            foreach (self::CASCADE_TABLES as $table => $primaryKey) {
                if (! Schema::hasTable($table)) {
                    continue; // installation without this module's table
                }

                $records = DB::table($table)
                    ->where('sys_groupid', $groupId)
                    ->orderByDesc($primaryKey)
                    ->get();

                foreach ($records as $row) {
                    $this->datalog->deleteRecord($table, $primaryKey, $row->{$primaryKey});

                    // Traffic rows have no sys_groupid (legacy plain DELETEs).
                    if ($table === 'web_domain' && Schema::hasTable('web_traffic')) {
                        DB::table('web_traffic')->where('hostname', $row->domain)->delete();
                    }

                    if ($table === 'mail_user' && Schema::hasTable('mail_traffic')) {
                        DB::table('mail_traffic')->where('mailuser_id', $row->mailuser_id)->delete();
                    }
                }
            }
        }

        // 4. The client row itself (datalog 'd').
        $client->delete();
    }

    /**
     * Resolve the parent reseller's control-panel sys_user
     * (client_edit.php: sys_user.default_group = sys_group.groupid AND
     * sys_group.client_id = parent). 400 problem for non-resellers and
     * missing sys users (spec FR-003).
     *
     * @return object{userid: int|string, default_group: int|string}
     */
    protected function resolveResellerUser(int $parentClientId): object
    {
        $parent = DB::table('client')->where('client_id', $parentClientId)->first(['client_id', 'limit_client']);

        if ($parent === null) {
            throw new BadRequestHttpException('Parent client does not exist.');
        }

        $limitClient = (int) $parent->limit_client;

        if ($limitClient !== -1 && $limitClient <= 0) {
            throw new BadRequestHttpException('Parent client is not a reseller (limit_client must be > 0 or -1).');
        }

        $user = DB::table('sys_user')
            ->join('sys_group', 'sys_user.default_group', '=', 'sys_group.groupid')
            ->where('sys_group.client_id', $parentClientId)
            ->first(['sys_user.userid', 'sys_user.default_group']);

        if ($user === null) {
            throw new BadRequestHttpException('Parent reseller system user not found.');
        }

        return $user;
    }

    /**
     * Move the client between resellers / back to the admin
     * (client_edit.php::onAfterUpdate "Client has been moved to another
     * reseller"). Legacy issues separate plain UPDATEs on the client row;
     * here the ownership fields are set on the model before save so the
     * change is part of the datalogged update.
     */
    protected function reparent(Client $client, int $oldParentId, int $newParentId): void
    {
        $group = DB::table('sys_group')->where('client_id', $client->getKey())->first();
        $groupId = $group !== null ? (int) $group->groupid : 0;

        // Remove the client group from the old reseller's user.
        if ($oldParentId > 0 && $groupId > 0) {
            $oldUser = DB::table('sys_user')
                ->join('sys_group', 'sys_user.default_group', '=', 'sys_group.groupid')
                ->where('sys_group.client_id', $oldParentId)
                ->first(['sys_user.userid']);

            if ($oldUser !== null) {
                $this->removeGroupFromUser((int) $oldUser->userid, $groupId);
            }
        }

        if ($newParentId > 0) {
            $resellerUser = $this->resolveResellerUser($newParentId);

            if ($groupId > 0) {
                $this->addGroupToUser((int) $resellerUser->userid, $groupId);
            }

            $client->setAttribute('sys_userid', (int) $resellerUser->userid);
            $client->setAttribute('sys_groupid', (int) $resellerUser->default_group);

            return;
        }

        // Not assigned to a reseller anymore: back to the admin.
        $client->setAttribute('sys_userid', 1);
        $client->setAttribute('sys_groupid', 1);
        $client->setAttribute('parent_client_id', 0);
    }

    /**
     * Default server lists on create when the request did not supply them.
     *
     * Legacy overrides them unconditionally after insert (client_edit.php
     * sets the *_servers lists, reseller_edit.php the default_* ids, both
     * from global config with a server-table fallback); the API applies the
     * server-table fallback only for fields the consumer left out, so
     * explicitly provided values are honored (documented deviation).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function applyDefaultServers(Client $client, array $payload, bool $asReseller): void
    {
        if (! Schema::hasTable('server')) {
            return;
        }

        $firstServer = function (string $flag): int {
            $id = DB::table('server')
                ->where($flag, 1)
                ->where('mirror_server_id', 0)
                ->orderBy('server_id')
                ->value('server_id');

            return (int) $id;
        };

        $mail = $firstServer('mail_server');
        $web = $firstServer('web_server');
        $dns = $firstServer('dns_server');
        $db = $firstServer('db_server');

        if ($asReseller) {
            // reseller_edit.php: default_* server ids.
            $defaults = [
                'default_mailserver' => $mail,
                'default_webserver' => $web,
                'default_dnsserver' => $dns,
                'default_slave_dnsserver' => $dns,
                'default_dbserver' => $db,
            ];
        } else {
            // client_edit.php: available-server lists (single default id).
            $defaults = [
                'mail_servers' => (string) $mail,
                'web_servers' => (string) $web,
                'dns_servers' => (string) $dns,
                'default_slave_dnsserver' => $dns,
                'db_servers' => (string) $db,
            ];
        }

        foreach ($defaults as $field => $value) {
            if (! array_key_exists($field, $payload) && ! in_array($value, [0, '0'], true)) {
                $client->setAttribute($field, $value);
            }
        }
    }

    /**
     * Legacy tform field defaults the DB cannot supply: added_date (today)
     * and added_by (the acting interface user), language 'en'.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function applyCreationDefaults(Client $client, array $payload): void
    {
        if (! array_key_exists('added_date', $payload)) {
            $client->setAttribute('added_date', date('Y-m-d'));
        }

        if (! array_key_exists('added_by', $payload)) {
            $client->setAttribute('added_by', $this->context->username());
        }

        if (blank($client->getAttributes()['language'] ?? null)) {
            $client->setAttribute('language', 'en');
        }
    }

    /**
     * Create the control-panel sys_user for the client (legacy plain INSERT
     * in client_edit.php/reseller_edit.php::onAfterInsert — not datalogged).
     */
    protected function createSysUser(Client $client, int $groupId, bool $asReseller): void
    {
        $attributes = $client->getAttributes();

        $modules = $this->interfaceModules();

        if ($asReseller || (int) ($attributes['limit_client'] ?? 0) > 0) {
            $modules .= ',client';
        }

        DB::table('sys_user')->insert([
            'username' => (string) ($attributes['username'] ?? ''),
            'passwort' => (string) ($attributes['password'] ?? ''),
            'modules' => $modules,
            'startmodule' => stristr($modules, 'dashboard') !== false ? 'dashboard' : 'client',
            'app_theme' => blank($attributes['usertheme'] ?? null) ? 'default' : (string) $attributes['usertheme'],
            'typ' => 'user',
            'active' => 1,
            'language' => (string) ($attributes['language'] ?? 'en'),
            'groups' => (string) $groupId,
            'default_group' => $groupId,
            'client_id' => (int) $client->getKey(),
        ]);
    }

    /**
     * Legacy auth.inc.php::add_group_to_user — append the group id to the
     * user's CSV `groups` column (plain UPDATE).
     */
    protected function addGroupToUser(int $userId, int $groupId): void
    {
        if ($userId < 1 || $groupId < 1) {
            return;
        }

        $user = DB::table('sys_user')->where('userid', $userId)->first(['groups']);

        if ($user === null) {
            return;
        }

        $groups = array_filter(explode(',', (string) $user->groups), fn ($g) => $g !== '');

        if (! in_array((string) $groupId, $groups, true)) {
            $groups[] = (string) $groupId;
        }

        DB::table('sys_user')->where('userid', $userId)->update(['groups' => implode(',', $groups)]);
    }

    /**
     * Legacy auth.inc.php::remove_group_from_user — drop the group id from
     * the user's CSV `groups` column (plain UPDATE).
     */
    protected function removeGroupFromUser(int $userId, int $groupId): void
    {
        if ($userId < 1 || $groupId < 1) {
            return;
        }

        $user = DB::table('sys_user')->where('userid', $userId)->first(['groups']);

        if ($user === null) {
            return;
        }

        $groups = array_filter(
            explode(',', (string) $user->groups),
            fn ($g) => $g !== '' && (int) $g !== $groupId
        );

        DB::table('sys_user')->where('userid', $userId)->update(['groups' => implode(',', $groups)]);
    }

    /**
     * Interface module list written to sys_user.modules — legacy
     * $conf['interface_modules_enabled'] (config.inc.php default).
     */
    protected function interfaceModules(): string
    {
        return (string) config('api.interface_modules', 'dashboard,mail,sites,dns,tools');
    }
}
