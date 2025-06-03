<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientDomain;
use App\Models\SysGroup; // Added SysGroup model
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB; // Added for DB facade if needed for SysGroup query, or use Eloquent.

class ClientDomainController extends Controller
{
    /**
     * Display a listing of client domains
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Apply filters, sorting, and pagination based on request parameters
        $query = ClientDomain::query();
        
        // Apply client_id filter if provided
        if ($request->has('client_id')) {
            $clientId = $request->get('client_id');
            // Find sys_groupids associated with the client_id
            $groupIds = SysGroup::where('client_id', $clientId)->pluck('groupid')->toArray();
            if (!empty($groupIds)) {
                $query->whereIn('sys_groupid', $groupIds);
            } else {
                // If client has no groups, or client_id is invalid, return no domains for this filter
                $query->whereRaw('1 = 0'); 
            }
        }
        
        // Apply other filters if provided
        if ($request->has('filter')) {
            $filters = $request->get('filter');
            foreach ($filters as $field => $value) {
                $query->where($field, $value);
            }
        }
        
        // Apply sorting
        $sort = $request->get('sort', 'domain_id');
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
     * Display the specified client domain
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $domain = ClientDomain::find($id);
        
        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], Response::HTTP_NOT_FOUND);
        }
        
        return response()->json($domain);
    }

    /**
     * Store a newly created client domain
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validate request data
        $this->validate($request, [
            'client_id' => 'required|integer|exists:client,client_id', // Used to derive sys_groupid
            'domain' => 'required|string|max:255|unique:domain',
            // sys_* fields can be optional in request, will be defaulted
            'sys_userid' => 'sometimes|integer',
            'sys_perm_user' => 'sometimes|string|max:5',
            'sys_perm_group' => 'sometimes|string|max:5', // Will be overridden to 'ru' for new domains
            'sys_perm_other' => 'sometimes|string|max:5',
        ]);
        
        $clientId = $request->input('client_id');
        $sysGroup = SysGroup::where('client_id', $clientId)->first();

        if (!$sysGroup) {
            // This case should ideally be caught by 'exists:client,client_id' validation
            // or if a client might exist without a group, which would be unusual.
            return response()->json(['error' => 'Associated client group not found.'], Response::HTTP_BAD_REQUEST);
        }

        // Extract only 'domain' as per schema for direct attributes
        $domainData = $request->only(['domain']);

        $domain = new ClientDomain($domainData); // This will only fill 'domain'
        
        // Set system fields based on schema and logic
        // sys_groupid is determined from client_id
        $domain->sys_groupid = $sysGroup->groupid;

        // sys_userid: from request attribute set by ApiAuthMiddleware, or fallback to request input, then 1.
        $ispconfigUserId = $request->attributes->get('ispconfig_user_id', 1); // Default to 1 if attribute not set
        $domain->sys_userid = $request->input('sys_userid', $ispconfigUserId); 

        // sys_perm_user: from request if provided, else default
        $domain->sys_perm_user = $request->input('sys_perm_user', 'riud');
        
        // sys_perm_group: always 'ru' for new domains, as per ISPConfig's onAfterInsert logic for domains
        $domain->sys_perm_group = 'ru'; 

        // sys_perm_other: from request if provided, else default
        $domain->sys_perm_other = $request->input('sys_perm_other', '');

        // 'server_id' and 'active' are not set here as they are not in ClientDomain.yaml for this endpoint.
        // They will be handled by ISPConfig's backend processing of the datalog entry for the 'domain' table.
        
        $domain->save();
        
        // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
        return response()->json($domain, Response::HTTP_ACCEPTED);
    }

    /**
     * Update the specified client domain
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $domain = ClientDomain::find($id);
        
        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Validate request data
        $this->validate($request, [
            'client_id' => 'integer|exists:client,client_id',
            'domain' => 'string|max:255|unique:domain,domain,' . $id . ',domain_id',
            'domain_option' => 'nullable|string'
        ]);
        
        $domain->fill($request->all());
        $domain->save();
        
        // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
        return response()->json($domain, Response::HTTP_ACCEPTED);
    }

    /**
     * Remove the specified client domain
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $domain = ClientDomain::find($id);
        
        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], Response::HTTP_NOT_FOUND);
        }
        
        $domain->delete();
        
        // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
        return response()->json(null, Response::HTTP_ACCEPTED);
    }
}
