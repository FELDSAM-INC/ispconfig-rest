<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DnsRecord extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_rr';

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
        'zone',
        'name',
        'type',
        'data',
        'aux',
        'ttl',
        'active',
        'stamp',
        'serial',
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other'
    ];
    
    /**
     * The meta attributes to use for data field parsing.
     */
    protected $meta = [
        'protected',
        'priority',
        'port',
        'weight',
        'protocol',
        'algorithm',
        'cert_type',
        'cert_key_tag',
        'cert_algorithm',
        'cert_usage',
        'cert_selector',
        'cert_matching_type',
        'cert_fingerprint',
        'ordername',
        'auth'
    ];
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'active' => \App\Casts\YesNoBoolean::class,
        'ttl' => 'integer',
        'stamp' => 'integer',
        'serial' => 'integer',
        'server_id' => 'integer',
        'zone' => 'integer',
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
        'auth' => true,
        'ttl' => 3600,
        'sys_perm_user' => 'riud',
        'sys_perm_group' => 'riud',
        'sys_perm_other' => '',
    ];

    /**
     * Record types that require special validation
     *
     * @var array
     */
    protected static $recordTypes = [
        'A', 'AAAA', 'MX', 'CNAME', 'TXT', 'NS', 'PTR', 'SOA', 'SRV', 'NAPTR',
        'CAA', 'SSHFP', 'TLSA', 'DS', 'DNSKEY', 'SPF', 'DKIM', 'DMARC', 'ALIAS', 'HINFO', 'RP', 'LOC'
    ];

    /**
     * Get the base validation rules for the model
     * 
     * @param bool $forUpdate Whether the rules are for an update operation
     * @return array
     */
    public static function getBaseRules($forUpdate = false)
    {
        $rules = [
            'zone' => 'integer|exists:dns_soa,id',
            'name' => 'string|max:255',
            'type' => 'string|max:10|in:A,AAAA,MX,CNAME,TXT,NS,PTR,SOA,SRV,NAPTR,CAA,SSHFP,TLSA,DS,DNSKEY,SPF,DKIM,DMARC,ALIAS,HINFO,RP,LOC',
            'data' => 'string|max:65535',
            'ttl' => 'integer|min:0|max:2147483647',
            'priority' => 'integer|min:0|max:65535',
            'port' => 'integer|min:0|max:65535',
            'weight' => 'integer|min:0|max:65535',
            'active' => 'in:y,n',
            'protected' => 'in:y,n',
            'auth' => 'in:y,n',
            'sys_userid' => 'integer|exists:sys_user,userid',
            'sys_groupid' => 'integer|exists:sys_group,groupid',
            'sys_perm_user' => 'string|max:5|regex:/^[riud]*$/',
            'sys_perm_group' => 'string|max:5|regex:/^[riud]*$/',
            'sys_perm_other' => 'string|max:5|regex:/^[riud]*$/',
            'algorithm' => 'integer|min:0|max:255',
            'cert_type' => 'integer|min:0|max:255',
            'cert_key_tag' => 'integer|min:0|max:65535',
            'cert_algorithm' => 'integer|min:0|max:255',
            'cert_usage' => 'integer|min:0|max:255',
            'cert_selector' => 'integer|min:0|max:255',
            'cert_matching_type' => 'integer|min:0|max:255',
            'cert_fingerprint' => 'string|max:255',
            'ordername' => 'string|max:255',
        ];
        
        // For create operations, make required fields mandatory
        if (!$forUpdate) {
            $rules['zone'] = 'required|' . $rules['zone'];
            $rules['name'] = 'required|' . $rules['name'];
            $rules['type'] = 'required|' . $rules['type'];
            $rules['data'] = 'required|' . $rules['data'];
            $rules['sys_userid'] = 'required|' . $rules['sys_userid'];
            $rules['sys_groupid'] = 'required|' . $rules['sys_groupid'];
        }
        
        return $rules;
    }
    
    /**
     * Validation rules for the model
     *
     * @deprecated Use getBaseRules() instead
     * @var array
     */
    public static $rules = [];

    /**
     * Get the validation rules for a specific record type
     *
     * @param string $type The record type (A, AAAA, MX, etc.)
     * @param int|null $id The record ID for update operations
     * @param bool $forUpdate Whether this is for an update operation
     * @return array
     */
    public static function getValidationRules($type = null, $id = null, $forUpdate = false)
    {
        $rules = self::getBaseRules($forUpdate);
        
        // Add type-specific validation rules
        if ($type) {
            $method = 'validate' . $type . 'Record';
            if (method_exists(static::class, $method)) {
                $typeRules = static::$method();
                
                // If this is an update, make type-specific rules optional
                if ($forUpdate) {
                    foreach ($typeRules as $field => &$rule) {
                        // Remove 'required|' from the beginning of the rule if it exists
                        $typeRules[$field] = preg_replace('/^required\|?/', '', $rule);
                    }
                }
                
                $rules = array_merge($rules, $typeRules);
            }
        }
        
        // For updates, add the current record ID to the unique constraints
        if ($id) {
            // No need to add 'sometimes' as all rules are optional for updates
            // Just ensure the IDs exist if they're provided
            $rules['zone'] = 'exists:dns_soa,id';
            $rules['sys_groupid'] = 'exists:sys_group,groupid';
        }
        
        return $rules;
    }

    /**
     * Validation rules for A records
     */
    protected static function validateARecord()
    {
        return [
            'data' => 'required|ipv4',
            'name' => 'required|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+$/i',
        ];
    }

    /**
     * Validation rules for AAAA records
     */
    protected static function validateAAAARecord()
    {
        return [
            'data' => 'required|ipv6',
            'name' => 'required|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+$/i',
        ];
    }

    /**
     * Validation rules for CNAME records
     */
    protected static function validateCNAMERecord()
    {
        return [
            'data' => 'required|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+\.?$/',
            'name' => 'required|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+$/i',
        ];
    }

    /**
     * Validation rules for MX records
     */
    protected static function validateMXRecord()
    {
        return [
            'data' => 'required|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+\.?$/',
            'name' => 'sometimes|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+$/i',
            'priority' => 'required|integer|min:0|max:65535',
        ];
    }

    /**
     * Validation rules for TXT records
     */
    protected static function validateTXTRecord()
    {
        return [
            'data' => 'required|string|max:65535',
            'name' => 'sometimes|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+$/i',
        ];
    }

    /**
     * Validation rules for NS records
     */
    protected static function validateNSRecord()
    {
        return [
            'data' => 'required|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+\.?$/',
            'name' => 'sometimes|string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+$/i',
        ];
    }

    /**
     * Get the zone that owns the record.
     */
    public function zone()
    {
        return $this->belongsTo(DnsSoa::class, 'id', 'zone');
    }

    /**
     * Scope a query to only include records of a specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', strtoupper($type));
    }

    /**
     * Scope a query to only include active records.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'y');
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
            
            // Set default TTL from zone if not provided
            if (empty($model->ttl) && $model->zone) {
                $zone = DnsSoa::find($model->zone);
                if ($zone) {
                    $model->ttl = $zone->ttl;
                }
            }
        });

        // When a record is updated, update the zone's serial
        static::saved(function ($model) {
            if ($model->zone) {
                $zone = DnsSoa::find($model->zone);
                if ($zone) {
                    $zone->incrementSerial();
                }
            }
        });

        // When a record is deleted, update the zone's serial
        static::deleted(function ($model) {
            if ($model->zone) {
                $zone = DnsSoa::find($model->zone);
                if ($zone) {
                    $zone->incrementSerial();
                }
            }
        });
    }
}
