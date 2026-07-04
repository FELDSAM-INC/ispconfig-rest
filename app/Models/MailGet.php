<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mail_get — fetchmail configuration for retrieving remote mail into a local
 * mailbox (contract: api/components/schemas/MailGet.yaml, endpoints
 * /mail/fetchmail; legacy: source_code/interface/web/mail/form/mail_get.tform.php).
 *
 * source_password is stored as legacy stores it (plaintext column) but is
 * write-only: hidden from every response and only re-set when a non-empty
 * value is provided on update.
 */
class MailGet extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'mail_get';

    /**
     * @var string
     */
    protected $primaryKey = 'mailget_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id',
        'type',
        'source_server',
        'source_username',
        'source_password',
        'source_delete',
        'source_read_all',
        'destination',
        'active',
    ];

    /**
     * The MailGet contract exposes no sys_* fields; source_password is
     * write-only.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'server_id',
        'type',
        'source_server',
        'source_username',
        'source_delete',
        'source_read_all',
        'destination',
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
        'source_delete' => YesNoBoolean::class,
        'source_read_all' => YesNoBoolean::class,
        'active' => YesNoBoolean::class,
        'server_id' => 'integer',
    ];

    /**
     * Legacy form defaults (mail_get.tform.php): source_delete n (the DB
     * column default is 'y' but the form default wins on create),
     * source_read_all y, active y.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source_delete' => 'n',
        'source_read_all' => 'y',
        'active' => 'y',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
