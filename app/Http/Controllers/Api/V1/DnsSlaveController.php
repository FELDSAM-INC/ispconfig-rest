<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DnsSlave;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DnsSlaveController extends Controller
{
    /**
     * Display a listing of the DNS slave zones.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = DnsSlave::query();

        // Apply filters
        if ($request->has('origin')) {
            $query->where('origin', 'like', str_replace('*', '%', $request->input('origin')));
        }

        if ($request->has('active')) {
            $query->where('active', $request->input('active'));
        }

        if ($request->has('server_id')) {
            $query->where('server_id', $request->input('server_id'));
        }

        // Apply sorting
        $sortField = $request->input('sort', 'origin');
        $sortOrder = 'asc';
        
        if (str_starts_with($sortField, '-')) {
            $sortField = substr($sortField, 1);
            $sortOrder = 'desc';
        }
        
        $query->orderBy($sortField, $sortOrder);

        // Paginate results
        $perPage = min($request->input('per_page', 20), 100);
        $zones = $query->paginate($perPage);

        return response()->json([
            'data' => $zones->items(),
            'pagination' => [
                'total' => $zones->total(),
                'per_page' => $zones->perPage(),
                'current_page' => $zones->currentPage(),
                'last_page' => $zones->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created DNS slave zone in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), DnsSlave::getValidationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::beginTransaction();

            $zone = new DnsSlave($request->all());
            $zone->save();

            DB::commit();

            return response()->json($zone, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create DNS slave zone: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to create DNS slave zone',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified DNS slave zone.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $zone = DnsSlave::find($id);

        if (!$zone) {
            return response()->json([
                'message' => 'DNS slave zone not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($zone);
    }

    /**
     * Update the specified DNS slave zone in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $zone = DnsSlave::find($id);

        if (!$zone) {
            return response()->json([
                'message' => 'DNS slave zone not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), DnsSlave::getValidationRules($id));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::beginTransaction();

            $zone->fill($request->all());
            $zone->save();

            DB::commit();

            return response()->json($zone);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update DNS slave zone: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to update DNS slave zone',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified DNS slave zone from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $zone = DnsSlave::find($id);

        if (!$zone) {
            return response()->json([
                'message' => 'DNS slave zone not found'
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            DB::beginTransaction();
            $zone->delete();
            DB::commit();

            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete DNS slave zone: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to delete DNS slave zone',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
