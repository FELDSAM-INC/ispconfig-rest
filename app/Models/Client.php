<?php

namespace App\Models;

class Client extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'client_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_name',
        'contact_name',
        'contact_firstname',
        'customer_no',
        'username',
        'password',
        'language',
        'usertheme',
        'street',
        'zip',
        'city',
        'state',
        'country',
        'telephone',
        'mobile',
        'fax',
        'email',
        'internet',
        'icq',
        'notes',
        'bank_account_owner',
        'bank_account_number',
        'bank_code',
        'bank_name',
        'bank_account_iban',
        'bank_account_swift',
        'default_mailserver',
        'default_webserver',
        'default_dnsserver',
        'default_slave_dnsserver',
        'default_dbserver',
        'template_master',
        'template_additional',
        'created_at',
        'web_php_options',
        'ssh_chroot',
        'web_limits_disable',
        'reseller_id',
        'locked',
        'canceled',
        'can_use_api',
        // System fields
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
        'server_id',
        'active'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password'
    ];

    /**
     * Get the templates assigned to this client
     */
    public function templates()
    {
        return $this->hasMany(ClientTemplateAssigned::class, 'client_id');
    }

    /**
     * Get the domains for this client
     */
    public function domains()
    {
        return $this->hasMany(ClientDomain::class, 'client_id');
    }
}
