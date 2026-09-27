<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWordPressJobRequest;
use App\Models\WebDomain;
use App\Services\WordPressService;
use Illuminate\Http\JsonResponse;

final class WordPressController extends Controller
{
    public function show(WebDomain $webDomain, WordPressService $wordpress): JsonResponse
    {
        return response()->json($wordpress->view($webDomain), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(StoreWordPressJobRequest $request, WebDomain $webDomain, WordPressService $wordpress): JsonResponse
    {
        return response()->json($wordpress->create($webDomain, $request->validated()), 201, ['Cache-Control' => 'private, no-store']);
    }

    public function job(WebDomain $webDomain, string $job, WordPressService $wordpress): JsonResponse
    {
        return response()->json($wordpress->job($webDomain, $job), 200, ['Cache-Control' => 'private, no-store']);
    }
}
