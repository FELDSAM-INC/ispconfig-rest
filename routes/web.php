<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return response()->json([
        'name' => 'ISPConfig REST API',
        'version' => env('API_VERSION', '1.0'),
        'documentation' => '/api/documentation'
    ]);
});

// API Routes - Versioned
$apiPrefix = env('API_PREFIX', 'api/v1');

$router->group(['prefix' => $apiPrefix, 'middleware' => 'api.auth'], function () use ($router) {
    // Client endpoints
    $router->get('clients', 'Api\V1\ClientController@index');
    $router->get('clients/{id}', 'Api\V1\ClientController@show');
    $router->post('clients', 'Api\V1\ClientController@store');
    $router->put('clients/{id}', 'Api\V1\ClientController@update');
    $router->delete('clients/{id}', 'Api\V1\ClientController@destroy');
    
    // Client Domain endpoints
    $router->get('clients/domains', 'Api\V1\ClientDomainController@index');
    $router->get('clients/domains/{id}', 'Api\V1\ClientDomainController@show');
    $router->post('clients/domains', 'Api\V1\ClientDomainController@store');
    $router->put('clients/domains/{id}', 'Api\V1\ClientDomainController@update');
    $router->delete('clients/domains/{id}', 'Api\V1\ClientDomainController@destroy');
    
    // Monitor - DataLog endpoints
    $router->get('monitor/data-logs', 'Api\V1\Monitor\DataLogController@index');
    $router->get('monitor/data-logs/{datalog_id}', 'Api\V1\Monitor\DataLogController@show');
    
    // Additional endpoints can be added here for other ISPConfig entities
    // based on the API specification in the api/ directory
});
