<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DatalogService
{
    /**
     * Insert a record into the sys_datalog table
     *
     * @param string $table The target database table
     * @param string $primaryKey The primary key field name
     * @param int|string $primaryKeyValue The primary key value
     * @param string $action The action (i=insert, u=update, d=delete)
     * @param array $data The data to be logged
     * @param int $serverId The server ID
     * @param int $sysUserId The system user ID performing the action
     * @return int The datalog ID
     */
    public function log($table, $primaryKey, $primaryKeyValue, $action, $data = [], $serverId = 0, $sysUserId = 1) // Default sysUserId to 1 (admin)
    {
        // Derive username heuristically (can be improved with proper user lookup)
        $username = 'api_user'; // Default
        if ($sysUserId === 1 || $sysUserId === '1') { // Check for admin
            // Attempt to get admin username, default to 'admin'
            // This might require querying sys_user table if a specific admin username is needed
            $adminUser = DB::table('sys_user')->where('userid', 1)->first();
            $username = $adminUser ? $adminUser->username : 'admin';
        } else {
            // Attempt to get client username
            $clientUser = DB::table('client')->where('client_id', $sysUserId)->first(); // Assuming sys_userid for clients maps to client_id
            $username = $clientUser ? $clientUser->username : 'client_id_' . $sysUserId;
        }

        // Prepare the datalog entry
        $datalogEntry = [
            'dbtable' => $table,
            'dbidx' => $primaryKey . ':' . $primaryKeyValue,
            'server_id' => $serverId,
            'action' => $action,
            'tstamp' => time(),
            'user' => $username,
            'data' => !empty($data) ? serialize($data) : '',
            'status' => 'ok',
            'session_id' => session_id() ?: md5(uniqid()) // Use native PHP session_id()
        ];
        
        // Insert into datalog
        return DB::table('sys_datalog')->insertGetId($datalogEntry);
    }
    
    /**
     * Get the status of a datalog entry
     *
     * @param int $datalogId The datalog ID
     * @return array|null The datalog entry or null if not found
     */
    public function getStatus($datalogId)
    {
        return DB::table('sys_datalog')
            ->where('datalog_id', $datalogId)
            ->first();
    }
    
    /**
     * Get pending datalog entries for a specific table
     *
     * @param string $table The table name
     * @param string $user The username
     * @return array The pending datalog entries
     */
    public function getPendingEntries($table, $user = 'api_user')
    {
        return DB::table('sys_datalog')
            ->where('dbtable', $table)
            ->where('user', $user)
            ->where('status', 'pending')
            ->orderBy('datalog_id', 'asc')
            ->get()
            ->toArray();
    }
}
