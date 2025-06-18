<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MailDomain extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mail_domain';

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
        'server_id',
        'domain',
        'dkim',
        'dkim_private',
        'dkim_selector',
        'relay_host',
        'relay_user',
        'relay_pass',
        'active',
        'local_delivery',
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'active' => \App\Casts\YesNoBoolean::class,
        'dkim' => \App\Casts\YesNoBoolean::class,
        'local_delivery' => \App\Casts\YesNoBoolean::class,
        'server_id' => 'integer',
        'sys_groupid' => 'integer',
        'sys_userid' => 'integer',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'relay_pass',
    ];

    /**
     * Default values for attributes
     *
     * @var array
     */
    protected $attributes = [
        'active' => true,
        'dkim' => false,
        'local_delivery' => true,
        'dkim_selector' => 'default',
        'sys_perm_user' => 'riud',
        'sys_perm_group' => 'riud',
        'sys_perm_other' => '',
    ];

    /**
     * Validation rules for the model
     *
     * @var array
     */
    public static $rules = [
        'server_id' => 'required|integer|exists:server,server_id',
        'domain' => 'required|string|max:255|regex:/^[a-zA-Z0-9\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}[\.]{0,1}$/|unique:mail_domain,domain',
        'dkim' => 'required|in:y,n',
        'dkim_private' => 'required_if:dkim,y|nullable|string',
        'dkim_selector' => 'nullable|string|max:126|regex:/^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/',
        'relay_host' => 'nullable|string|max:255',
        'relay_user' => 'required_with:relay_host|nullable|string|max:255',
        'relay_pass' => 'required_with:relay_user|nullable|string|max:255',
        'active' => 'required|in:y,n',
        'local_delivery' => 'required|in:y,n',
        'sys_userid' => 'required|integer|exists:sys_user,userid',
        'sys_groupid' => 'required|integer|exists:sys_group,groupid',
        'sys_perm_user' => 'sometimes|string|max:5|regex:/^[riud]*$/',
        'sys_perm_group' => 'sometimes|string|max:5|regex:/^[riud]*$/',
        'sys_perm_other' => 'sometimes|string|max:5|regex:/^[riud]*$/',
    ];

    /**
     * Get the validation rules for a specific operation
     *
     * @param int|null $id
     * @return array
     */
    public static function getValidationRules($id = null)
    {
        $rules = self::$rules;
        
        // For updates, make all fields optional and handle unique constraint for domain
        if ($id) {
            // Make domain unique except for the current record
            $rules['domain'] = str_replace('required', 'sometimes', $rules['domain']);
            $rules['domain'] = str_replace('unique:mail_domain,domain', 'unique:mail_domain,domain,' . $id . ',domain_id', $rules['domain']);
            
            // Make all required fields optional
            foreach ($rules as $field => &$rule) {
                $rules[$field] = str_replace('required', 'sometimes', $rule);
            }
        }
        
        return $rules;
    }

    /**
     * Validate the DKIM private key
     *
     * @param string $privateKey
     * @return bool
     */
    public static function validateDkimPrivateKey($privateKey)
    {
        if (empty($privateKey)) {
            return true;
        }

        // Check if it's a valid private key
        $keyResource = openssl_pkey_get_private($privateKey);
        return $keyResource !== false;
    }

    /**
     * Get the server that owns the mail domain.
     */
    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id', 'server_id');
    }

    /**
     * Get the group that owns the mail domain.
     */
    public function group()
    {
        return $this->belongsTo(Group::class, 'sys_groupid', 'groupid');
    }

    /**
     * Scope a query to only include active domains.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'y');
    }

    /**
     * Scope a query to only include domains with local delivery.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithLocalDelivery($query)
    {
        return $query->where('local_delivery', 'y');
    }

    /**
     * Scope a query to only include domains for a specific server.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $serverId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForServer($query, $serverId)
    {
        return $query->where('server_id', $serverId);
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Set default values when creating a new model
        static::creating(function ($model) {
            if (empty($model->sys_userid)) {
                $model->sys_userid = auth()->id() ?? 1;
            }
        });
    }
}
