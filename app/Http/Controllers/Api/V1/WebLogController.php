<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ReadsAccountQuery;
use App\Http\Controllers\Controller;
use App\Models\WebDomain;
use App\Services\WebLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebLogController extends Controller
{
    use ReadsAccountQuery;

    public function show(Request $request, WebDomain $webDomain, string $kind, WebLogService $logs): JsonResponse
    {
        $this->rejectUnknownParameters($request, ['lines', 'before']);
        $query = validator($request->query(), ['lines' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'before' => ['sometimes', 'string', 'max:4096']])->validate();
        $result = $logs->read($webDomain, $kind, (int) ($query['lines'] ?? 200), $query['before'] ?? null);

        return response()->json($result, $result['state'] === 'pending' ? 202 : 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
