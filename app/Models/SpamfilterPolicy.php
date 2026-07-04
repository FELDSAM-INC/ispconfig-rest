<?php

namespace App\Models;

/**
 * spamfilter_policy — Amavis/Rspamd policy (contract:
 * api/components/schemas/SpamfilterPolicy.yaml; legacy:
 * source_code/interface/web/mail/form/spamfilter_policy.tform.php).
 *
 * The API exposes a subset of the ~50 policy columns; all unexposed columns
 * (tag/kill levels, address extensions, admin addresses, rspamd_* levels...)
 * keep their database defaults. Y/N flags are stored UPPERCASE and exposed
 * as strings, never booleans (exact casing is a server-plugin contract).
 * The table has no server_id — policies are global. Record permissions
 * default to sys_perm_other='r' (policies are readable by all panel users),
 * unlike every other mail resource.
 */
class SpamfilterPolicy extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'spamfilter_policy';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'policy_name',
        'virus_lover',
        'spam_lover',
        'banned_files_lover',
        'bad_header_lover',
        'bypass_virus_checks',
        'bypass_spam_checks',
        'bypass_banned_checks',
        'bypass_header_checks',
        'virus_quarantine_to',
        'spam_quarantine_to',
        'banned_quarantine_to',
        'bad_header_quarantine_to',
        'clean_quarantine_to',
    ];

    /**
     * Only the contract-exposed subset is serialized; the remaining legacy
     * policy columns stay at their DB defaults and out of responses.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'policy_name',
        'virus_lover',
        'spam_lover',
        'banned_files_lover',
        'bad_header_lover',
        'bypass_virus_checks',
        'bypass_spam_checks',
        'bypass_banned_checks',
        'bypass_header_checks',
        'virus_quarantine_to',
        'spam_quarantine_to',
        'banned_quarantine_to',
        'bad_header_quarantine_to',
        'clean_quarantine_to',
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Contract defaults for the exposed Y/N flags plus the resource-specific
     * sys_perm_other='r' (spamfilter_policy.tform.php auth_preset;
     * BaseModel only fills sys fields that are not already set).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'virus_lover' => 'N',
        'spam_lover' => 'N',
        'banned_files_lover' => 'N',
        'bad_header_lover' => 'N',
        'bypass_virus_checks' => 'N',
        'bypass_spam_checks' => 'N',
        'bypass_banned_checks' => 'N',
        'bypass_header_checks' => 'N',
        'sys_perm_other' => 'r',
    ];
}
