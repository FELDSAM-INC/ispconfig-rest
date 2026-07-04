<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailAccessRequest;
use App\Http\Requests\UpdateMailAccessRequest;
use App\Models\MailAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Mail Access Rules — server-level black/whitelist entries on mail_access
 * (contract: api/modules/mail/access-rules.yaml; legacy:
 * mail_blacklist.tform.php + mail_whitelist.tform.php).
 *
 * The (server_id, source, type) UNIQUE key is surfaced as 409 on create AND
 * update (contract). Rspamd server effect: these rules become global
 * white/blacklist maps (spec 005 Parity #14 — documentation only, the
 * server plugin consumes the datalog rows).
 */
class MailAccessController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/access-rules — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailAccess::query(),
            $request,
            sortable: ['access_id', 'source', 'access', 'type', 'server_id', 'active'],
            defaultSort: 'source',
            filters: [
                'source' => 'wildcard',
                'type' => 'string',
                'access' => 'string',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/access-rules/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailAccess $mailAccess): JsonResponse
    {
        return response()->json($mailAccess);
    }

    /**
     * POST /mail/access-rules — 201; datalog 'i'; duplicate source+type per
     * server => 409.
     */
    public function store(StoreMailAccessRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $accessRule = new MailAccess($payload);
        $attributes = $accessRule->getAttributes();

        $this->guardUniqueSourceType(
            (string) $attributes['source'],
            (string) $attributes['type'],
            (int) $attributes['server_id']
        );

        DB::transaction(function () use ($accessRule): void {
            $accessRule->save();
        });

        return response()->json($accessRule->refresh(), 201);
    }

    /**
     * PUT /mail/access-rules/{id} — 200; datalog 'u' (suppressed when
     * nothing changed). server_id immutable; source+type stays unique.
     */
    public function update(UpdateMailAccessRequest $request, MailAccess $mailAccess): JsonResponse
    {
        $mailAccess->fill($request->payload());

        $attributes = $mailAccess->getAttributes();

        $this->guardUniqueSourceType(
            (string) $attributes['source'],
            (string) $attributes['type'],
            (int) $attributes['server_id'],
            (int) $mailAccess->getKey()
        );

        DB::transaction(function () use ($mailAccess): void {
            $mailAccess->save();
        });

        return response()->json($mailAccess->refresh());
    }

    /**
     * DELETE /mail/access-rules/{id} — 204; datalog 'd'.
     */
    public function destroy(MailAccess $mailAccess): Response
    {
        DB::transaction(function () use ($mailAccess): void {
            $mailAccess->delete();
        });

        return response()->noContent();
    }

    /**
     * The mail_access (server_id, source, type) UNIQUE key -> 409 (contract).
     */
    protected function guardUniqueSourceType(string $source, string $type, int $serverId, ?int $exceptId = null): void
    {
        $query = MailAccess::query()
            ->where('source', $source)
            ->where('type', $type)
            ->where('server_id', $serverId);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw new ConflictHttpException(
                "An access rule for '{$source}' with type '{$type}' already exists on server {$serverId}."
            );
        }
    }
}
