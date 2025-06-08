<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DnsRecord;
use App\Models\DnsSoa;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DnsRecordController extends Controller
{
    /**
     * Display a listing of the DNS records.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = DnsRecord::query();

        // Apply filters
        if ($request->has('zone')) {
            $query->where('zone', $request->input('zone'));
        }

        if ($request->has('type')) {
            $query->where('type', strtoupper($request->input('type')));
        }

        if ($request->has('name')) {
            $query->where('name', 'like', str_replace('*', '%', $request->input('name')));
        }

        if ($request->has('data')) {
            $query->where('data', 'like', '%' . $request->input('data') . '%');
        }

        if ($request->has('active')) {
            $query->where('active', $request->input('active'));
        }

        // Apply sorting
        $sortField = $request->input('sort', 'name');
        $sortOrder = 'asc';
        
        if (str_starts_with($sortField, '-')) {
            $sortField = substr($sortField, 1);
            $sortOrder = 'desc';
        }
        
        $query->orderBy($sortField, $sortOrder);

        // Paginate results
        $perPage = min($request->input('per_page', 20), 100);
        $records = $query->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'pagination' => [
                'total' => $records->total(),
                'per_page' => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created DNS record in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(), 
            DnsRecord::getValidationRules($request->input('type'), null, false)
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Verify zone exists
        $zone = DnsSoa::find($request->input('zone'));
        if (!$zone) {
            return response()->json([
                'message' => 'Zone not found',
                'error' => 'The specified zone does not exist'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            DB::beginTransaction();

            $record = new DnsRecord($request->all());
            $record->server_id = $zone->server_id; // Inherit server_id from zone
            $record->save();

            DB::commit();

            return response()->json($record, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create DNS record: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to create DNS record',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified DNS record.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $record = DnsRecord::find($id);

        if (!$record) {
            return response()->json([
                'message' => 'DNS record not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($record);
    }

    /**
     * Update the specified DNS record in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $record = DnsRecord::find($id);

        if (!$record) {
            return response()->json([
                'message' => 'DNS record not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $recordType = $request->input('type', $record->type);
        
        $validator = Validator::make(
            $request->all(),
            DnsRecord::getValidationRules($recordType, $id, true)
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // If zone is being updated, verify it exists
        if ($request->has('zone') && $request->input('zone') != $record->zone) {
            $zone = DnsSoa::find($request->input('zone'));
            if (!$zone) {
                return response()->json([
                    'message' => 'Zone not found',
                    'error' => 'The specified zone does not exist'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            DB::beginTransaction();

            $record->fill($request->all());
            
            // If zone changed, update server_id to match new zone
            if ($request->has('zone') && $request->input('zone') != $record->zone) {
                $record->server_id = $zone->server_id;
            }
            
            $record->save();

            DB::commit();

            return response()->json($record);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update DNS record: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to update DNS record',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified DNS record from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $record = DnsRecord::find($id);

        if (!$record) {
            return response()->json([
                'message' => 'DNS record not found'
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            DB::beginTransaction();
            $record->delete();
            DB::commit();

            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete DNS record: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to delete DNS record',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
