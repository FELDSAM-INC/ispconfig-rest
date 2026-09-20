<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDatabaseOperationChunkRequest;
use App\Http\Requests\StoreDatabaseOperationRequest;
use App\Models\WebDatabase;
use App\Services\DatabaseOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
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

    public function chunk(StoreDatabaseOperationChunkRequest $request, WebDatabase $webDatabase, string $operation, int $sequence): JsonResponse
    {
        return response()->json($this->service->chunk($webDatabase, $operation, $sequence, $request->validated('dump_base64')));
    }

    public function finish(WebDatabase $webDatabase, string $operation): JsonResponse
    {
        return response()->json($this->service->finish($webDatabase, $operation));
    }

    public function destroy(WebDatabase $webDatabase, string $operation): Response
    {
        $this->service->cancel($webDatabase, $operation);

        return response()->noContent();
    }

    public function download(WebDatabase $webDatabase, string $operation): StreamedResponse
    {
        $job = $this->service->find($webDatabase, $operation);
        abort_unless($job->action === 'export' && $job->status === 'complete', 409, 'The export is not ready.');

        @set_time_limit(0);
        $headers = ['Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store', 'X-Accel-Buffering' => 'no'];
        if ($job->download_bytes !== null) {
            $headers['Content-Length'] = (string) $job->download_bytes;
        }

        return response()->streamDownload(function () use ($operation): void {
            foreach (DB::table('api_database_operation_chunks')->where('operation_id', $operation)->lazyById(4, 'sequence') as $chunk) {
                echo base64_decode($chunk->content, true);
            }
        }, $webDatabase->database_name_full.'.sql.gz', $headers);
    }
}
