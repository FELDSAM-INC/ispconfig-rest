<?php

namespace App\Models;

use App\Services\DnsSerialService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DnsSoa extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_soa';

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
        'mbox',
        'serial',
        'refresh',
        'retry',
        'expire',
        'minimum',
        'ttl',
        'active',
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
        'xfer',
        'also_notify',
        'update_acl'
    ];


    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'active' => \App\Casts\YesNoBoolean::class,
        'serial' => 'integer',
        'refresh' => 'integer',
        'retry' => 'integer',
        'expire' => 'integer',
        'minimum' => 'integer',
        'ttl' => 'integer',
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
        'serial' => 1,
        'refresh' => 28800,
        'retry' => 7200,
        'expire' => 604800,
        'minimum' => 86400,
        'ttl' => 86400,
    ];

    /**
     * Validation rules for the model
     *
     * @var array
     */
    public static $rules = [
        'server_id' => 'required|integer|exists:server,server_id',
        'origin' => 'required|string|max:255|regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/|unique:dns_soa,origin',
        'ns' => 'required|string|max:255',
        'mbox' => 'required|string|max:255',
        'serial' => 'required|integer|min:1|max:4294967295',
        'refresh' => 'required|integer|min:0|max:2147483647',
        'retry' => 'required|integer|min:0|max:2147483647',
        'expire' => 'required|integer|min:0|max:2147483647',
        'minimum' => 'required|integer|min:0|max:2147483647',
        'ttl' => 'required|integer|min:0|max:2147483647',
        'active' => 'required|in:y,n',
        'sys_userid' => 'required|integer|exists:sys_user,userid',
        'sys_groupid' => 'required|integer|exists:sys_group,groupid',
        'sys_perm_user' => 'sometimes|string|max:5|regex:/^[riud]*$/',
        'sys_perm_group' => 'sometimes|string|max:5|regex:/^[riud]*$/',
        'sys_perm_other' => 'sometimes|string|max:5|regex:/^[riud]*$/',
        'xfer' => 'nullable|string|max:255',
        'also_notify' => 'nullable|string|max:255',
        'update_acl' => 'nullable|string|max:255',
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
            $rules['origin'] = str_replace('unique:dns_soa,origin', 'unique:dns_soa,origin,' . $id . ',id', $rules['origin']);
            
            // Make all required fields optional
            foreach ($rules as $field => $rule) {
                $rules[$field] = str_replace('required', 'sometimes', $rule);
            }
        }
        
        return $rules;
    }

    /**
     * Get the server that owns the DNS zone.
     */
    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id', 'server_id');
    }

    /**
     * Get the DNS records for the zone.
     */
    public function records()
    {
        return $this->hasMany(DnsRecord::class, 'zone', 'id');
    }

    /**
     * Get the group that owns the DNS zone.
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
     * Increment and return the next serial number for the zone.
     *
     * @return int
     */
    public function incrementSerial()
    {
        $this->serial = DnsSerialService::getNextSerialNumber($this->serial);
        $this->save();
        
        return $this->serial;
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
            
            // Generate a serial number if not provided
            if (empty($model->serial)) {
                $model->serial = (int) (date('Ymd') . '00');
            }
        });

        // When updating, ensure the serial is incremented if the zone is modified
        static::updating(function ($model) {
            // Skip if this is a system update (like updating the serial number)
            if ($model->isDirty() && !$model->isDirty('serial')) {
                $model->serial = $model->getNextSerialNumber();
            }
        });
    }
}
