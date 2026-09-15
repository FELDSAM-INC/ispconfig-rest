<?php

namespace App\Models;

use LogicException;

/**
 * web_backup — a stored backup of a website's files or of one of its
 * databases (contract: api/components/schemas/WebBackup.yaml; legacy:
 * interface/lib/classes/plugin_backuplist.inc.php).
 *
 * Read-only: the servers create and remove these rows (backup.inc.php);
 * the API never saves or deletes them — deletion is a `backup_delete`
 * remote action (RemoteActionService). The table has no sys_* columns;
 * visibility comes from the parent website's route binding (spec 011).
 * Representations are built by WebBackupService (derived fields, R7).
 */
class WebBackup extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'web_backup';

    /**
     * @var string
     */
    protected $primaryKey = 'backup_id';

    protected bool $hasSysFields = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'backup_id' => 'integer',
        'server_id' => 'integer',
        'parent_domain_id' => 'integer',
        'tstamp' => 'integer',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'backup_password',
    ];

    public function save(array $options = [])
    {
        throw new LogicException('web_backup rows are owned by the ISPConfig servers and are never written by the API.');
    }

    public function delete()
    {
        throw new LogicException('web_backup rows are removed by the ISPConfig servers (backup_delete remote action).');
    }
}
