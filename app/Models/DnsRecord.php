<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Services\DnsRecordMetaService;
use App\Services\DnsSerialService;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The date format for the model's date fields.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * Meta fields that are not stored directly in the database
     * but are used in API requests/responses
     */
    protected $metaFields = [
        'A' => ['ipaddress'],
        'AAAA' => ['ipaddress'],
        'MX' => ['priority', 'hostname'],
        'SRV' => ['priority', 'weight', 'port', 'hostname'],
        'TLSA' => ['cert_usage', 'selector', 'matching_type', 'hostname'],
        'SSHFP' => ['algorithm', 'hash_type', 'hash'],
        'CAA' => ['caa_flag', 'caa_type', 'ca_issuer', 'additional'],
        'HINFO' => ['cpu', 'os'],
        'SPF' => ['allow_mx', 'allow_a', 'ipv4_address', 'ipv6_address', 'hostname', 'include', 'policy'],
        'DMARC' => ['policy', 'pct', 'rua', 'ruf', 'sp', 'adkim', 'aspf'],
        'NAPTR' => ['order', 'pref', 'naptr_flag', 'service', 'regexp', 'replacement'],
        'DS' => ['key_tag', 'algorithm', 'digest_type', 'digest']
    ];

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
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'active' => YesNoBoolean::class,
        'stamp' => 'string',
        'serial' => 'integer',
        'server_id' => 'integer',
        'zone' => 'integer',
        'sys_groupid' => 'integer',
        'sys_userid' => 'integer',
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var array
     */
    protected $appends = ['meta'];

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
        'CAA', 'SSHFP', 'TLSA', 'DS', 'SPF', 'DKIM', 'DMARC', 'ALIAS', 'HINFO', 'RP', 'LOC'
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
            'name' => 'string|max:255|regex:/^([a-z0-9\-]+\.)*[a-z0-9\-]+$/i',
            'type' => 'string|max:10|in:A,AAAA,MX,CNAME,TXT,NS,PTR,SOA,SRV,NAPTR,CAA,SSHFP,TLSA,DS,SPF,DKIM,DMARC,ALIAS,HINFO,RP,LOC',
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

        return $rules;
    }

    /**
     * Validation rules for A records
     */
    protected static function validateARecord()
    {
        return [
            'data' => 'required|ipv4',
        ];
    }

    /**
     * Validation rules for AAAA records
     */
    protected static function validateAAAARecord()
    {
        return [
            'data' => 'required|ipv6',
        ];
    }

    /**
     * Validation rules for CNAME records
     */
    protected static function validateCNAMERecord()
    {
        return [
            'data' => 'required|string|max:255|regex:/^([a-zA-Z0-9\.-]+\.)*[a-zA-Z0-9\.-]+\.?$/',
        ];
    }

    /**
     * Validation rules for TXT records
     */
    protected static function validateTXTRecord()
    {
        return [
            'data' => 'required|string|max:65535',
        ];
    }

    /**
     * Validation rules for NS records
     */
    protected static function validateNSRecord()
    {
        return [
            'data' => 'required|string|max:255|regex:/^[a-zA-Z0-9\.-]+$/',
        ];
    }

    /**
     * Validation rules for MX records
     */
    protected static function validateMXRecord()
    {
        return [
            'priority' => 'required|integer|min:0|max:65535',
            'hostname' => 'required|string|max:255|regex:/^([a-zA-Z0-9\.-]+\.)*[a-zA-Z0-9\.-]+\.?$/',
        ];
    }

    /**
     * Validation rules for SRV records
     */
    protected static function validateSRVRecord()
    {
        return [
            'priority' => 'required|integer|min:0|max:65535',
            'weight' => 'required|integer|min:0|max:65535',
            'port' => 'required|integer|min:0|max:65535',
            'hostname' => 'required|string|max:255|regex:/^([a-zA-Z0-9\.-]+\.)*[a-zA-Z0-9\.-]+\.?$/',
        ];
    }

    /**
     * Validation rules for TLSA records
     */
    protected static function validateTLSARecord()
    {
        return [
            'cert_usage' => 'required|integer|min:0|max:3',
            'selector' => 'required|integer|min:0|max:1',
            'matching_type' => 'required|integer|min:0|max:2',
            'hash' => 'required|string|max:255',
        ];
    }

    /**
     * Validation rules for SSHFP records
     */
    protected static function validateSSHFPRecord()
    {
        return [
            'algorithm' => 'required|integer|min:0|max:4',
            'hash_type' => 'required|integer|min:0|max:2',
            'hash' => 'required|string|max:255',
        ];
    }

    /**
     * Validation rules for CAA records
     */
    protected static function validateCAARecord()
    {
        return [
            'caa_flag' => 'required|integer|min:0|max:255',
            'caa_type' => 'required|string|in:issue,issuewild,iodef',
            'ca_issuer' => 'required_if:caa_type,issue,issuewild|string|max:255',
            'additional' => 'string|max:255|required_if:caa_type,iodef',
        ];
    }

    /**
     * Validation rules for HINFO records
     */
    protected static function validateHINFORecord()
    {
        return [
            'cpu' => 'required|string|max:255|regex:/^[a-zA-Z0-9\.-_\s]+$/',
            'os' => 'required|string|max:255|regex:/^[a-zA-Z0-9\.-_\s]+$/',
        ];
    }

    /**
     * Validation rules for SPF records
     */
    protected static function validateSPFRecord()
    {
        return [
            'allow_mx' => 'boolean',
            'allow_a' => 'boolean',
            'ipv4_address' => 'string|regex:/^(((?:\d{1,3}\.){3}\d{1,3})(?:\/(?:\d{1,2}|3[0-2]))?(?:\s+((?:\d{1,3}\.){3}\d{1,3})(?:\/(?:\d{1,2}|3[0-2]))?)*$/',
            'ipv6_address' => 'string|regex:/^(((?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4})(?:\/(?:\d{1,3}|1[0-9]{1,2}|2[0-4]\d|25[0-5]))?(?:\s+((?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4})(?:\/(?:\d{1,3}|1[0-9]{1,2}|2[0-4]\d|25[0-5]))?)*$/',
            'hostname' => 'string|max:255|regex:/^([a-zA-Z0-9\.-]+\.)*[a-zA-Z0-9\.-]+\.?$/',
            'include' => 'string|max:255|regex:/^([a-zA-Z0-9\.-]+\.)*[a-zA-Z0-9\.-]+\.?$/',
            'policy' => 'required|string|in:fail,softfail,neutral',
        ];
    }

    /**
     * Validation rules for DMARC records
     */
    protected static function validateDMARCRecord()
    {
        return [
            'policy' => 'required|string|in:none,quarantine,reject',
            'pct' => 'required|integer|min:0|max:100',
            'rua' => 'string|max:255',
            'ruf' => 'string|max:255',
            'sp' => 'required|string|in:none,quarantine,reject',
            'adkim' => 'required|string|in:r,s',
            'aspf' => 'required|string|in:r,s',
        ];
    }

    /**
     * Validation rules for NAPTR records
     */
    protected static function validateNAPTRRecord()
    {
        return [
            'order' => 'required|integer|min:0|max:65535',
            'pref' => 'required|integer|min:0|max:65535',
            'naptr_flag' => 'string|max:1|in:U,S,A,P',
            'service' => 'required|string|max:32',
            'regexp' => 'required_without:replacement|string|max:255',
            'replacement' => 'required_without:regexp|string|max:255',
        ];
    }

    /**
     * Validation rules for DS records
     */
    protected static function validateDSRecord()
    {
        return [
            'key_tag' => 'required|integer|min:0|max:65535',
            'algorithm' => 'required|integer|min:0|max:255',
            'digest_type' => 'required|integer|min:0|max:255',
            'digest' => 'required|string|max:255',
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
     * Update the serial number for this DNS record.
     *
     * @return int The new serial number
     */
    public function updateSerial()
    {
        $this->stamp = DnsSerialService::getCurrentTimestamp();
        $this->serial = DnsSerialService::getNextSerialNumber($this->serial);
        $this->save();

        return $this->serial;
    }

    /**
     * Process meta fields and update the data field accordingly
     * This is called during model creation and updates
     */
    protected function processMetaFields()
    {
        // Only process if we have a type
        if (!$this->type) {
            return;
        }

        $type = strtoupper($this->type);
        $attributes = $this->getAttributes();

        // Check if we have any meta fields for this record type
        if (!isset($this->metaFields[$type]) || empty($this->metaFields[$type])) {
            return;
        }

        // Check if any meta fields are present in the attributes
        $hasMetaFields = false;
        foreach ($this->metaFields[$type] as $field) {
            if (array_key_exists($field, $attributes)) {
                $hasMetaFields = true;
                break;
            }
        }

        // If no meta fields are present, no need to process
        if (!$hasMetaFields) {
            return;
        }

        // Process meta fields into the data field
        list($this->aux, $this->data) = DnsRecordMetaService::metaToData($attributes, $type);
    }

    /**
     * Get meta attribute accessor
     *
     * @return array
     */
    public function getMetaAttribute()
    {
        if (!$this->type || !$this->data) {
            return [];
        }

        $type = strtoupper($this->type);

        // Extract meta fields from data
        $metaData = DnsRecordMetaService::dataToMeta($this->getAttributes(), $type);

        return $metaData;
    }

    /**
     * Set meta attribute mutator
     *
     * @param array $value
     */
    public function setMetaAttribute($value)
    {
        if (!is_array($value)) {
            return;
        }

        // Set each meta field as a direct attribute
        foreach ($value as $key => $val) {
            $this->attributes[$key] = $val;
        }
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // For new records, we don't update the serial or stamp
            // They will be set by the DnsSoa model when the zone's serial is incremented

            // Process meta fields if present
            $model->processMetaFields();
        });

        static::updating(function ($model) {
            $model->stamp = DnsSerialService::getCurrentTimestamp();
            $currentSerial = $model->getOriginal('serial') ?? 0;
            $model->serial = DnsSerialService::getNextSerialNumber($currentSerial);

            // Process meta fields if present
            $model->processMetaFields();
        });

        // When a record is created, update the zone's serial
        static::created(function ($model) {
            if ($model->zone) {
                $zone = DnsSoa::find($model->zone);
                if ($zone) {
                    $zone->incrementSerial();
                }
            }
        });

        // When a record is updated, update the zone's serial
        static::updated(function ($model) {
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
