<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        // Validate request data
        $this->validate($request, [
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'username' => 'required|string|max:255|unique:client',
            'password' => 'required|string|min:8',
        ]);
        
        $client = new Client($request->all());
        $client->save();
        
        // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
        return response()->json($client, Response::HTTP_ACCEPTED);
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
        
        // Validate request data
        $this->validate($request, [
            'company_name' => 'string|max:255',
            'contact_name' => 'string|max:255',
            'email' => 'email|max:255',
            'username' => 'string|max:255|unique:client,username,' . $id . ',client_id',
            'password' => 'string|min:8',
        ]);
        
        $client->fill($request->all());
        $client->save();
        
        // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
        return response()->json($client, Response::HTTP_ACCEPTED);
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
        
        $client->delete();
        
        // Return 202 Accepted for pending changes as per ISPConfig datalog pattern
        return response()->json(null, Response::HTTP_ACCEPTED);
    }
}
