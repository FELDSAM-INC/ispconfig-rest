<?php

namespace App\Models;

class SysGroup extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sys_group';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'groupid';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'client_id',
        // System fields common to ISPConfig tables are handled by BaseModel
        // but specific ones for sys_group might be listed if needed for direct manipulation.
    ];

    /**
     * Get the client associated with this system group.
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }
}
