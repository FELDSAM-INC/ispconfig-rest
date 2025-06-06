<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientTemplate;
use App\Services\DatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class ClientTemplateController extends Controller
{
    /**
     * Display a listing of client templates
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Apply filters, sorting, and pagination based on request parameters
        $query = ClientTemplate::query();
        
        // Apply filters if provided
        if ($request->has('filter')) {
            $filters = $request->get('filter');
            foreach ($filters as $field => $value) {
                $query->where($field, 'like', "%{$value}%");
            }
        }
        
        // Apply search
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('template_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        
        // Apply sorting
        $sort = $request->get('sort', 'template_id');
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
     * Store a newly created client template
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Use validation rules from the model
        // For store method, we need to make sure template_name is required
        $rules = ClientTemplate::$rules;
        $rules['template_name'] = 'required|string|max:255';
        $rules['template_type'] = 'required|string|in:m,a'; // m = main template, a = additional template
        
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Add system fields
            $data = array_merge($request->all(), [
                'sys_userid' => Auth::id() ?? 1,
                'sys_groupid' => Auth::user()->sys_groupid ?? 1,
                'sys_perm_user' => 'riud',
                'sys_perm_group' => 'riud',
                'sys_perm_other' => ''
            ]);
            
            $template = new ClientTemplate($data);
            $template->save(); // This will use the BaseModel's save method which logs to datalog
            
            return response()->json($template, Response::HTTP_CREATED);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create client template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified client template
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $template = ClientTemplate::findOrFail($id);
        return response()->json($template);
    }

    /**
     * Update the specified client template
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $template = ClientTemplate::findOrFail($id);
        
        // Log the input data for debugging
        \Log::info('Update client template input data:', ['input' => $request->all(), 'id' => $id]);
        
        // Use validation rules from the model
        $validator = Validator::make($request->all(), ClientTemplate::$rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Fill the model with the new data, excluding system fields
            $data = $request->except([
                'sys_userid', 'sys_groupid', 'sys_perm_user', 
                'sys_perm_group', 'sys_perm_other'
            ]);
            
            // Fill the model with the filtered data
            $template->fill($data);
            
            // Save changes which will use BaseModel's save method to log to datalog
            $template->save();
            
            DB::commit();
            
            // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
            return response()->json($template, Response::HTTP_ACCEPTED);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update client template: ' . $e->getMessage(), ['exception' => $e, 'id' => $id]);
            return response()->json([
                'message' => 'Failed to update client template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified client template
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $template = ClientTemplate::findOrFail($id);
        
        try {
            // Check if template is in use
            if ($template->clients()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete template that is in use by clients'
                ], Response::HTTP_CONFLICT);
            }
            
            // Delete the template - this will use BaseModel's delete method to log to datalog
            $template->delete();
            
            return response()->json(null, Response::HTTP_NO_CONTENT);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete client template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    

}
