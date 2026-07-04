<?php

namespace App\Models;

class SysUser extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sys_user';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'userid';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'username',
        'passwort',
        'modules',
        'startmodule',
        'app_theme',
        'typ',
        'active',
        'language',
        'groups',
        'default_group',
        'client_id',
    ];

    /**
     * Get the default group associated with this user.
     */
    public function defaultGroup()
    {
        return $this->belongsTo(SysGroup::class, 'default_group', 'groupid');
    }

    /**
     * Get the client associated with this user.
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }
}
