<?php

namespace App\Models;

class ClientTemplateAssigned extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client_template_assigned';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'assigned_template_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'client_id',
        'client_template_id',
        // System fields
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
        'active'
    ];

    /**
     * Get the client that owns the template assignment
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
