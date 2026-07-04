<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * spamfilter_wblist — per-recipient white/blacklist entry (contract:
 * api/components/schemas/SpamfilterWBList.yaml; legacy:
 * source_code/interface/web/mail/form/spamfilter_blacklist.tform.php +
 * spamfilter_whitelist.tform.php — one table serves both legacy forms).
 *
 * `wb` is stored UPPERCASE W/B and exposed as a string. Rspamd resolves
 * `rid` against spamfilter_users and SKIPS entries whose rid does not
 * resolve — rid=0 "global" entries are accepted but Rspamd-inert (C-10);
 * global rules belong in /mail/access-rules.
 */
class SpamfilterWBList extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'spamfilter_wblist';

    /**
     * @var string
     */
    protected $primaryKey = 'wblist_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'wb',
        'rid',
        'email',
        'priority',
        'active',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'wblist_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'active' => YesNoBoolean::class,
        'server_id' => 'integer',
        'rid' => 'integer',
        'priority' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Contract defaults: wb 'B' (blacklist), rid 0, priority 5 (legacy form
     * select default), active y.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'wb' => 'B',
        'rid' => 0,
        'priority' => 5,
        'active' => 'y',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
