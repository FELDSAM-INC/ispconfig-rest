<?php

namespace App\Models;

class ClientDomain extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'domain';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'domain_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'domain',
        // System fields from schema
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other'
    ];

    /**
     * Get the system group that owns the domain.
     */
    public function sysGroup()
    {
        return $this->belongsTo(SysGroup::class, 'sys_groupid', 'groupid'); // Assuming SysGroup model exists or will be created
    }
    
    // To get the client, you would chain through sysGroup: $domain->sysGroup->client
    // This requires SysGroup model to have a client() relationship.
}
