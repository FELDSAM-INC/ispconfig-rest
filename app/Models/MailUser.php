<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_user — a mailbox account (contract: api/components/schemas/MailUser.yaml
 * + the MailUserAutoresponder/CC/Password/SpamFilter sub-resource views;
 * legacy: source_code/interface/web/mail/form/mail_user.tform.php and
 * mail_user_edit.php).
 *
 * Derived columns (server_id, maildir, homedir, uid, gid, maildir_format,
 * sys_groupid) are composed by MailUserService from the mail domain and the
 * server mail config — never mass assigned. The password is stored as a
 * CRYPTMAIL SHA-512 crypt hash (LegacyCrypt::hashMail) and never serialized.
 */
class MailUser extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mail_user';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'mailuser_id';

    /**
     * Writable fields per the contract (main resource + nested sub-resource
     * views). Derived/system fields are set explicitly by MailUserService.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'login',
        'password',
        'name',
        'quota',
        'cc',
        'forward_in_lda',
        'sender_cc',
        'postfix',
        'greylisting',
        // /mail/users/{id}/autoresponder view
        'autoresponder',
        'autoresponder_start_date',
        'autoresponder_end_date',
        'autoresponder_subject',
        'autoresponder_text',
        // /mail/users/{id}/spamfilter view
        'move_junk',
        'purge_trash_days',
        'purge_junk_days',
        'custom_mailfilter',
    ];

    /**
     * Only the columns the MailUser contract exposes are serialized; the
     * dovecot disable* flags, backup settings, usage counters and the
     * sub-resource columns stay out of the main resource shape. The password
     * hash is never visible anywhere.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'server_id',
        'email',
        'login',
        'name',
        'uid',
        'gid',
        'maildir',
        'maildir_format',
        'homedir',
        'quota',
        'cc',
        'forward_in_lda',
        'sender_cc',
        'postfix',
        'greylisting',
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * mail_user y/n enums are lowercase (ispconfig3.sql); move_junk is a
     * three-state y/a/n flag and therefore stays a plain string.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'forward_in_lda' => YesNoBoolean::class,
        'postfix' => YesNoBoolean::class,
        'greylisting' => YesNoBoolean::class,
        'autoresponder' => YesNoBoolean::class,
        'quota' => 'integer',
        'uid' => 'integer',
        'gid' => 'integer',
        'purge_trash_days' => 'integer',
        'purge_junk_days' => 'integer',
        'server_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Raw column defaults mirroring the legacy tform field defaults
     * (mail_user.tform.php) — DB-native values, bypassing the casts.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quota' => 0,
        'cc' => '',
        'forward_in_lda' => 'n',
        'sender_cc' => '',
        'postfix' => 'y',
        'greylisting' => 'n',
        'autoresponder' => 'n',
        'autoresponder_subject' => 'Out of office reply',
        'move_junk' => 'y',
        'purge_trash_days' => 0,
        'purge_junk_days' => 0,
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
