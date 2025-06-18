<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MailDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MailDomainController extends Controller
{
    /**
     * Display a listing of the mail domains.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = MailDomain::query();

        // Apply filters
        if ($request->has('domain')) {
            $query->where('domain', 'like', str_replace('*', '%', $request->input('domain')));
        }

        if ($request->has('active')) {
            $query->where('active', $request->input('active'));
        }

        if ($request->has('local_delivery')) {
            $query->where('local_delivery', $request->input('local_delivery'));
        }

        if ($request->has('dkim')) {
            $query->where('dkim', $request->input('dkim'));
        }

        // Apply sorting
        $sortField = $request->input('sort', 'domain');
        $sortOrder = 'asc';
        
        if (str_starts_with($sortField, '-')) {
            $sortField = substr($sortField, 1);
            $sortOrder = 'desc';
        }
        
        $query->orderBy($sortField, $sortOrder);

        // Paginate results
        $perPage = min($request->input('per_page', 20), 100);
        $domains = $query->paginate($perPage);

        return response()->json([
            'data' => $domains->items(),
            'pagination' => [
                'total' => $domains->total(),
                'per_page' => $domains->perPage(),
                'current_page' => $domains->currentPage(),
                'last_page' => $domains->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created mail domain in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), MailDomain::getValidationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // Custom validation for DKIM private key
        if ($request->input('dkim') === 'y' && !MailDomain::validateDkimPrivateKey($request->input('dkim_private'))) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['dkim_private' => ['Invalid DKIM private key']]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::beginTransaction();

            $domain = new MailDomain($request->all());
            $domain->save();

            DB::commit();

            return response()->json($domain, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create mail domain: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to create mail domain',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified mail domain.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $domain = MailDomain::find($id);

        if (!$domain) {
            return response()->json([
                'message' => 'Mail domain not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($domain);
    }

    /**
     * Update the specified mail domain in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $domain = MailDomain::find($id);

        if (!$domain) {
            return response()->json([
                'message' => 'Mail domain not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), MailDomain::getValidationRules($id));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // Custom validation for DKIM private key if present in request
        if ($request->has('dkim') && $request->input('dkim') === 'y' && 
            $request->has('dkim_private') && !MailDomain::validateDkimPrivateKey($request->input('dkim_private'))) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['dkim_private' => ['Invalid DKIM private key']]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::beginTransaction();

            $domain->fill($request->all());
            $domain->save();

            DB::commit();

            return response()->json($domain);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update mail domain: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to update mail domain',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified mail domain from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $domain = MailDomain::find($id);

        if (!$domain) {
            return response()->json([
                'message' => 'Mail domain not found'
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            DB::beginTransaction();
            $domain->delete();
            DB::commit();

            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete mail domain: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to delete mail domain',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
