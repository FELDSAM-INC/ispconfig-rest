<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DnsTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DnsTemplateController extends Controller
{
    /**
     * Display a listing of the DNS templates.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = DnsTemplate::query();

        // Apply filters
        if ($request->has('name')) {
            $query->where('name', 'like', str_replace('*', '%', $request->input('name')));
        }

        if ($request->has('visible')) {
            $query->where('visible', $request->input('visible'));
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
        $templates = $query->paginate($perPage);

        return response()->json([
            'data' => $templates->items(),
            'pagination' => [
                'total' => $templates->total(),
                'per_page' => $templates->perPage(),
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created DNS template in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), DnsTemplate::getValidationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // Custom validation for fields
        $customErrors = DnsTemplate::getCustomValidationRules($request->all());
        if (!empty($customErrors)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $customErrors
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::beginTransaction();

            $template = new DnsTemplate($request->all());
            $template->save();

            DB::commit();

            return response()->json($template, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create DNS template: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to create DNS template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified DNS template.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $template = DnsTemplate::find($id);

        if (!$template) {
            return response()->json([
                'message' => 'DNS template not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($template);
    }

    /**
     * Update the specified DNS template in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $template = DnsTemplate::find($id);

        if (!$template) {
            return response()->json([
                'message' => 'DNS template not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), DnsTemplate::getValidationRules($id));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // Custom validation for fields if present in request
        if ($request->has('fields')) {
            $customErrors = DnsTemplate::getCustomValidationRules($request->all());
            if (!empty($customErrors)) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $customErrors
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            DB::beginTransaction();

            $template->fill($request->all());
            $template->save();

            DB::commit();

            return response()->json($template);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update DNS template: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to update DNS template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified DNS template from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $template = DnsTemplate::find($id);

        if (!$template) {
            return response()->json([
                'message' => 'DNS template not found'
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            DB::beginTransaction();
            $template->delete();
            DB::commit();

            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete DNS template: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to delete DNS template',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
