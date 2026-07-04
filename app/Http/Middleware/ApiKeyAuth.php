<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;

/**
 * X-API-Key authentication (constitution Principle V).
 *
 * Keys are stored SHA-256 hashed in the API-owned api_keys table, each bound
 * to an ISPConfig sys_userid/sys_groupid pair that downstream datalog writes
 * are attributed to. In local/testing environments a configured dev key
 * (config api.dev_key) authenticates as the admin user.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-API-Key');

        if ($key === null || $key === '') {
            return Problem::response(401, 'Unauthorized', 'API key is required. Provide it in the X-API-Key header.');
        }

        $devKey = config('api.dev_key');

        if ($devKey && app()->environment(['local', 'development', 'testing']) && hash_equals($devKey, $key)) {
            $request->attributes->set('sys_userid', 1);
            $request->attributes->set('sys_groupid', 1);

            return $next($request);
        }

        $apiKey = ApiKey::query()
            ->where('key_hash', hash('sha256', $key))
            ->where('active', true)
            ->first();

        if ($apiKey === null) {
            return Problem::response(401, 'Unauthorized', 'The provided API key is invalid or has been revoked.');
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('sys_userid', $apiKey->sys_userid);
        $request->attributes->set('sys_groupid', $apiKey->sys_groupid);
        $request->attributes->set('api_key_id', $apiKey->id);

        return $next($request);
    }
}
