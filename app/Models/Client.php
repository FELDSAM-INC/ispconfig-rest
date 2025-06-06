<?php

namespace App\Models;

class Client extends BaseModel
{
    /**
     * Validation rules for the model
     *
     * @var array
     */
    public static $rules = [
        'company_name' => 'sometimes|string|max:255',
        'contact_name' => 'sometimes|string|max:255',
        'contact_firstname' => 'sometimes|string|max:255',
        'customer_no' => 'sometimes|string|max:255',
        'username' => 'sometimes|string|max:255',
        'password' => 'sometimes|string|min:8',
        'language' => 'sometimes|string|max:2',
        'usertheme' => 'sometimes|string|max:32',
        'street' => 'sometimes|string|max:255',
        'zip' => 'sometimes|string|max:32',
        'city' => 'sometimes|string|max:255',
        'state' => 'sometimes|string|max:255',
        'country' => 'sometimes|string|max:255',
        'telephone' => 'sometimes|string|max:32',
        'mobile' => 'sometimes|string|max:32',
        'fax' => 'sometimes|string|max:32',
        'email' => 'sometimes|email|max:255',
        'internet' => 'sometimes|url|max:255',
        'notes' => 'sometimes|nullable|string',
        'template_master' => 'sometimes|integer|exists:client_template,template_id',
        'template_additional' => 'sometimes|string',
        'parent_client_id' => 'sometimes|integer|nullable',
        'locked' => 'sometimes|in:y,n',
        'canceled' => 'sometimes|in:y,n',
        'can_use_api' => 'sometimes|in:y,n',
    ];
    
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
        'parent_client_id',
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
     * Get the master template assigned to this client
     */
    public function masterTemplate()
    {
        return $this->belongsTo(ClientTemplate::class, 'template_master', 'template_id');
    }
    
    /**
     * Get the additional templates assigned to this client
     */
    public function addonTemplates()
    {
        return $this->belongsToMany(
            ClientTemplate::class,
            'client_template_assigned',
            'client_id',
            'client_template_id',
            'client_id',
            'template_id'
        );
    }

    /**
     * Get the domains for this client
     */
    public function domains()
    {
        return $this->hasMany(ClientDomain::class, 'client_id');
    }
}
