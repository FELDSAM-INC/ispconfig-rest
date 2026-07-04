<?php

namespace App\Models;

/**
 * sys_ini — the global-configuration singleton (row sysini_id = 1). Its
 * `config` column is one INI-formatted text blob holding the [sites], [mail],
 * [dns], [domains] and [misc] sections shown in legacy System → Main Config
 * (contract: api/components/schemas/SystemConfig.yaml; legacy:
 * source_code/interface/web/admin/form/system_config.tform.php).
 *
 * The table carries neither sys_* nor server_id columns; datalog entries
 * therefore resolve to server_id 0, like legacy datalogUpdate('sys_ini', ...).
 * All parsing/merging of the blob lives in SystemConfigService — this model
 * only persists the whole `config` string through the datalog (db_history=yes
 * in the legacy tform).
 */
class SysIni extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sys_ini';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'sysini_id';

    /**
     * sys_ini has no ISPConfig system fields.
     */
    protected bool $hasSysFields = false;

    /**
     * Only the blob is writable; the logo columns are legacy-UI-only and
     * out of the API's scope.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'config',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'default_logo',
        'custom_logo',
    ];
}
