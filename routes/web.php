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

// Swagger Documentation Routes
$router->get('/api/documentation', 'SwaggerController@index');
$router->get('/api/spec', 'SwaggerController@getSpec');
$router->get('/api/modules/{path:.*}', 'SwaggerController@getModuleSpec');
$router->get('/api/components/{path:.*}', 'SwaggerController@getModuleSpec');

// API Routes - Versioned
$apiPrefix = env('API_PREFIX', 'api/v1');

$router->group(['prefix' => $apiPrefix, 'middleware' => 'api.auth'], function () use ($router) {
    // Client Domain endpoints - more specific routes first
    $router->get('clients/domains', 'Api\V1\ClientDomainController@index');
    $router->get('clients/domains/{id}', 'Api\V1\ClientDomainController@show');
    $router->post('clients/domains', 'Api\V1\ClientDomainController@store');
    $router->put('clients/domains/{id}', 'Api\V1\ClientDomainController@update');
    $router->delete('clients/domains/{id}', 'Api\V1\ClientDomainController@destroy');
    
    // Client Template endpoints - more specific routes first
    $router->get('clients/templates', 'Api\V1\ClientTemplateController@index');
    $router->get('clients/templates/{id}', 'Api\V1\ClientTemplateController@show');
    $router->post('clients/templates', 'Api\V1\ClientTemplateController@store');
    $router->put('clients/templates/{id}', 'Api\V1\ClientTemplateController@update');
    $router->delete('clients/templates/{id}', 'Api\V1\ClientTemplateController@destroy');
    
    // Client Template Assignment endpoints
    $router->get('clients/{client_id}/templates', 'Api\V1\ClientTemplateAssignmentController@index');
    $router->post('clients/{client_id}/templates', 'Api\V1\ClientTemplateAssignmentController@store');
    $router->get('clients/{client_id}/templates/{template_id}', 'Api\V1\ClientTemplateAssignmentController@show');
    $router->delete('clients/{client_id}/templates/{template_id}', 'Api\V1\ClientTemplateAssignmentController@destroy');
    
    // Client Circle endpoints
    $router->get('clients/circles', 'Api\V1\ClientCircleController@index');
    $router->post('clients/circles', 'Api\V1\ClientCircleController@store');
    $router->get('clients/circles/{id}', 'Api\V1\ClientCircleController@show');
    $router->put('clients/circles/{id}', 'Api\V1\ClientCircleController@update');
    $router->delete('clients/circles/{id}', 'Api\V1\ClientCircleController@destroy');

    // Client endpoints - general routes last
    $router->get('clients', 'Api\V1\ClientController@index');
    $router->post('clients', 'Api\V1\ClientController@store');
    $router->get('clients/{id}', 'Api\V1\ClientController@show');
    $router->put('clients/{id}', 'Api\V1\ClientController@update');
    $router->delete('clients/{id}', 'Api\V1\ClientController@destroy');
    
    // Monitor - DataLog endpoints
    $router->get('monitor/data-logs', 'Api\V1\Monitor\DataLogController@index');
    $router->get('monitor/data-logs/{datalog_id}', 'Api\V1\Monitor\DataLogController@show');
    
    // Reseller endpoints
    $router->get('resellers', 'Api\V1\ClientResellerController@index');
    $router->post('resellers', 'Api\V1\ClientResellerController@store');
    $router->get('resellers/{id}', 'Api\V1\ClientResellerController@show');
    $router->put('resellers/{id}', 'Api\V1\ClientResellerController@update');
    $router->delete('resellers/{id}', 'Api\V1\ClientResellerController@destroy');
    
    // Additional endpoints can be added here for other ISPConfig entities
    // based on the API specification in the api/ directory
});
