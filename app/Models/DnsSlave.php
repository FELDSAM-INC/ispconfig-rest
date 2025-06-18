<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DnsSlave extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_slave';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'server_id',
        'origin',
        'ns',
        'xfer',
        'active',
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
        'server_id' => 'integer',
        'sys_groupid' => 'integer',
        'sys_userid' => 'integer',
    ];

    /**
     * Default values for attributes
     *
     * @var array
     */
    protected $attributes = [
        'active' => true,
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
        'origin' => 'required|string|max:255|regex:/^[a-zA-Z0-9\.\-\/]{1,255}\.[a-zA-Z0-9\-]{2,63}[\.]{0,1}$/|unique:dns_slave,origin',
        'ns' => 'required|string|max:255',
        'xfer' => 'nullable|string|max:255',
        'active' => 'required|in:y,n',
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
        
        // For updates, make all fields optional and handle unique constraint for origin
        if ($id) {
            // Make origin unique except for the current record
            $rules['origin'] = str_replace('required', 'sometimes', $rules['origin']);
            $rules['origin'] = str_replace('unique:dns_slave,origin', 'unique:dns_slave,origin,' . $id . ',id', $rules['origin']);
            
            // Make all required fields optional
            foreach ($rules as $field => $rule) {
                $rules[$field] = str_replace('required', 'sometimes', $rule);
            }
        }
        
        return $rules;
    }

    /**
     * Get the server that owns the DNS slave zone.
     */
    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id', 'server_id');
    }

    /**
     * Get the group that owns the DNS slave zone.
     */
    public function group()
    {
        return $this->belongsTo(Group::class, 'sys_groupid', 'groupid');
    }

    /**
     * Scope a query to only include active zones.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'y');
    }

    /**
     * Scope a query to only include zones for a specific server.
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
