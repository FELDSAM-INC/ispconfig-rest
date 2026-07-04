<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDirectiveSnippetRequest;
use App\Http\Requests\UpdateDirectiveSnippetRequest;
use App\Models\DirectiveSnippet;
use App\Services\DirectiveSnippetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Directive Snippets (contract: api/modules/system/directive-snippets.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, legacy business
 * rules ((name, type) uniqueness, in-use guards, update_sites re-emission)
 * in DirectiveSnippetService, datalogging in BaseModel/DatalogService.
 * All conflicts — duplicate (name, type), deleting/deactivating/hiding an
 * in-use snippet — are 409 problem+json per the contract.
 */
class DirectiveSnippetController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected DirectiveSnippetService $service) {}

    /**
     * GET /system/directive-snippets — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');

        if ($type !== null && ! in_array($type, DirectiveSnippet::TYPES, true)) {
            throw new BadRequestHttpException(
                sprintf("Invalid type filter '%s'. Allowed: %s.", is_string($type) ? $type : gettype($type), implode(', ', DirectiveSnippet::TYPES))
            );
        }

        $result = $this->listQuery(
            DirectiveSnippet::query(),
            $request,
            sortable: ['directive_snippets_id', 'name', 'type', 'active', 'customer_viewable'],
            defaultSort: 'name',
            filters: [
                'type' => 'string',
                'active' => 'boolean',
            ],
            extra: ['type'],
        );

        return response()->json($result);
    }

    /**
     * GET /system/directive-snippets/{id} — implicit binding 404s as
     * problem+json.
     */
    public function show(DirectiveSnippet $directiveSnippet): JsonResponse
    {
        return response()->json($directiveSnippet);
    }

    /**
     * POST /system/directive-snippets — 201 with the created record;
     * datalog action 'i'; duplicate (name, type) is 409.
     */
    public function store(StoreDirectiveSnippetRequest $request): JsonResponse
    {
        $payload = $request->payload();

        if ($this->service->nameTypeTaken($payload['name'], $payload['type'])) {
            throw new ConflictHttpException(
                "A directive snippet named '{$payload['name']}' of type '{$payload['type']}' already exists."
            );
        }

        $snippet = new DirectiveSnippet($payload);

        DB::transaction(function () use ($snippet): void {
            $snippet->save();
        });

        return response()->json($snippet->refresh(), 201);
    }

    /**
     * PUT /system/directive-snippets/{id} — 200 with the updated record.
     *
     * 409 guards (legacy onBeforeUpdate order: active first, then
     * customer_viewable): deactivating or hiding an in-use snippet, or
     * changing (name, type) onto an existing pair. When the final state has
     * update_sites=y and active=y, every affected web_domain is re-emitted
     * as a forced full-record datalog update (legacy onAfterUpdate).
     */
    public function update(UpdateDirectiveSnippetRequest $request, DirectiveSnippet $directiveSnippet): JsonResponse
    {
        $payload = $request->payload();

        $newName = array_key_exists('name', $payload) ? $payload['name'] : $directiveSnippet->name;
        $newType = array_key_exists('type', $payload) ? $payload['type'] : $directiveSnippet->type;

        if ($this->service->nameTypeTaken((string) $newName, (string) $newType, (int) $directiveSnippet->getKey())) {
            throw new ConflictHttpException(
                "A directive snippet named '{$newName}' of type '{$newType}' already exists."
            );
        }

        $wasActive = (bool) $directiveSnippet->active;
        $wasViewable = (bool) $directiveSnippet->customer_viewable;

        $directiveSnippet->fill($payload);

        if ($wasActive && ! $directiveSnippet->active && $this->service->isInUse($directiveSnippet)) {
            throw new ConflictHttpException('The snippet cannot be deactivated while websites use it.');
        } elseif ($wasViewable && ! $directiveSnippet->customer_viewable && $this->service->isInUse($directiveSnippet)) {
            throw new ConflictHttpException('The snippet cannot be hidden from customers while websites use it.');
        }

        DB::transaction(function () use ($directiveSnippet): void {
            $directiveSnippet->save();

            if ($directiveSnippet->update_sites && $directiveSnippet->active) {
                $this->service->emitSiteUpdates($directiveSnippet);
            }
        });

        return response()->json($directiveSnippet->refresh());
    }

    /**
     * DELETE /system/directive-snippets/{id} — 204; datalog action 'd'.
     * Deleting an in-use snippet is 409 (legacy onBeforeDelete).
     */
    public function destroy(DirectiveSnippet $directiveSnippet): Response
    {
        if ($this->service->isInUse($directiveSnippet)) {
            throw new ConflictHttpException('The snippet cannot be deleted while websites use it.');
        }

        DB::transaction(function () use ($directiveSnippet): void {
            $directiveSnippet->delete();
        });

        return response()->noContent();
    }
}
