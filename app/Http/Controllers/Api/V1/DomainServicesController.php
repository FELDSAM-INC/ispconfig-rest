<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomainServicesRequest;
use App\Services\DomainServicesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainServicesController extends Controller
{
    public function __construct(private DomainServicesService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $rows = $this->service->inventory();
        $limit = (int) $request->query('limit', 100);
        $page = (int) $request->query('page', 1);

        return response()->json(['data' => array_slice($rows, ($page - 1) * $limit, $limit), 'meta' => ['total' => count($rows), 'page' => $page, 'limit' => $limit]]);
    }

    public function store(StoreDomainServicesRequest $request): JsonResponse
    {
        return response()->json($this->service->create($request->validated()), 201);
    }

    public function activate(Request $request): JsonResponse
    {
        $fields = $request->validate(['domain' => ['required', 'string', 'max:253'], 'service' => ['required', Rule::in(['dns', 'mail'])]]);
        $this->service->activate(strtolower($fields['domain']), $fields['service']);

        return response()->json(['domain' => $fields['domain'], 'service' => $fields['service']]);
    }
}
