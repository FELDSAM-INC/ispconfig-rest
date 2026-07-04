<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailRelayRecipientRequest;
use App\Http\Requests\UpdateMailRelayRecipientRequest;
use App\Models\MailRelayRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mail Relay Recipients (contract: api/modules/mail/relay-recipients.yaml;
 * legacy: mail_relay_recipient.tform.php). access defaults to 'OK' and
 * active to y server-side (C-4); source/server_id immutable on update.
 */
class MailRelayRecipientController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/relay-recipients — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailRelayRecipient::query(),
            $request,
            sortable: ['relay_recipient_id', 'source', 'access', 'server_id', 'active'],
            defaultSort: 'source',
            filters: [
                'source' => 'wildcard',
                'access' => 'string',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/relay-recipients/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailRelayRecipient $mailRelayRecipient): JsonResponse
    {
        return response()->json($mailRelayRecipient);
    }

    /**
     * POST /mail/relay-recipients — 201; datalog 'i'.
     */
    public function store(StoreMailRelayRecipientRequest $request): JsonResponse
    {
        $relayRecipient = new MailRelayRecipient($request->payload());

        DB::transaction(function () use ($relayRecipient): void {
            $relayRecipient->save();
        });

        return response()->json($relayRecipient->refresh(), 201);
    }

    /**
     * PUT /mail/relay-recipients/{id} — 200; datalog 'u' (suppressed when
     * nothing changed). source/server_id immutable.
     */
    public function update(UpdateMailRelayRecipientRequest $request, MailRelayRecipient $mailRelayRecipient): JsonResponse
    {
        $mailRelayRecipient->fill($request->payload());

        DB::transaction(function () use ($mailRelayRecipient): void {
            $mailRelayRecipient->save();
        });

        return response()->json($mailRelayRecipient->refresh());
    }

    /**
     * DELETE /mail/relay-recipients/{id} — 204; datalog 'd'.
     */
    public function destroy(MailRelayRecipient $mailRelayRecipient): Response
    {
        DB::transaction(function () use ($mailRelayRecipient): void {
            $mailRelayRecipient->delete();
        });

        return response()->noContent();
    }
}
