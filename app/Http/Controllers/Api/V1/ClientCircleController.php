<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ClientCircle;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class ClientCircleController extends Controller
{
    /**
     * Display a listing of client circles
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = ClientCircle::query();
        
        // Apply filters
        if ($request->has('active')) {
            $query->where('active', $request->input('active'));
        }
        
        if ($request->has('circle_name')) {
            $query->where('circle_name', 'LIKE', $request->input('circle_name'));
        }
        
        if ($request->has('description')) {
            $query->where('description', 'LIKE', $request->input('description'));
        }
        
        // Apply sorting
        $sortField = $request->input('sort', 'circle_id');
        $sortOrder = $request->input('order', 'asc');
        $query->orderBy($sortField, $sortOrder);
        
        // Apply pagination
        $limit = (int)$request->input('limit', 15);
        $offset = (int)$request->input('offset', 0);
        
        $total = $query->count();
        $items = $query->skip($offset)->take($limit)->get();
        
        return response()->json([
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit
            ]
        ]);
    }

    /**
     * Store a newly created client circle
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Validate request
        $this->validate($request, ClientCircle::$rules);
        
        // Check for duplicate circle name
        if (ClientCircle::where('circle_name', $request->input('circle_name'))->exists()) {
            return response()->json([
                'message' => 'A client circle with this name already exists',
                'error' => 'Duplicate circle name'
            ], Response::HTTP_CONFLICT);
        }
        
        // Validate client IDs exist
        try {
            $this->validateClientIds($request->input('client_ids'));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Invalid client IDs',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
        
        // Create new client circle
        $circle = new ClientCircle($request->all());
        
        // Set system fields
        $this->setSystemFields($circle);
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Save to database
            $circle->save();
            
            DB::commit();
            
            return response()->json($circle, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create client circle: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to create client circle',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified client circle
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $circle = ClientCircle::find($id);
        
        if (!$circle) {
            return response()->json(['error' => 'Client circle not found'], Response::HTTP_NOT_FOUND);
        }
        
        return response()->json($circle);
    }

    /**
     * Update the specified client circle
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $circle = ClientCircle::find($id);
        
        if (!$circle) {
            throw new NotFoundException('Client circle not found');
        }
        
        // Validate request with "sometimes" rule to make fields optional on update
        $rules = array_map(function($rule) {
            return 'sometimes|' . $rule;
        }, ClientCircle::$rules);
        
        // Add unique constraint for circle_name
        $rules['circle_name'] = [
            'sometimes',
            'required',
            'string',
            'max:64',
            Rule::unique('client_circle')->ignore($id, 'circle_id')
        ];
        
        $this->validate($request, $rules);
        
        // Validate client IDs if provided
        if ($request->has('client_ids')) {
            try {
                $this->validateClientIds($request->input('client_ids'));
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Invalid client IDs',
                    'error' => $e->getMessage()
                ], Response::HTTP_BAD_REQUEST);
            }
        }
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Update client circle
            $circle->fill($request->all());
            $circle->save();
            
            DB::commit();
            
            // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
            return response()->json($circle, Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update client circle: ' . $e->getMessage(), ['exception' => $e, 'id' => $id]);
            return response()->json([
                'message' => 'Failed to update client circle',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified client circle
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $circle = ClientCircle::find($id);
        
        if (!$circle) {
            return response()->json(['error' => 'Client circle not found'], Response::HTTP_NOT_FOUND);
        }
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Delete the circle - this will use BaseModel's delete method to log to datalog
            $circle->delete();
            
            DB::commit();
            
            // Return 204 No Content for successful deletion
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete client circle: ' . $e->getMessage(), ['exception' => $e, 'id' => $id]);
            return response()->json([
                'message' => 'Failed to delete client circle',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Validate that all client IDs in the comma-separated list exist
     *
     * @param  string  $clientIdsString
     * @return void
     * @throws ValidationException
     */
    protected function validateClientIds($clientIdsString)
    {
        if (empty($clientIdsString)) {
            return;
        }
        
        $clientIds = array_map('intval', explode(',', $clientIdsString));
        $clientIds = array_filter($clientIds);
        
        if (empty($clientIds)) {
            throw new \Exception('Client IDs list is invalid');
        }
        
        // Check if all client IDs exist
        $existingCount = Client::whereIn('client_id', $clientIds)->count();
        
        if ($existingCount !== count($clientIds)) {
            throw new \Exception('One or more client IDs do not exist');
        }
    }
    
    /**
     * Set system fields for a new model
     *
     * @param  ClientCircle  $circle
     * @return void
     */
    protected function setSystemFields(ClientCircle $circle)
    {
        // Set system fields based on authenticated user
        $circle->sys_userid = $this->getCurrentUserId();
        $circle->sys_groupid = $this->getCurrentGroupId();
        $circle->sys_perm_user = 'riud';
        $circle->sys_perm_group = 'riud';
        $circle->sys_perm_other = '';
    }
}
