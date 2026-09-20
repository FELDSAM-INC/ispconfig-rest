<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDatabaseOperationRequest;
use App\Models\WebDatabase;
use App\Services\DatabaseOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseOperationController extends Controller
{
    public function __construct(private DatabaseOperationService $service) {}

    public function store(StoreDatabaseOperationRequest $request, WebDatabase $webDatabase): JsonResponse
    {
        return response()->json($this->service->create($webDatabase, $request->validated()), 201);
    }

    public function show(WebDatabase $webDatabase, string $operation): JsonResponse
    {
        return response()->json($this->service->present($this->service->find($webDatabase, $operation)));
    }

    public function download(WebDatabase $webDatabase, string $operation): StreamedResponse
    {
        $job = $this->service->find($webDatabase, $operation);
        abort_unless($job->action === 'export' && $job->status === 'complete', 409, 'The export is not ready.');

        return response()->streamDownload(function () use ($operation): void {
            foreach (DB::table('api_database_operation_chunks')->where('operation_id', $operation)->orderBy('sequence')->cursor() as $chunk) {
                echo base64_decode($chunk->content, true);
            }
        }, $webDatabase->database_name_full.'.sql.gz', ['Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store']);
    }
}
