<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailContentFilterRequest;
use App\Http\Requests\UpdateMailContentFilterRequest;
use App\Models\MailContentFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mail Content Filters — Postfix header/body_checks rules (contract:
 * api/modules/mail/content-filters.yaml; legacy:
 * mail_content_filter.tform.php). server_id immutable on update.
 */
class MailContentFilterController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/content-filters — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailContentFilter::query(),
            $request,
            sortable: ['content_filter_id', 'type', 'pattern', 'action', 'server_id', 'active'],
            defaultSort: 'content_filter_id',
            filters: [
                'type' => 'string',
                'action' => 'string',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/content-filters/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailContentFilter $mailContentFilter): JsonResponse
    {
        return response()->json($mailContentFilter);
    }

    /**
     * POST /mail/content-filters — 201; datalog 'i'.
     */
    public function store(StoreMailContentFilterRequest $request): JsonResponse
    {
        $contentFilter = new MailContentFilter($request->payload());

        DB::transaction(function () use ($contentFilter): void {
            $contentFilter->save();
        });

        return response()->json($contentFilter->refresh(), 201);
    }

    /**
     * PUT /mail/content-filters/{id} — 200; datalog 'u' (suppressed when
     * nothing changed). server_id immutable.
     */
    public function update(UpdateMailContentFilterRequest $request, MailContentFilter $mailContentFilter): JsonResponse
    {
        $mailContentFilter->fill($request->payload());

        DB::transaction(function () use ($mailContentFilter): void {
            $mailContentFilter->save();
        });

        return response()->json($mailContentFilter->refresh());
    }

    /**
     * DELETE /mail/content-filters/{id} — 204; datalog 'd'.
     */
    public function destroy(MailContentFilter $mailContentFilter): Response
    {
        DB::transaction(function () use ($mailContentFilter): void {
            $mailContentFilter->delete();
        });

        return response()->noContent();
    }
}
