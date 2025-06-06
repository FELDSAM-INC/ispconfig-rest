<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientReseller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientResellerController extends Controller
{
    /**
     * Display a listing of resellers
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Start with a base query for clients that are resellers
        // The ClientReseller model already has the reseller scope applied
        $query = ClientReseller::query();
        
        // Apply filters if provided
        if ($request->has('contact_name')) {
            $query->where('contact_name', 'like', '%' . $request->get('contact_name') . '%');
        }
        
        if ($request->has('company_name')) {
            $query->where('company_name', 'like', '%' . $request->get('company_name') . '%');
        }
        
        if ($request->has('email')) {
            $query->where('email', 'like', '%' . $request->get('email') . '%');
        }
        
        if ($request->has('customer_no')) {
            $query->where('customer_no', $request->get('customer_no'));
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
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'limit' => (int)$limit,
                'offset' => (int)$offset
            ]
        ]);
    }

    /**
     * Display the specified reseller
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $reseller = ClientReseller::find($id);
        
        if (!$reseller) {
            return response()->json(['error' => 'Reseller not found'], Response::HTTP_NOT_FOUND);
        }
        
        return response()->json(['data' => $reseller]);
    }

    /**
     * Store a newly created reseller
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Use validation rules from the model
        // For resellers, make certain fields required
        $rules = ClientReseller::$rules;
        $rules['company_name'] = 'required|string|max:64';
        $rules['contact_name'] = 'required|string|max:255';
        $rules['username'] = 'required|string|max:64|unique:client';
        $rules['password'] = 'required|string|min:8';
        $rules['email'] = 'required|email|max:255';
        $rules['street'] = 'required|string';
        $rules['zip'] = 'required|string|max:15';
        $rules['city'] = 'required|string|max:255';
        $rules['country'] = 'required|string|max:2';
        $rules['template_master'] = 'required|integer';
        
        // Reseller specific limits
        $rules['limit_client'] = 'required|integer';
        $rules['limit_web_domain'] = 'required|integer';
        $rules['limit_web_quota'] = 'required|integer';
        $rules['limit_web_user'] = 'required|integer';
        $rules['limit_mail_domain'] = 'required|integer';
        $rules['limit_mailbox'] = 'required|integer';
        $rules['limit_mail_quota'] = 'required|integer';
        $rules['limit_database'] = 'required|integer';
        $rules['limit_dns_domain'] = 'required|integer';
        $rules['limit_cron'] = 'required|integer';
        $rules['limit_shell_user'] = 'required|integer';
        $rules['limit_php_mode'] = 'required|string|in:php-fcgi,php-fpm,mod_php';
        $rules['limit_php_upload_max_filesize'] = 'required|integer';
        $rules['limit_php_post_max_size'] = 'required|integer';
        $rules['limit_php_max_execution_time'] = 'required|integer';
        $rules['limit_php_memory_limit'] = 'required|integer';
        $rules['limit_php_disable_functions'] = 'required|string';
        $rules['limit_php_mail_function'] = 'required|string|in:mail,smtp,sendmail,qmail';
        $rules['limit_php_mail_smtp_server'] = 'required|string';
        $rules['limit_php_mail_smtp_port'] = 'required|integer';
        $rules['limit_php_mail_smtp_ssl'] = 'required|string|in:none,ssl,tls';
        $rules['limit_php_mail_smtp_auth'] = 'required|string|in:none,plain,login,cram-md5';
        $rules['limit_php_mail_smtp_user'] = 'required|string';
        $rules['limit_php_mail_smtp_pass'] = 'required|string';
        
        $this->validate($request, $rules);
        
        // Ensure this is a reseller by checking limit_client
        if (!$request->has('limit_client') || 
            ($request->get('limit_client') <= 0 && $request->get('limit_client') != -1)) {
            return response()->json([
                'message' => 'A reseller must have limit_client > 0 or limit_client = -1'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Add system fields
            $data = array_merge($request->all(), [
                'sys_userid' => Auth::id() ?? 1,
                'sys_groupid' => Auth::user()->sys_groupid ?? 1,
                'sys_perm_user' => 'riud',
                'sys_perm_group' => 'riud',
                'sys_perm_other' => '',
                'active' => 'y'
            ]);
            
            $reseller = new ClientReseller($data);
            $reseller->save(); // This will use BaseModel's save method to log to datalog
            
            DB::commit();
            
            // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
            return response()->json([
                'data' => $reseller,
                'message' => 'Reseller created successfully'
            ], Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create reseller: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to create reseller',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified reseller
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $reseller = ClientReseller::find($id);
        
        if (!$reseller) {
            return response()->json(['error' => 'Reseller not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Use validation rules from the model with sometimes modifier
        $rules = ClientReseller::$rules;
        
        // Add unique rule for username if it's being updated
        if ($request->has('username')) {
            $rules['username'] = 'sometimes|string|max:64|unique:client,username,' . $id . ',client_id';
        }
        
        // Add sometimes modifier to all reseller-specific fields
        $rules['limit_client'] = 'sometimes|integer';
        $rules['limit_web_domain'] = 'sometimes|integer';
        $rules['limit_web_quota'] = 'sometimes|integer';
        $rules['limit_web_user'] = 'sometimes|integer';
        $rules['limit_mail_domain'] = 'sometimes|integer';
        $rules['limit_mailbox'] = 'sometimes|integer';
        $rules['limit_mail_quota'] = 'sometimes|integer';
        $rules['limit_database'] = 'sometimes|integer';
        $rules['limit_dns_domain'] = 'sometimes|integer';
        $rules['limit_cron'] = 'sometimes|integer';
        $rules['limit_shell_user'] = 'sometimes|integer';
        $rules['limit_php_mode'] = 'sometimes|string|in:php-fcgi,php-fpm,mod_php';
        $rules['limit_php_upload_max_filesize'] = 'sometimes|integer';
        $rules['limit_php_post_max_size'] = 'sometimes|integer';
        $rules['limit_php_max_execution_time'] = 'sometimes|integer';
        $rules['limit_php_memory_limit'] = 'sometimes|integer';
        $rules['limit_php_disable_functions'] = 'sometimes|string';
        $rules['limit_php_mail_function'] = 'sometimes|string|in:mail,smtp,sendmail,qmail';
        $rules['limit_php_mail_smtp_server'] = 'sometimes|string';
        $rules['limit_php_mail_smtp_port'] = 'sometimes|integer';
        $rules['limit_php_mail_smtp_ssl'] = 'sometimes|string|in:none,ssl,tls';
        $rules['limit_php_mail_smtp_auth'] = 'sometimes|string|in:none,plain,login,cram-md5';
        $rules['limit_php_mail_smtp_user'] = 'sometimes|string';
        $rules['limit_php_mail_smtp_pass'] = 'sometimes|string';
        
        $this->validate($request, $rules);
        
        // Ensure this remains a reseller by checking limit_client if it's being updated
        if ($request->has('limit_client') && 
            $request->get('limit_client') <= 0 && 
            $request->get('limit_client') != -1) {
            return response()->json([
                'message' => 'A reseller must have limit_client > 0 or limit_client = -1'
            ], Response::HTTP_BAD_REQUEST);
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
            $reseller->fill($data);
            
            // Save changes which will use BaseModel's save method to log to datalog
            $reseller->save();
            
            DB::commit();
            
            // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
            return response()->json([
                'data' => $reseller,
                'message' => 'Reseller updated successfully'
            ], Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update reseller: ' . $e->getMessage(), ['exception' => $e, 'id' => $id]);
            return response()->json([
                'message' => 'Failed to update reseller',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified reseller
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $reseller = ClientReseller::find($id);
        
        if (!$reseller) {
            return response()->json(['error' => 'Reseller not found'], Response::HTTP_NOT_FOUND);
        }
        
        try {
            // Begin transaction
            DB::beginTransaction();
            
            // Check if this reseller has any clients assigned
            $clientCount = $reseller->clients()->count();
            if ($clientCount > 0) {
                return response()->json([
                    'message' => 'Cannot delete reseller with assigned clients. Please reassign or delete the clients first.'
                ], Response::HTTP_CONFLICT);
            }
            
            // Delete the reseller - this will use BaseModel's delete method to log to datalog
            $reseller->delete();
            
            DB::commit();
            
            // Return 204 No Content for successful deletion
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete reseller: ' . $e->getMessage(), ['exception' => $e, 'id' => $id]);
            return response()->json([
                'message' => 'Failed to delete reseller',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
