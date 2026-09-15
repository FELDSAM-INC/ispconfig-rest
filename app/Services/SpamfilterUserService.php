<?php

namespace App\Services;

use App\Models\MailDomain;
use App\Models\MailUser;
use App\Support\IspContext;
use Illuminate\Support\Facades\DB;

/**
 * Spam filter level of mailboxes and mail domains (spec 026): the companion
 * spamfilter_users rows legacy mail_user_edit.php (onAfterInsert/Update) and
 * mail_domain_edit.php (onAfterInsert/Update) upsert from the form's policy
 * select. Written with direct datalog calls like legacy — the permission to
 * change the level comes from the mailbox or domain, not from the row.
 */
class SpamfilterUserService
{
    /** Legacy insert priorities: mailbox rows 7, `@domain` rows 5. */
    public const MAILBOX_PRIORITY = 7;

    public const DOMAIN_PRIORITY = 5;

    public function __construct(
        protected DatalogService $datalog,
        protected IspContext $context,
        protected MailUserService $mailUsers,
    ) {}

    /**
     * The level of one recipient key (address or `@domain`), 0 without a row.
     */
    public function policyFor(string $email): int
    {
        return (int) (DB::table('spamfilter_users')->where('email', $email)->orderBy('id')->value('policy_id') ?? 0);
    }

    /**
     * Levels of many recipient keys with one query; keys without a row are absent.
     *
     * @param  array<int, string>  $emails
     * @return array<string, int>
     */
    public function policiesFor(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        // Descending ids so the oldest row of a duplicated key wins, like legacy queryOneRecord.
        return DB::table('spamfilter_users')
            ->whereIn('email', array_values(array_unique($emails)))
            ->orderByDesc('id')
            ->pluck('policy_id', 'email')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Set the level of a mailbox (mail_user_edit.php:336-360, 468-480).
     */
    public function assignMailbox(MailUser $mailbox, int $policyId): void
    {
        $raw = $mailbox->getAttributes();
        $email = (string) $raw['email'];

        // The mail domain supplies server and owner group (legacy onAfterInsert).
        $domainPart = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $domain = DB::table('mail_domain')->where('domain', $domainPart)->first(['server_id', 'sys_groupid']);

        $this->assign($email, $policyId, [
            'server_id' => (int) ($domain->server_id ?? $raw['server_id']),
            'sys_groupid' => (int) ($domain->sys_groupid ?? $raw['sys_groupid']),
            'priority' => self::MAILBOX_PRIORITY,
            'email' => $email,
            'fullname' => $this->mailUsers->idnDecode($email),
        ]);
    }

    /**
     * Set the level of a mail domain (`@domain`, mail_domain_edit.php:364-390, 468-492).
     */
    public function assignDomain(MailDomain $domain, int $policyId): void
    {
        $raw = $domain->getAttributes();
        $email = '@'.$raw['domain'];

        $this->assign($email, $policyId, [
            'server_id' => (int) $raw['server_id'],
            'sys_groupid' => (int) $raw['sys_groupid'],
            'priority' => self::DOMAIN_PRIORITY,
            'email' => $email,
            'fullname' => $email,
        ]);
    }

    /**
     * Update policy_id when it changed, or insert the row with the legacy
     * defaults when there is none.
     *
     * @param  array<string, mixed>  $insert  row-specific insert columns
     */
    protected function assign(string $email, int $policyId, array $insert): void
    {
        $row = DB::table('spamfilter_users')->where('email', $email)->orderBy('id')->first(['id', 'policy_id']);

        if ($row !== null) {
            if ((int) $row->policy_id !== $policyId) {
                $this->datalog->updateRecord('spamfilter_users', 'id', (int) $row->id, ['policy_id' => $policyId]);
            }

            return;
        }

        $this->datalog->insertRecord('spamfilter_users', 'id', $insert + [
            'sys_userid' => $this->context->sysUserId(),
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'policy_id' => $policyId,
            'local' => 'Y',
        ]);
    }
}
