<?php

namespace App\Services;

use App\Models\MailUser;
use App\Models\SpamfilterUser;
use App\Support\IspContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Mailbox composition and lifecycle side effects, mirroring legacy
 * source_code/interface/web/mail/mail_user_edit.php (onSubmit +
 * onAfterInsert/onAfterUpdate):
 *
 *  - server_id is copied from the mail_domain row of the email's domain part;
 *  - maildir is the server mail config maildir_path with [domain]/[localpart]
 *    substituted; homedir is homedir_path; uid/gid come from
 *    mailuser_uid/mailuser_gid, or both -1 when mailbox_virtual_uidgid_maps=y;
 *  - maildir_format is taken from the server config on insert and restored
 *    (never overwritten) on update;
 *  - login defaults to the email;
 *  - sys_groupid is forced to the mail domain's sys_groupid;
 *  - a companion spamfilter_users row is upserted via datalog on every
 *    insert/update (priority 7, local 'Y', fullname = IDN-decoded email,
 *    policy_id preserved when the row already exists).
 */
class MailUserService
{
    /**
     * Legacy server mail config defaults (server.conf master file), applied
     * when the server's config blob does not carry the key.
     *
     * @var array<string, string>
     */
    protected const MAIL_CONFIG_DEFAULTS = [
        'maildir_path' => '/var/vmail/[domain]/[localpart]',
        'homedir_path' => '/var/vmail',
        'mailuser_uid' => '5000',
        'mailuser_gid' => '5000',
        'maildir_format' => 'maildir',
        'mailbox_virtual_uidgid_maps' => 'n',
    ];

    public function __construct(
        protected ServerIniConfigService $serverConfig,
        protected IspContext $context,
    ) {}

    /**
     * The mail_domain row for an email's domain part; 400 when the domain is
     * not a mail domain (legacy: "no_domain_perm" / FR-008).
     *
     * @return object{domain_id: mixed, domain: string, server_id: mixed, sys_groupid: mixed}
     */
    public function resolveMailDomain(string $email): object
    {
        $domainPart = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        $domain = DB::table('mail_domain')->where('domain', $domainPart)->first();

        if ($domain === null) {
            throw new BadRequestHttpException("The domain '{$domainPart}' is not an existing mail domain.");
        }

        return $domain;
    }

    /**
     * The [mail] section of a server's config with legacy defaults applied.
     *
     * @return array<string, string>
     */
    public function mailConfig(int $serverId): array
    {
        return array_merge(self::MAIL_CONFIG_DEFAULTS, $this->serverConfig->getSection($serverId, 'mail'));
    }

    /**
     * Derive every composed column for a NEW mailbox
     * (mail_user_edit.php:248-302 + onAfterInsert sys_groupid forcing).
     */
    public function applyCreateDerivations(MailUser $user, object $domain): void
    {
        $email = (string) $user->getAttributes()['email'];
        $localPart = strtolower((string) strstr($email, '@', true));

        $config = $this->mailConfig((int) $domain->server_id);

        $maildir = str_replace('[domain]', (string) $domain->domain, $config['maildir_path']);
        $maildir = str_replace('[localpart]', $localPart, $maildir);

        $user->setAttribute('server_id', (int) $domain->server_id);
        $user->setAttribute('maildir', $maildir);
        $user->setAttribute('homedir', $config['homedir_path']);
        $user->setAttribute('maildir_format', $config['maildir_format']);

        if (($config['mailbox_virtual_uidgid_maps'] ?? 'n') === 'y') {
            $user->setAttribute('uid', -1);
            $user->setAttribute('gid', -1);
        } else {
            $user->setAttribute('uid', (int) $config['mailuser_uid']);
            $user->setAttribute('gid', (int) $config['mailuser_gid']);
        }

        if (blank($user->getAttributes()['login'] ?? null)) {
            $user->setAttribute('login', $email);
        }

        // Legacy onAfterInsert: the domain owner is the mailbox owner.
        $user->setAttribute('sys_groupid', (int) $domain->sys_groupid);
    }

    /**
     * Update-time derivations: sys_groupid is re-forced from the domain,
     * maildir_format is preserved (both legacy parity; email is immutable so
     * the maildir itself never changes through the API).
     */
    public function applyUpdateDerivations(MailUser $user, object $domain): void
    {
        $user->setAttribute('sys_groupid', (int) $domain->sys_groupid);
    }

    /**
     * Upsert the companion spamfilter_users row (mail_user_edit.php
     * onAfterInsert/onAfterUpdate): existing rows keep their policy_id (the
     * API exposes no policy field), missing rows are datalog-inserted with
     * priority 7, local 'Y' and the IDN-decoded email as fullname.
     */
    public function syncSpamfilterUser(MailUser $user, object $domain): void
    {
        $email = (string) $user->getAttributes()['email'];

        $existing = SpamfilterUser::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Legacy only datalogs when the policy changes; the API cannot
            // change it, so the existing mapping is left untouched.
            return;
        }

        $spamfilterUser = new SpamfilterUser([
            'server_id' => (int) $domain->server_id,
            'priority' => 7,
            'policy_id' => 0,
            'email' => $email,
            'fullname' => $this->idnDecode($email),
            'local' => 'Y',
        ]);

        $spamfilterUser->setAttribute('sys_userid', $this->context->sysUserId());
        $spamfilterUser->setAttribute('sys_groupid', (int) $domain->sys_groupid);

        $spamfilterUser->save(); // datalog 'i'
    }

    /**
     * Legacy functions.inc.php::idn_decode for email addresses (fullname is
     * stored IDN-decoded).
     */
    protected function idnDecode(string $email): string
    {
        if (! str_contains($email, '@') || ! function_exists('idn_to_utf8')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $decoded = idn_to_utf8($domain);

        return $local.'@'.($decoded === false ? $domain : $decoded);
    }
}
