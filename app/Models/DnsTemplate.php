<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsTemplate extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dns_template';

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
        'name',
        'fields',
        'template',
        'visible',
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other'
    ];

    /**
     * The model's default attribute values.
     *
     * @var array
     */
    protected $attributes = [
        'visible' => true,
        'sys_perm_user' => 'riud',
        'sys_perm_group' => 'riud',
        'sys_perm_other' => ''
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
        'visible' => \App\Casts\YesNoBoolean::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * The valid field types for DNS templates
     * 
     * @var array
     */
    public static $validFields = [
        'DOMAIN', 'IP', 'IPV6', 'NS1', 'NS2', 'EMAIL', 'DKIM', 'DNSSEC'
    ];

    /**
     * Validation rules for the model
     *
     * @var array
     */
    public static $rules = [
        'name' => 'required|string|max:255',
        'fields' => 'required|string|max:255',
        'template' => 'required|string',
        'visible' => 'required|in:y,n',
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
        
        // For updates, make all fields optional
        if ($id) {
            // Make all required fields optional
            foreach ($rules as $field => $rule) {
                $rules[$field] = str_replace('required', 'sometimes', $rule);
            }
        }
        
        return $rules;
    }
    
    /**
     * Custom validation rule to check if fields are valid
     *
     * @param array $data
     * @return array
     */
    public static function getCustomValidationRules(array $data)
    {
        $errors = [];
        
        // Validate that fields contains only valid values
        if (isset($data['fields'])) {
            $fieldValues = explode(',', $data['fields']);
            foreach ($fieldValues as $field) {
                $trimmedField = trim($field);
                if (!empty($trimmedField) && !in_array($trimmedField, self::$validFields)) {
                    $errors['fields'] = ['The fields must contain only valid field types: ' . implode(', ', self::$validFields)];
                    break;
                }
            }
        }
        
        return $errors;
    }

    /**
     * Get the user that owns the template.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sys_userid', 'userid');
    }

    /**
     * Get the group that owns the template.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'sys_groupid', 'groupid');
    }

    /**
     * Scope a query to only include visible templates.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVisible($query)
    {
        return $query->where('visible', 'y');
    }

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Set sys_userid to authenticated user or admin (id=1) if not provided
            if (!isset($model->sys_userid)) {
                $model->sys_userid = auth()->check() ? auth()->user()->userid : 1;
            }
        });
    }
}
