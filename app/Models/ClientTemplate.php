<?php

namespace App\Models;

class ClientTemplate extends BaseModel
{
    /**
     * Validation rules for the model
     *
     * @var array
     */
    public static $rules = [
        'template_name' => 'required|string|max:255',
        'template_type' => 'sometimes|string|in:m,a',
        'description' => 'sometimes|nullable|string',
        'mail_servers' => 'sometimes|string',
        'web_servers' => 'sometimes|string',
        'dns_servers' => 'sometimes|string',
        'db_servers' => 'sometimes|string',
        'limit_maildomain' => 'sometimes|integer',
        'limit_mailbox' => 'sometimes|integer',
        'limit_mailalias' => 'sometimes|integer',
        'limit_mailaliasdomain' => 'sometimes|integer',
        'limit_mailforward' => 'sometimes|integer',
        'limit_mailcatchall' => 'sometimes|integer',
        'limit_mailrouting' => 'sometimes|integer',
        'limit_mailfilter' => 'sometimes|integer',
        'limit_fetchmail' => 'sometimes|integer',
        'limit_mailquota' => 'sometimes|integer',
        'limit_web_domain' => 'sometimes|integer',
        'limit_web_quota' => 'sometimes|integer',
        'limit_web_subdomain' => 'sometimes|integer',
        'limit_web_aliasdomain' => 'sometimes|integer',
        'limit_ftp_user' => 'sometimes|integer',
        'limit_shell_user' => 'sometimes|integer',
        'limit_webdav_user' => 'sometimes|integer',
        'limit_database' => 'sometimes|integer',
        'limit_database_quota' => 'sometimes|integer',
        'limit_dns_zone' => 'sometimes|integer',
        'limit_dns_record' => 'sometimes|integer',
        'limit_client' => 'sometimes|integer',
    ];
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client_template';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'template_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        // System fields
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
        'server_id',
        'active',
        
        // Template fields
        'template_name',
        'template_type',
        'description',
        'mail_servers',
        'web_servers',
        'db_servers',
        'dns_servers',
        'file_servers',
        
        // Limit fields
        'limit_maildomain',
        'limit_mailbox',
        'limit_mailalias',
        'limit_mailaliasdomain',
        'limit_mailforward',
        'limit_mailcatchall',
        'limit_mailrouting',
        'limit_mail_wblist',
        'limit_mailfilter',
        'limit_fetchmail',
        'limit_mailquota',
        'limit_spamfilter_wblist',
        'limit_spamfilter_user',
        'limit_spamfilter_policy',
        'limit_web_domain',
        'limit_web_quota',
        'limit_web_subdomain',
        'limit_web_aliasdomain',
        'limit_ftp_user',
        'limit_shell_user',
        'limit_webdav_user',
        'limit_database',
        'limit_database_quota',
        'limit_dns_zone',
        'limit_dns_record',
        'limit_client',
        'limit_cron',
        'limit_web_ip',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'template_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'server_id' => 'integer',
        'active' => 'string',
        'limit_client' => 'integer',
        'limit_maildomain' => 'integer',
        'limit_mailbox' => 'integer',
        'limit_mailalias' => 'integer',
        'limit_mailaliasdomain' => 'integer',
        'limit_mailforward' => 'integer',
        'limit_mailcatchall' => 'integer',
        'limit_mailrouting' => 'integer',
        'limit_mail_wblist' => 'integer',
        'limit_mailfilter' => 'integer',
        'limit_fetchmail' => 'integer',
        'limit_mailquota' => 'integer',
        'limit_spamfilter_wblist' => 'integer',
        'limit_spamfilter_user' => 'integer',
        'limit_spamfilter_policy' => 'integer',
        'limit_web_domain' => 'integer',
        'limit_web_quota' => 'integer',
        'limit_web_subdomain' => 'integer',
        'limit_web_aliasdomain' => 'integer',
        'limit_ftp_user' => 'integer',
        'limit_shell_user' => 'integer',
        'limit_webdav_user' => 'integer',
        'limit_database' => 'integer',
        'limit_database_quota' => 'integer',
        'limit_dns_zone' => 'integer',
        'limit_dns_record' => 'integer',
        'limit_cron' => 'integer',
        'limit_web_ip' => 'integer',
    ];

    /**
     * Default attribute values.
     *
     * @var array
     */
    protected $attributes = [
        'active' => 'y',
        'template_type' => 'm',
        'limit_client' => 0,
        'limit_maildomain' => 0,
        'limit_mailbox' => 0,
        'limit_mailalias' => 0,
        'limit_mailaliasdomain' => -1,
        'limit_mailforward' => 0,
        'limit_mailcatchall' => -1,
        'limit_mailrouting' => 0,
        'limit_mailquota' => -1,
        'limit_web_domain' => 0,
        'limit_web_quota' => -1,
        'limit_web_subdomain' => -1,
        'limit_web_aliasdomain' => -1,
        'limit_ftp_user' => 0,
        'limit_database' => 0,
    ];

    /**
     * Get the clients that use this template.
     */
    public function clients()
    {
        return $this->hasMany(Client::class, 'template_id', 'template_id');
    }
}
