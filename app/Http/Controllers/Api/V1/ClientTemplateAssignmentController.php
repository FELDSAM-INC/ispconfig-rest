<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientTemplateAssignmentRequest;
use App\Models\Client;
use App\Models\ClientTemplate;
use App\Services\ClientTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Client Template Assignments (contract:
 * api/modules/client/template_assignments.yaml). The master template lives
 * in client.template_master (no pivot row, id null), additional templates
 * in the client_template_assigned pivot. After every assignment change the
 * client's effective limits are recomputed from master + additional
 * templates (ClientTemplateService::applyClientTemplates, the legacy
 * client_templates.inc.php merge).
 */
class ClientTemplateAssignmentController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected ClientTemplateService $service)
    {
    }

    /**
     * GET /clients/{client_id}/templates — the client's assignments
     * (master first) in the shared {data, meta} envelope. The rows are a
     * computed collection, so pagination/sorting run in memory.
     */
    public function index(Request $request, Client $client): JsonResponse
    {
        $sortable = ['client_template_id'];
        $sort = $request->query('sort', 'client_template_id');

        if (! is_string($sort) || ! in_array($sort, $sortable, true)) {
            throw new BadRequestHttpException(
                sprintf("Invalid sort column '%s'. Allowed: %s.", is_string($sort) ? $sort : gettype($sort), implode(', ', $sortable))
            );
        }

        $order = $request->query('order', 'asc');

        if (! is_string($order) || ! in_array(strtolower($order), ['asc', 'desc'], true)) {
            throw new BadRequestHttpException("Invalid order value. Allowed: 'asc', 'desc'.");
        }

        $limit = $this->positiveIntParam($request, 'limit', 25, min: 1, max: 100);
        $offset = $this->positiveIntParam($request, 'offset', 0, min: 0);

        $assignments = $this->service->assignmentsForClient($client)
            ->sortBy($sort, SORT_REGULAR, strtolower($order) === 'desc')
            ->values();

        return response()->json([
            'data' => $assignments->slice($offset, $limit)->values(),
            'meta' => [
                'total' => $assignments->count(),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * GET /clients/{client_id}/templates/{template_id} — a single
     * assignment; 404 problem when the template is not assigned.
     */
    public function show(Client $client, int $templateId): JsonResponse
    {
        return response()->json($this->service->assignmentForClient($client, $templateId));
    }

    /**
     * POST /clients/{client_id}/templates — 201 with the created
     * assignment; 409 for duplicates (service), 422 for unknown templates
     * (exists rule). Master assignments datalog the client row ('u'),
     * additional assignments the pivot row ('i'); the recomputed limits
     * datalog a client 'u' when they change.
     */
    public function store(StoreClientTemplateAssignmentRequest $request, Client $client): JsonResponse
    {
        $template = ClientTemplate::query()
            ->findOrFail((int) $request->validated()['client_template_id']);

        $assignment = DB::transaction(
            fn (): array => $this->service->assignTemplate($client, $template)
        );

        return response()->json($assignment, 201);
    }

    /**
     * DELETE /clients/{client_id}/templates/{template_id} — 204; 404
     * problem when the template is not assigned. Limits are recomputed.
     */
    public function destroy(Client $client, int $templateId): Response
    {
        DB::transaction(function () use ($client, $templateId): void {
            $this->service->unassignTemplate($client, $templateId);
        });

        return response()->noContent();
    }
}
