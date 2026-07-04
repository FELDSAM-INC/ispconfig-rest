<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailTransportRequest;
use App\Http\Requests\UpdateMailTransportRequest;
use App\Models\MailTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Mail Transports — Postfix transport-map entries (contract:
 * api/modules/mail/transports.yaml; legacy: mail_transport_edit.php).
 *
 * The (server_id, domain) UNIQUE key is surfaced as 409 (contract); domain
 * and server_id are immutable on update (legacy reverts server changes).
 */
class MailTransportController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/transports — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailTransport::query(),
            $request,
            sortable: ['transport_id', 'domain', 'transport', 'sort_order', 'server_id', 'active'],
            defaultSort: 'domain',
            filters: [
                'domain' => 'wildcard',
                'transport' => 'wildcard',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/transports/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailTransport $mailTransport): JsonResponse
    {
        return response()->json($mailTransport);
    }

    /**
     * POST /mail/transports — 201; datalog 'i'; duplicate domain per
     * server => 409.
     */
    public function store(StoreMailTransportRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $this->guardUniqueDomain((string) $payload['domain'], (int) $payload['server_id']);

        $transport = new MailTransport($payload);

        DB::transaction(function () use ($transport): void {
            $transport->save();
        });

        return response()->json($transport->refresh(), 201);
    }

    /**
     * PUT /mail/transports/{id} — 200; datalog 'u' (suppressed when nothing
     * changed). domain/server_id immutable.
     */
    public function update(UpdateMailTransportRequest $request, MailTransport $mailTransport): JsonResponse
    {
        $mailTransport->fill($request->payload());

        DB::transaction(function () use ($mailTransport): void {
            $mailTransport->save();
        });

        return response()->json($mailTransport->refresh());
    }

    /**
     * DELETE /mail/transports/{id} — 204; datalog 'd'.
     */
    public function destroy(MailTransport $mailTransport): Response
    {
        DB::transaction(function () use ($mailTransport): void {
            $mailTransport->delete();
        });

        return response()->noContent();
    }

    /**
     * The mail_transport (server_id, domain) UNIQUE key -> 409 (contract;
     * legacy validate_mail_transport::validate_domain).
     */
    protected function guardUniqueDomain(string $domain, int $serverId): void
    {
        $duplicate = MailTransport::query()
            ->where('domain', $domain)
            ->where('server_id', $serverId)
            ->exists();

        if ($duplicate) {
            throw new ConflictHttpException(
                "A transport for domain '{$domain}' already exists on server {$serverId}."
            );
        }
    }
}
