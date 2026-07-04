<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_access — sender/recipient/client access (black/white) rule (contract:
 * api/components/schemas/MailAccess.yaml; legacy:
 * source_code/interface/web/mail/form/mail_blacklist.tform.php +
 * mail_whitelist.tform.php — one table serves both legacy forms).
 *
 * Rspamd side effect (server-side, no interface work): mail_access datalog
 * events become *global* wblist files global_wblist_<access_id>.conf —
 * access='OK' whitelists, anything else blacklists; sender maps to `from`,
 * recipient to `rcpt`, client to ip/hostname
 * (source_code/server/plugins-available/rspamd_plugin.inc.php:115-122, 388-398).
 */
class MailAccess extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'mail_access';

    /**
     * @var string
     */
    protected $primaryKey = 'access_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'source',
        'access',
        'type',
        'active',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'access_id',
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
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * Contract defaults: access 'REJECT' (legacy blacklist form default),
     * type 'recipient', active y.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'access' => 'REJECT',
        'type' => 'recipient',
        'active' => 'y',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
