<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * sys_remoteaction — ISPConfig's remote action queue, read by every server's
 * modules.inc.php::processActions() (contract: WebBackupJob.yaml).
 *
 * Documented Principle II exception (specs/018-backups/plan.md Complexity
 * Tracking): legacy plugin_backuplist.inc.php inserts these rows with plain
 * SQL and servers execute actions only from this table, so they are NOT
 * journaled through sys_datalog. This model therefore extends Eloquent's
 * Model instead of BaseModel (whose save() always writes a datalog entry).
 * Rows are inserted only by App\Services\RemoteActionService.
 */
class RemoteAction extends Model
{
    /**
     * @var string
     */
    protected $table = 'sys_remoteaction';

    /**
     * @var string
     */
    protected $primaryKey = 'action_id';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'action_id' => 'integer',
        'server_id' => 'integer',
        'tstamp' => 'integer',
    ];
}
