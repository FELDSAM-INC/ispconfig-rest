<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SwaggerController extends Controller
{
    /**
     * Display Swagger UI.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('swagger');
    }

    /**
     * Get the OpenAPI specification directly from YAML files.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSpec()
    {
        $yamlFile = base_path('api/openapi.yaml');
        
        if (!File::exists($yamlFile)) {
            return response()->json(['error' => 'OpenAPI specification not found'], 404);
        }
        
        try {
            // Return the raw YAML content with proper headers
            $content = File::get($yamlFile);
            return response($content)
                ->header('Content-Type', 'application/yaml')
                ->header('Content-Disposition', 'inline; filename="openapi.yaml"');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reading OpenAPI specification: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Get a specific module YAML file.
     * This handles requests for module YAML files from Swagger UI.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getModuleSpec(Request $request)
    {
        // Get the full path from the request
        $requestPath = $request->path();
        
        // Remove the 'api/' prefix to get the relative path within the api directory
        $relativePath = preg_replace('/^api\//', '', $requestPath);
        
        // Make sure the file has .yaml extension
        if (!Str::endsWith($relativePath, '.yaml')) {
            $relativePath .= '.yaml';
        }
        
        // Get the file from the api directory, refusing path traversal
        $apiRoot = realpath(base_path('api'));
        $filePath = realpath(base_path('api/' . $relativePath));

        if ($filePath === false || !str_starts_with($filePath, $apiRoot . DIRECTORY_SEPARATOR)) {
            return response()->json(['error' => 'Module specification not found: ' . $relativePath], 404);
        }
        
        try {
            // Return the raw YAML content with proper headers
            $content = File::get($filePath);
            return response($content)
                ->header('Content-Type', 'application/yaml')
                ->header('Content-Disposition', 'inline; filename="' . basename($filePath) . '"');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reading module specification: ' . $e->getMessage()], 500);
        }
    }
}
