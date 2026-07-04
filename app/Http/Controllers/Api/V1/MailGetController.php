<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailGetRequest;
use App\Http\Requests\UpdateMailGetRequest;
use App\Models\MailGet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mail Fetchmail — mail_get configurations (contract:
 * api/modules/mail/fetchmail.yaml; legacy: mail_get.tform.php).
 *
 * source_password is write-only: stored as legacy stores it (plaintext
 * column), never echoed, only re-set when a non-empty value is provided.
 */
class MailGetController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/fetchmail — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailGet::query(),
            $request,
            sortable: ['mailget_id', 'type', 'source_server', 'destination', 'active'],
            defaultSort: 'mailget_id',
            filters: [
                'type' => 'string',
                'source_server' => 'wildcard',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/fetchmail/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailGet $mailGet): JsonResponse
    {
        return response()->json($mailGet);
    }

    /**
     * POST /mail/fetchmail — 201; datalog 'i'.
     */
    public function store(StoreMailGetRequest $request): JsonResponse
    {
        $mailGet = new MailGet($request->payload());

        DB::transaction(function () use ($mailGet): void {
            $mailGet->save();
        });

        return response()->json($mailGet->refresh(), 201);
    }

    /**
     * PUT /mail/fetchmail/{id} — 200; datalog 'u' (suppressed when nothing
     * changed). server_id immutable, password only re-set when provided.
     */
    public function update(UpdateMailGetRequest $request, MailGet $mailGet): JsonResponse
    {
        $mailGet->fill($request->payload());

        DB::transaction(function () use ($mailGet): void {
            $mailGet->save();
        });

        return response()->json($mailGet->refresh());
    }

    /**
     * DELETE /mail/fetchmail/{id} — 204; datalog 'd'.
     */
    public function destroy(MailGet $mailGet): Response
    {
        DB::transaction(function () use ($mailGet): void {
            $mailGet->delete();
        });

        return response()->noContent();
    }
}
