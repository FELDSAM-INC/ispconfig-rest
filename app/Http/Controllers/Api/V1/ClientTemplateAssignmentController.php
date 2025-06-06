<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\ClientTemplateAssigned;
use App\Services\ClientTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ClientTemplateAssignmentController extends Controller
{
    /**
     * @var ClientTemplateService
     */
    protected $clientTemplateService;
    
    /**
     * Constructor
     * 
     * @param ClientTemplateService $clientTemplateService
     */
    public function __construct(ClientTemplateService $clientTemplateService)
    {
        $this->clientTemplateService = $clientTemplateService;
    }
    
    /**
     * This controller handles both master and additional template assignments.
     * 
     * - Master templates are stored in the template_master field of the client table
     * - Additional templates are stored in the client_template_assigned table
     * 
     * The template_type field in the ClientTemplate model determines whether a template
     * is a master ('m') or additional ('a') template.
     */
    
    /**
     * List all templates assigned to a client
     * Includes both master template and additional templates
     *
     * @param int $client_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($client_id)
    {
        $client = Client::find($client_id);
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
        }

        $result = [];
        
        // Get master template if assigned
        if ($client->masterTemplate) {
            $result[] = [
                'client_id' => $client_id,
                'client_template_id' => $client->masterTemplate->template_id,
                'is_master' => true,
                'template' => $client->masterTemplate
            ];
        }
        
        // Get additional templates
        $addonTemplates = $client->addonTemplates;
        foreach ($addonTemplates as $template) {
            $result[] = [
                'client_id' => $client_id,
                'client_template_id' => $template->template_id,
                'is_master' => false,
                'template' => $template
            ];
        }

        return response()->json([
            'data' => $result
        ]);
    }

    /**
     * Get template assignment details
     * Handles both master and additional templates
     *
     * @param int $client_id
     * @param int $template_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($client_id, $template_id)
    {
        $client = Client::find($client_id);
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Check if this is the master template
        if ($client->masterTemplate && $client->masterTemplate->template_id == $template_id) {
            return response()->json([
                'client_id' => $client_id,
                'client_template_id' => $template_id,
                'is_master' => true,
                'template' => $client->masterTemplate
            ]);
        }
        
        // Check for additional template assignment
        $addonTemplate = $client->addonTemplates()
            ->where('client_template.template_id', $template_id)
            ->first();

        if (!$addonTemplate) {
            return response()->json(['error' => 'Template assignment not found'], Response::HTTP_NOT_FOUND);
        }
        
        return response()->json([
            'client_id' => $client_id,
            'client_template_id' => $template_id,
            'is_master' => false,
            'template' => $addonTemplate
        ]);
    }

    /**
     * Assign a template to a client
     * Handles both master and additional templates based on template_type
     *
     * @param Request $request
     * @param int $client_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $client_id)
    {
        $client = Client::find($client_id);
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
        }

        $this->validate($request, [
            'client_template_id' => 'required|integer|exists:client_template,template_id'
        ]);

        $templateId = $request->input('client_template_id');
        
        // Validate if template exists using the service
        $validationErrors = $this->clientTemplateService->validateTemplateAssignments([$templateId]);
        if (!empty($validationErrors)) {
            return response()->json([
                'error' => $validationErrors[0]
            ], Response::HTTP_BAD_REQUEST);
        }
        
        // Get the template to check its type
        $template = ClientTemplate::find($templateId);
        
        // For master templates, check if client already has this master template
        if ($template->template_type === 'm') {
            // If client already has this master template, return conflict
            if ($client->masterTemplate && $client->masterTemplate->template_id == $templateId) {
                return response()->json([
                    'error' => 'This master template is already assigned to this client'
                ], Response::HTTP_CONFLICT);
            }
        } else {
            // For additional templates, check if assignment already exists
            $hasTemplate = $client->addonTemplates()
                ->where('client_template.template_id', $templateId)
                ->exists();
    
            if ($hasTemplate) {
                return response()->json([
                    'error' => 'This additional template is already assigned to this client'
                ], Response::HTTP_CONFLICT);
            }
        }

        try {
            DB::beginTransaction();
            
            // Use the service to create the assignment
            // The service will handle master vs additional templates based on template_type
            $response = $this->clientTemplateService->createTemplateAssignment($client_id, $templateId);
                
            DB::commit();

            // Return 201 Created with the assignment
            return response()->json($response, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to assign template: ' . $e->getMessage(), [
                'exception' => $e,
                'client_id' => $client_id,
                'template_id' => $templateId
            ]);
            return response()->json([
                'message' => 'Failed to assign template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Unassign a template from a client
     *
     * @param int $client_id
     * @param int $template_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($client_id, $template_id)
    {
        $client = Client::find($client_id);
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
        }
        
        try {
            DB::beginTransaction();

            // Use the service to delete the assignment
            // The service will throw an exception if the assignment doesn't exist
            $this->clientTemplateService->deleteTemplateAssignment($client_id, $template_id);

            DB::commit();

            // Return 204 No Content as per API spec
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            
            // If the assignment doesn't exist, return 404
            if (strpos($e->getMessage(), 'not found') !== false) {
                return response()->json(['error' => 'Template assignment not found'], Response::HTTP_NOT_FOUND);
            }
            
            // Log other errors
            \Log::error('Failed to unassign template: ' . $e->getMessage(), [
                'exception' => $e,
                'client_id' => $client_id,
                'template_id' => $template_id
            ]);
            
            return response()->json([
                'message' => 'Failed to unassign template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
