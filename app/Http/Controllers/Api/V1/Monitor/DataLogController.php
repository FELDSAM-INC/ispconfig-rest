<?php

namespace App\Http\Controllers\Api\V1\Monitor;

use App\Http\Controllers\Controller;
use App\Services\DatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DataLogController extends Controller
{
    /**
     * @var DatalogService
     */
    protected $datalogService;

    /**
     * DataLogController constructor.
     *
     * @param DatalogService $datalogService
     */
    public function __construct(DatalogService $datalogService)
    {
        $this->datalogService = $datalogService;
    }

    /**
     * List data logs with filtering options
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Build query with filters
        $query = DB::table('sys_datalog');
        
        // Apply server filter
        if ($request->has('server_id')) {
            $query->where('server_id', $request->get('server_id'));
        }
        
        // Apply table filter
        if ($request->has('dbtable')) {
            $query->where('dbtable', $request->get('dbtable'));
        }
        
        // Apply action filter
        if ($request->has('action')) {
            $query->where('action', strtolower($request->get('action')));
        }
        
        // Apply status filter
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }
        
        // Apply date range filters
        if ($request->has('start_date')) {
            $query->where('tstamp', '>=', $request->get('start_date'));
        }
        
        if ($request->has('end_date')) {
            $query->where('tstamp', '<=', $request->get('end_date'));
        }
        
        // Apply unprocessed_only filter
        if ($request->has('unprocessed_only') && $request->get('unprocessed_only') === 'true') {
            // Get the server's last update timestamp
            $serverUpdated = DB::table('server')->where('server_id', $request->get('server_id', 0))
                ->value('updated');
                
            if ($serverUpdated) {
                $query->where('tstamp', '>', $serverUpdated);
            }
        }
        
        // Apply sorting
        $sort = $request->get('sort', 'datalog_id');
        $order = $request->get('order', 'desc');
        $query->orderBy($sort, $order);
        
        // Apply pagination
        $limit = (int)$request->get('limit', 25);
        $offset = (int)$request->get('offset', 0);
        
        $total = $query->count();
        $items = $query->skip($offset)->take($limit)->get();
        
        // Process data to unserialize if needed
        $items = $items->map(function ($item) {
            if (!empty($item->data)) {
                try {
                    $item->data = unserialize($item->data);
                } catch (\Exception $e) {
                    // Keep as is if unserialize fails
                }
            }
            return $item;
        });
        
        return response()->json([
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]
        ]);
    }

    /**
     * Get details of a specific datalog entry
     *
     * @param int $datalog_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($datalog_id)
    {
        $datalog = DB::table('sys_datalog')->where('datalog_id', $datalog_id)->first();
        
        if (!$datalog) {
            return response()->json(['error' => 'Data log not found'], Response::HTTP_NOT_FOUND);
        }
        
        // If data is serialized, unserialize it for better readability
        if (!empty($datalog->data)) {
            try {
                $datalog->data = unserialize($datalog->data);
            } catch (\Exception $e) {
                // Keep as is if unserialize fails
            }
        }
        
        return response()->json($datalog);
    }
}
