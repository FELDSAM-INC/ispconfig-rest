<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientTemplateRequest;
use App\Http\Requests\UpdateClientTemplateRequest;
use App\Models\ClientTemplate;
use App\Services\ClientTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Client Templates (contract: api/modules/client/templates.yaml) — reusable
 * limit templates: 'm' (master) templates define a client's base limits,
 * 'a' (additional) templates stack on top. Updates re-apply the changed
 * limits to every assigned client (legacy client_template_edit.php::
 * onAfterUpdate — fixes spec 001 gap G14); deletion is blocked with 409
 * while the template is in use (fixed in-use check — spec 001 gap G2).
 */
class ClientTemplateController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected ClientTemplateService $service)
    {
    }

    /**
     * GET /clients/templates — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            ClientTemplate::query(),
            $request,
            sortable: ['template_id', 'template_name', 'template_type'],
            defaultSort: 'template_id',
            filters: [
                'template_type' => 'string',
                'template_name' => 'string',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /clients/templates/{template_id} — implicit binding 404s as
     * problem+json.
     */
    public function show(ClientTemplate $template): JsonResponse
    {
        return response()->json($template);
    }

    /**
     * POST /clients/templates — 201 with the created record; datalog action
     * 'i' (legacy sets db_history=no for this form — the surplus datalog row
     * is documented and harmless).
     */
    public function store(StoreClientTemplateRequest $request): JsonResponse
    {
        $template = new ClientTemplate($request->payload());

        DB::transaction(function () use ($template): void {
            $template->save();
        });

        return response()->json($template->refresh(), 201);
    }

    /**
     * PUT /clients/templates/{template_id} — 200 with the updated record;
     * datalog action 'u', then the changed limits are re-applied to every
     * client using the template (spec 001 gap G14).
     */
    public function update(UpdateClientTemplateRequest $request, ClientTemplate $template): JsonResponse
    {
        DB::transaction(function () use ($request, $template): void {
            $template->fill($request->payload());
            $template->save();

            $this->service->reapplyTemplate($template);
        });

        return response()->json($template->refresh());
    }

    /**
     * DELETE /clients/templates/{template_id} — 204; 409 while the template
     * is in use as a master (client.template_master) or additional
     * (client_template_assigned) template — the fixed legacy
     * client_template_del.php::onBeforeDelete check (spec 001 gap G2).
     */
    public function destroy(ClientTemplate $template): Response
    {
        if ($template->isInUse()) {
            throw new ConflictHttpException(
                'The template is assigned to one or more clients and cannot be deleted.'
            );
        }

        DB::transaction(function () use ($template): void {
            $template->delete();
        });

        return response()->noContent();
    }
}
