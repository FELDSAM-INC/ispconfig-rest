<?php

namespace App\Services;

use Carbon\Carbon;

class DnsSerialService
{
    /**
     * Generate the next serial number for a DNS zone.
     *
     * @param int $currentSerial The current serial number
     * @return int The next serial number
     */
    public static function getNextSerialNumber($currentSerial)
    {
        $now = Carbon::now();
        $datePart = (int) $now->format('Ymd');
        
        // If the current serial is from today, increment the counter
        if ($currentSerial >= $datePart * 100) {
            return $currentSerial + 1;
        }
        
        // Otherwise, start a new serial with today's date
        return $datePart * 100;
    }
    
    /**
     * Get the current timestamp as an integer.
     *
     * @return int The current timestamp
     */
    public static function getCurrentTimestamp()
    {
        return Carbon::now()->format('Y-m-d H:i:s');
    }
}
