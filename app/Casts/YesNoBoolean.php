<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Casts ISPConfig ENUM('n','y') / ENUM('N','Y') columns to booleans.
 *
 * The stored case matters: sys_datalog payloads are consumed by legacy
 * ISPConfig server plugins that compare against the exact column case.
 * Most tables use lowercase 'y'/'n' (the default); DNS tables use
 * uppercase — declare those casts as YesNoBoolean::class.':upper'.
 */
class YesNoBoolean implements CastsAttributes
{
    protected string $true;

    protected string $false;

    public function __construct(string $case = 'lower')
    {
        $upper = strtolower($case) === 'upper';
        $this->true = $upper ? 'Y' : 'y';
        $this->false = $upper ? 'N' : 'n';
    }

    /**
     * Cast the stored value to a boolean for the application.
     */
    public function get($model, string $key, $value, array $attributes): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return in_array(strtoupper($value), ['Y', '1', 'TRUE'], true);
        }

        return (bool) $value;
    }

    /**
     * Prepare the boolean for storage in the column's native case.
     */
    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = in_array(strtolower($value), ['y', 'yes', '1', 'true'], true);
        }

        return $value ? $this->true : $this->false;
    }
}
