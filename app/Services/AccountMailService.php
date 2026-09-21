<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mail side of an account for its own key (spec 025; contracts
 * api/modules/me/capabilities.yaml `mail`, api/modules/me/mail-settings.yaml).
 *
 * Reads the installation's mailbox rules (sys_ini [mail] and [misc]) and the
 * account's mail servers. The tab switches are also the gates the mailbox
 * sub-resources apply to client and reseller keys, so the capabilities
 * describe rules the API enforces.
 */
class AccountMailService
{
    /** Autoresponder tab switch (mail_user.tform.php:356). */
    public const AUTORESPONDER_TAB = 'mailbox_show_autoresponder_tab';

    /** Mail filter tab switch (mail_user.tform.php:427). */
    public const MAIL_FILTER_TAB = 'mailbox_show_mail_filter_tab';

    public const TAB_MESSAGES = [
        self::AUTORESPONDER_TAB => 'Autoresponders are not enabled on this installation.',
        self::MAIL_FILTER_TAB => 'Mail filters are not enabled on this installation.',
    ];

    /** auth::get_min_password_length() without a system setting (auth.inc.php). */
    public const DEFAULT_MIN_PASSWORD_LENGTH = 8;

    /** Connections of an ISPConfig mail server (research R7): implicit TLS for mailbox access, submission first. */
    public const IMAP = ['port' => 993, 'security' => 'ssl'];

    public const POP3 = ['port' => 995, 'security' => 'ssl'];

    public const SMTP = [['port' => 587, 'security' => 'starttls'], ['port' => 465, 'security' => 'ssl']];

    public function __construct(
        protected SystemConfigService $system,
        protected ServerIniConfigService $serverConfig,
        protected ServerAssignmentService $servers,
    ) {}

    /**
     * The `mail` block of the account capabilities (FR-001…FR-005).
     *
     * @return array<string, mixed>
     */
    public function capabilities(int $clientId): array
    {
        $config = $this->system->rawConfig();
        $mail = $config['mail'] ?? [];

        return [
            'autoresponder' => $this->tabOn($mail, self::AUTORESPONDER_TAB),
            'mail_filters' => $this->tabOn($mail, self::MAIL_FILTER_TAB),
            // Custom rules are an administrator-only tab (mail_user.tform.php:474).
            'custom_rules' => false,
            'recipient_wblist' => true,
            'spamfilter_wblist' => (int) (DB::table('client')->where('client_id', $clientId)->value('limit_spamfilter_wblist') ?? -1) !== 0,
            'fetchmail' => true,
            'spamfilter_policy' => $this->policyReadable($clientId),
            'dkim' => $this->dkimAvailable($clientId),
            'custom_login' => ($mail['enable_custom_login'] ?? 'n') === 'y',
            'password_policy' => $this->policyFrom($config),
        ];
    }

    /**
     * Email program settings of an account (FR-006…FR-008).
     *
     * @return array<string, mixed>
     */
    public function settings(int $clientId): array
    {
        $config = $this->system->rawConfig();
        $mail = $config['mail'] ?? [];
        $ids = $this->accountMailServers($clientId);
        $names = $ids === [] ? [] : DB::table('server')->whereIn('server_id', $ids)->pluck('server_name', 'server_id')->all();

        $servers = array_map(function (int $id) use ($names, $mail): array {
            $host = (string) ($names[$id] ?? '');

            return [
                'server_id' => $id,
                'host' => $host,
                'webmail_url' => $this->webmailUrl($id, $host, (string) ($mail['webmail_url'] ?? '')),
                'imap' => self::IMAP,
                'pop3' => self::POP3,
                'smtp' => self::SMTP,
            ];
        }, $ids);

        return [
            'client_id' => $clientId,
            'custom_login' => ($mail['enable_custom_login'] ?? 'n') === 'y',
            'webmail_link' => ($mail['mailboxlist_webmail_link'] ?? 'n') === 'y',
            'password_policy' => $this->policyFrom($config),
            'servers' => $servers,
        ];
    }

    /**
     * Whether a key may write the fields of a mailbox tab (FR-011, FR-012):
     * admin keys always, others when the installation shows the tab.
     */
    public function tabAllowed(AuthScope $scope, string $setting): bool
    {
        return $scope->isAdmin || $this->tabOn($this->system->rawSection('mail'), $setting);
    }

    /**
     * The installation's mailbox password policy (FR-005, research R5).
     *
     * @return array{min_length: int, min_strength: int, ascii_only: bool}
     */
    public function passwordPolicy(): array
    {
        return $this->policyFrom($this->system->rawConfig());
    }

    /**
     * Mail servers of a client (FR-008, research R6): the valid assigned mail
     * servers in `mail_servers` order, then the non-mirror mail servers
     * hosting the client's mail domains, by id.
     *
     * @return array<int, int>
     */
    public function accountMailServers(int $clientId): array
    {
        // Lookup scope for the assignment columns of the client row.
        $assigned = $this->servers->assignedServerIds(new AuthScope(0, 0, [], false, $clientId), 'mail');

        $hosting = ! Schema::hasTable('mail_domain') ? [] : DB::table('mail_domain')
            ->join('sys_group', 'sys_group.groupid', '=', 'mail_domain.sys_groupid')
            ->join('server', 'server.server_id', '=', 'mail_domain.server_id')
            ->where('sys_group.client_id', $clientId)
            ->where('server.mail_server', 1)
            ->where('server.mirror_server_id', 0)
            ->distinct()
            ->orderBy('mail_domain.server_id')
            ->pluck('mail_domain.server_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($assigned, $hosting)));
    }

    /**
     * Whether a mail server can store DKIM keys (research R4, legacy
     * mail_plugin_dkim::check_system()).
     */
    public function dkimPathUsable(int $serverId): bool
    {
        $path = trim((string) ($this->serverConfig->getSection($serverId, 'mail')['dkim_path'] ?? ''));

        return $path !== '' && $path !== '/';
    }

    /**
     * Webmail address of a mail server (FR-007, legacy webmailer.php).
     */
    public function webmailUrl(int $serverId, string $host, string $setting): string
    {
        if ($setting !== '') {
            return str_replace('[SERVERNAME]', $host, $setting);
        }

        $nginx = ($this->serverConfig->getSection($serverId, 'web')['server_type'] ?? '') === 'nginx';

        return 'https://'.$host.($nginx ? ':8081' : '').'/webmail';
    }

    /**
     * A tab switch: shown when `y`; a missing switch counts as shown (the
     * form default /system/config presents, research R2).
     *
     * @param  array<string, string>  $mail
     */
    protected function tabOn(array $mail, string $setting): bool
    {
        return ($mail[$setting] ?? 'y') === 'y';
    }

    /**
     * @param  array<string, array<string, string>>  $config
     * @return array{min_length: int, min_strength: int, ascii_only: bool}
     */
    protected function policyFrom(array $config): array
    {
        $misc = $config['misc'] ?? [];

        return [
            'min_length' => array_key_exists('min_password_length', $misc)
                ? max(0, (int) $misc['min_password_length'])
                : self::DEFAULT_MIN_PASSWORD_LENGTH,
            'min_strength' => min(5, max(0, (int) ($misc['min_password_strength'] ?? 0))),
            'ascii_only' => ($config['mail']['mail_password_onlyascii'] ?? 'n') === 'y',
        ];
    }

    /**
     * At least one spam filter policy is readable with the client's own
     * control-panel scope (research R3; without an identity only the world
     * clause applies).
     */
    protected function policyReadable(int $clientId): bool
    {
        if (! Schema::hasTable('spamfilter_policy')) {
            return false;
        }

        $scope = AuthScope::forClient($clientId) ?? new AuthScope(0, 0, [], false, $clientId);

        return $scope->applyReadPredicate(DB::table('spamfilter_policy'), 'r')->exists();
    }

    protected function dkimAvailable(int $clientId): bool
    {
        foreach ($this->accountMailServers($clientId) as $serverId) {
            if ($this->dkimPathUsable($serverId)) {
                return true;
            }
        }

        return false;
    }
}
