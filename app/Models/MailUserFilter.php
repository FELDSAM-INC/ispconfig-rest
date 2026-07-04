<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_user_filter — a server-side mail sorting rule of a mailbox
 * (contract: api/components/schemas/MailUserFilter.yaml; legacy:
 * source_code/interface/web/mail/form/mail_user_filter.tform.php).
 *
 * Stored values are the legacy stored values (source Subject/From/To/
 * List-Id/Header/Size, op contains/is/begins/ends/regex/localpart/domain,
 * action move/delete/keep/reject), NOT UI language keys — the server-side
 * sieve generator depends on them. Every write is followed by a
 * custom_mailfilter regeneration on the owning mail_user
 * (MailUserFilterService).
 */
class MailUserFilter extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'mail_user_filter';

    /**
     * @var string
     */
    protected $primaryKey = 'filter_id';

    /**
     * mailuser_id comes from the URL path (never from the body) and is set
     * explicitly by the controller.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'rulename',
        'source',
        'op',
        'searchterm',
        'action',
        'target',
        'active',
    ];

    /**
     * The MailUserFilter contract exposes no sys_* fields.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'mailuser_id',
        'rulename',
        'source',
        'op',
        'searchterm',
        'action',
        'target',
        'active',
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
        'mailuser_id' => 'integer',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'target' => '',
        'active' => 'y',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
