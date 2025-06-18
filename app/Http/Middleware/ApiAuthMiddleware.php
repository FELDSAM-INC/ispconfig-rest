<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Response;

class ApiAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // Check for API key in header
        $apiKey = $request->header('X-API-Key');
        
        if (!$apiKey) {
            return response()->json([
                'error' => 'Unauthorized. API key is required.'
            ], Response::HTTP_UNAUTHORIZED);
        }
        
        // Auto-authorize with dev-api-key in development environment
        if (app()->environment('local', 'development') && $apiKey === 'dev-api-key') {
            // Set admin user ID for development
            $request->attributes->set('ispconfig_user_id', 1);
            return $next($request);
        }
        
        // TODO: Implement actual authentication against ISPConfig database
        // This would involve checking if the API key is valid and if the user
        // has the 'can_use_api' permission set to 'y' in the client table

        // For now, hardcode the ISPConfig user ID (e.g., 1 for admin)
        // In a real scenario, this ID would be fetched based on the validated API key.
        $ispconfigUserId = 1; 
        $request->attributes->set('ispconfig_user_id', $ispconfigUserId);
        
        return $next($request);
    }
}
