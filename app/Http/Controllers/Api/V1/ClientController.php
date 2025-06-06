<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SysUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    /**
     * Display a listing of clients
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Apply filters, sorting, and pagination based on request parameters
        $query = Client::query();
        
        // Apply filters if provided
        if ($request->has('filter')) {
            $filters = $request->get('filter');
            foreach ($filters as $field => $value) {
                $query->where($field, $value);
            }
        }
        
        // Apply sorting
        $sort = $request->get('sort', 'client_id');
        $order = $request->get('order', 'asc');
        $query->orderBy($sort, $order);
        
        // Apply pagination
        $limit = $request->get('limit', 25);
        $offset = $request->get('offset', 0);
        
        $total = $query->count();
        $items = $query->skip($offset)->take($limit)->get();
        
        return response()->json([
            'items' => $items,
            'total' => $total,
            'limit' => (int)$limit,
            'offset' => (int)$offset
        ]);
    }

    /**
     * Display the specified client
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $client = Client::find($id);
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
        }
        
        return response()->json($client);
    }

    /**
     * Store a newly created client
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Use validation rules from the model
        // For store method, make certain fields required and add unique rule for username
        $rules = Client::$rules;
        $rules['company_name'] = 'required|string|max:255';
        $rules['contact_name'] = 'required|string|max:255';
        $rules['email'] = 'required|email|max:255';
        $rules['username'] = 'required|string|max:255|unique:client';
        $rules['password'] = 'required|string|min:8';
        
        $this->validate($request, $rules);
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            $data = $request->all();
            
            // If parent_client_id is provided, get the parent reseller to set permissions
            if (isset($data['parent_client_id']) && !empty($data['parent_client_id'])) {
                $parentReseller = Client::find($data['parent_client_id']);
                
                if (!$parentReseller) {
                    return response()->json([
                        'message' => 'Parent reseller not found',
                        'error' => 'The specified parent_client_id does not exist'
                    ], Response::HTTP_BAD_REQUEST);
                }
                
                // Check if the parent is actually a reseller (has limit_client > 0 or = -1)
                if ($parentReseller->limit_client <= 0 && $parentReseller->limit_client != -1) {
                    return response()->json([
                        'message' => 'Invalid parent reseller',
                        'error' => 'The specified parent_client_id is not a reseller'
                    ], Response::HTTP_BAD_REQUEST);
                }
                
                // Get the sys_user record associated with the reseller
                // This follows ISPConfig's pattern in client_edit.php
                $sysUser = SysUser::whereHas('defaultGroup', function($query) use ($data) {
                    $query->where('client_id', $data['parent_client_id']);
                })->first();
                
                if (!$sysUser) {
                    return response()->json([
                        'message' => 'Parent reseller system user not found',
                        'error' => 'Could not find system user for the specified parent_client_id'
                    ], Response::HTTP_BAD_REQUEST);
                }
                
                // Set sys_userid and sys_groupid from the parent reseller's sys_user record
                $sysUserId = $sysUser->userid;
                $sysGroupId = $sysUser->default_group;
            } else {
                // No parent reseller, use authenticated user's ID
                $sysUserId = Auth::id() ?? 1;
                $sysGroupId = Auth::user()->sys_groupid ?? 1;
            }
            
            // Add system fields
            $data = array_merge($data, [
                'sys_userid' => $sysUserId,
                'sys_groupid' => $sysGroupId,
                'sys_perm_user' => 'riud',
                'sys_perm_group' => 'riud',
                'sys_perm_other' => '',
                'active' => 'y'
            ]);
            
            $client = new Client($data);
            $client->save(); // This will use BaseModel's save method to log to datalog
            
            DB::commit();
            
            // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
            return response()->json($client, Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create client',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified client
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $client = Client::find($id);
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Use validation rules from the model
        // Add unique rule for username
        $rules = Client::$rules;
        if ($request->has('username')) {
            $rules['username'] = 'sometimes|string|max:255|unique:client,username,' . $id . ',client_id';
        }
        
        $this->validate($request, $rules);
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Get the request data, excluding system fields
            $data = $request->except([
                'sys_userid', 'sys_groupid', 'sys_perm_user', 
                'sys_perm_group', 'sys_perm_other'
            ]);
            
            // Check if parent_client_id is being updated
            if (isset($data['parent_client_id']) && $data['parent_client_id'] != $client->parent_client_id) {
                // If parent_client_id is provided and changed, get the parent reseller to set permissions
                if (!empty($data['parent_client_id'])) {
                    $parentReseller = Client::find($data['parent_client_id']);
                    
                    if (!$parentReseller) {
                        return response()->json([
                            'message' => 'Parent reseller not found',
                            'error' => 'The specified parent_client_id does not exist'
                        ], Response::HTTP_BAD_REQUEST);
                    }
                    
                    // Check if the parent is actually a reseller (has limit_client > 0 or = -1)
                    if ($parentReseller->limit_client <= 0 && $parentReseller->limit_client != -1) {
                        return response()->json([
                            'message' => 'Invalid parent reseller',
                            'error' => 'The specified parent_client_id is not a reseller'
                        ], Response::HTTP_BAD_REQUEST);
                    }
                    
                    // Get the sys_user record associated with the reseller
                    // This follows ISPConfig's pattern in client_edit.php
                    $sysUser = SysUser::whereHas('defaultGroup', function($query) use ($data) {
                        $query->where('client_id', $data['parent_client_id']);
                    })->first();
                    
                    if (!$sysUser) {
                        return response()->json([
                            'message' => 'Parent reseller system user not found',
                            'error' => 'Could not find system user for the specified parent_client_id'
                        ], Response::HTTP_BAD_REQUEST);
                    }
                    
                    // Update sys_userid and sys_groupid from the parent reseller's sys_user record
                    $client->sys_userid = $sysUser->userid;
                    $client->sys_groupid = $sysUser->default_group;
                } else {
                    // If parent_client_id is being removed (set to null or 0), reset to admin ownership
                    // This follows ISPConfig's pattern in client_edit.php
                    $client->sys_userid = 1;
                    $client->sys_groupid = 1;
                    $data['parent_client_id'] = 0; // Ensure it's set to 0, not null
                }
            }
            
            // Fill the model with the filtered data
            $client->fill($data);
            
            // Save changes which will use BaseModel's save method to log to datalog
            $client->save();
            
            DB::commit();
            
            // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
            return response()->json($client, Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update client: ' . $e->getMessage(), ['exception' => $e, 'id' => $id]);
            return response()->json([
                'message' => 'Failed to update client',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified client
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $client = Client::find($id);
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
        }
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Delete the client - this will use BaseModel's delete method to log to datalog
            $client->delete();
            
            DB::commit();
            
            // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
            return response()->json(null, Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete client',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
