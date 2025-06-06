<?php

namespace App\Models;

class ClientReseller extends Client
{
    /**
     * Validation rules for the reseller model
     *
     * @var array
     */
    public static $rules = [
        // Basic Information
        'company_name' => 'sometimes|string|max:64',
        'contact_name' => 'sometimes|string|max:255',
        'username' => 'sometimes|string|max:64',
        'password' => 'sometimes|string|min:8',
        'email' => 'sometimes|email|max:255',
        
        // Address Information
        'street' => 'sometimes|string',
        'zip' => 'sometimes|string|max:15',
        'city' => 'sometimes|string|max:255',
        'state' => 'sometimes|string|max:255',
        'country' => 'sometimes|string|max:2',
        
        // Contact Information
        'telephone' => 'sometimes|string|max:32',
        'mobile' => 'sometimes|string|max:32',
        'fax' => 'sometimes|string|max:32',
        
        // System Settings
        'template_master' => 'sometimes|integer',
        
        // Resource Limits - Reseller specific
        'limit_client' => 'sometimes|integer',
        'limit_web_domain' => 'sometimes|integer',
        'limit_web_quota' => 'sometimes|integer',
        'limit_web_user' => 'sometimes|integer',
        'limit_mail_domain' => 'sometimes|integer',
        'limit_mailbox' => 'sometimes|integer',
        'limit_mail_quota' => 'sometimes|integer',
        'limit_database' => 'sometimes|integer',
        'limit_dns_domain' => 'sometimes|integer',
        'limit_cron' => 'sometimes|integer',
        'limit_shell_user' => 'sometimes|integer',
        
        // PHP Configuration
        'limit_php_mode' => 'sometimes|string|in:php-fcgi,php-fpm,mod_php',
        'limit_php_upload_max_filesize' => 'sometimes|integer',
        'limit_php_post_max_size' => 'sometimes|integer',
        'limit_php_max_execution_time' => 'sometimes|integer',
        'limit_php_memory_limit' => 'sometimes|integer',
        'limit_php_disable_functions' => 'sometimes|string',
        
        // Mail Configuration
        'limit_php_mail_function' => 'sometimes|string|in:mail,smtp,sendmail,qmail',
        'limit_php_mail_smtp_server' => 'sometimes|string',
        'limit_php_mail_smtp_port' => 'sometimes|integer',
        'limit_php_mail_smtp_ssl' => 'sometimes|string|in:none,ssl,tls',
        'limit_php_mail_smtp_auth' => 'sometimes|string|in:none,plain,login,cram-md5',
        'limit_php_mail_smtp_user' => 'sometimes|string',
        'limit_php_mail_smtp_pass' => 'sometimes|string',
        
        // Other Client fields that may be relevant
        'language' => 'sometimes|string|max:2',
        'usertheme' => 'sometimes|string|max:32',
        'notes' => 'sometimes|nullable|string',
        'locked' => 'sometimes|in:y,n',
        'canceled' => 'sometimes|in:y,n',
        'can_use_api' => 'sometimes|in:y,n',
    ];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Add a global scope to only include clients that are resellers
        static::addGlobalScope('reseller', function ($builder) {
            $builder->where(function($q) {
                $q->where('limit_client', '>', 0)
                  ->orWhere('limit_client', -1);
            });
        });
    }

    /**
     * Get the clients belonging to this reseller
     */
    public function clients()
    {
        return $this->hasMany(Client::class, 'parent_client_id', 'client_id');
    }
}
