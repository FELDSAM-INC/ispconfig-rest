<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_content_filter — Postfix header/body_checks rule (contract:
 * api/components/schemas/MailContentFilter.yaml; legacy:
 * source_code/interface/web/mail/form/mail_content_filter.tform.php).
 */
class MailContentFilter extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'mail_content_filter';

    /**
     * @var string
     */
    protected $primaryKey = 'content_filter_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'type',
        'pattern',
        'data',
        'action',
        'active',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'content_filter_id',
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
     * @var array<string, mixed>
     */
    protected $attributes = [
        'data' => '',
        'active' => 'y',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
