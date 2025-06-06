<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PutPatchInputMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only process PUT and PATCH requests
        if (in_array($request->method(), ['PUT', 'PATCH'])) {
            // Get the raw input
            $rawInput = file_get_contents('php://input');
            
            // Log the raw input for debugging
            Log::info('Raw input for ' . $request->method() . ' request:', [
                'raw' => $rawInput,
                'content_type' => $request->header('Content-Type')
            ]);
            
            // Check content type
            $contentType = $request->header('Content-Type');
            
            // For JSON content type, validate and parse JSON
            if (strpos($contentType, 'application/json') !== false) {
                // Validate JSON
                json_decode($rawInput);
                $jsonError = json_last_error();
                
                if ($jsonError !== JSON_ERROR_NONE) {
                    $errorMessage = json_last_error_msg();
                    Log::error('Invalid JSON in request: ' . $errorMessage, ['input' => $rawInput]);
                    
                    return response()->json([
                        'error' => 'Invalid JSON in request',
                        'message' => $errorMessage
                    ], Response::HTTP_BAD_REQUEST);
                }
                
                // JSON is valid, decode it
                $data = json_decode($rawInput, true);
                
                // Replace the request input with our parsed data
                if (is_array($data)) {
                    $request->replace($data);
                    Log::info('Parsed JSON data:', ['data' => $data]);
                }
            } 
            // For form data
            elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
                parse_str($rawInput, $data);
                if (is_array($data)) {
                    $request->replace($data);
                    Log::info('Parsed form data:', ['data' => $data]);
                }
            }
            
            // Log the final request data
            Log::info('Final request data after middleware:', ['data' => $request->all()]);
        }

        return $next($request);
    }
    

}
