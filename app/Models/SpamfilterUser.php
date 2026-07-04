<?php

namespace App\Models;

/**
 * spamfilter_users — email-to-policy mapping (contract:
 * api/components/schemas/SpamfilterUser.yaml; legacy:
 * source_code/interface/web/mail/form/spamfilter_users.tform.php).
 *
 * `local` is stored UPPERCASE Y/N and exposed as a string. The legacy form
 * default for priority is 5 (the contract default); the DB column default 7
 * is the value used by the automatic mailbox sync (MailUserService sets it
 * explicitly).
 */
class SpamfilterUser extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'spamfilter_users';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'priority',
        'policy_id',
        'email',
        'fullname',
        'local',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'priority' => 'integer',
        'policy_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Contract defaults (legacy form spamfilter_users.tform.php: priority 5,
     * local Y, policy inherit).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 5,
        'policy_id' => 0,
        'local' => 'Y',
    ];
}
