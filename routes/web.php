<?php

use App\Http\Controllers\SwaggerController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'ISPConfig REST API',
    'version' => config('api.version'),
    'documentation' => '/api/documentation',
]));

// Swagger UI and multi-file OpenAPI spec passthrough
Route::get('/api/documentation', [SwaggerController::class, 'index']);
Route::get('/api/spec', [SwaggerController::class, 'getSpec']);
Route::get('/api/modules/{path}', [SwaggerController::class, 'getModuleSpec'])->where('path', '.*');
Route::get('/api/components/{path}', [SwaggerController::class, 'getModuleSpec'])->where('path', '.*');
