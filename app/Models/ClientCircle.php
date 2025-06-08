<?php

namespace App\Models;

class ClientCircle extends BaseModel
{
    protected $table = 'client_circle';
    protected $primaryKey = 'circle_id';
    public $timestamps = false;

    protected $fillable = [
        'circle_name',
        'client_ids',
        'description',
        'active'
    ];

    // Validation rules
    public static $rules = [
        'circle_name' => 'required|string|max:64',
        'client_ids' => 'required|string',
        'description' => 'nullable|string',
        'active' => 'required|in:y,n'
    ];

    // Default values
    protected $attributes = [
        'active' => 'y'
    ];

    /**
     * Get the clients associated with this circle
     * 
     * @return array Array of client IDs
     */
    public function getClientIdsArray()
    {
        if (empty($this->client_ids)) {
            return [];
        }
        
        return array_map('intval', explode(',', $this->client_ids));
    }

    /**
     * Set client IDs from an array
     * 
     * @param array $clientIds Array of client IDs
     * @return void
     */
    public function setClientIdsFromArray(array $clientIds)
    {
        $this->client_ids = implode(',', array_unique(array_filter($clientIds, 'is_numeric')));
    }
}
