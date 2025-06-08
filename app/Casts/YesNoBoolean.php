<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class YesNoBoolean implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return bool
     */
    public function get($model, string $key, $value, array $attributes)
    {
        // Return a boolean value for the application
        if ($value === null) {
            return false;
        }
        
        // Handle string values (Y/N from database)
        if (is_string($value)) {
            $value = strtoupper($value);
            return $value === 'Y' || $value === '1' || $value === 'TRUE';
        }
        
        // Handle boolean or numeric values
        return (bool) $value;
    }

    /**
     * Convert a value to a database boolean string.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function toDbValue($value)
    {
        if (is_bool($value)) {
            return $value ? 'Y' : 'N';
        }
        
        if (is_string($value)) {
            $value = strtolower($value);
            return in_array($value, ['y', 'yes', '1', 'true']) ? 'Y' : 'N';
        }
        
        return $value ? 'Y' : 'N';
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return string
     */
    public function set($model, string $key, $value, array $attributes)
    {
        // If the value is null, return null to indicate no change
        if ($value === null) {
            return null;
        }
        
        // Convert the value to database format ('Y' or 'N')
        $newValue = $this->toDbValue($value);
        
        // Get the current original value before any changes
        $original = $model->getOriginal($key);
        
        // If the original value exists and is already in the correct format ('Y' or 'N'),
        // and the new value would be the same, return the original to prevent marking as dirty
        if ($original !== null && in_array($original, ['Y', 'N']) && $original === $newValue) {
            return $original;
        }
        
        return $newValue;
    }
}
