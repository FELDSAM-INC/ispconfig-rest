<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailForwardingRequest;
use App\Http\Requests\UpdateMailForwardingRequest;
use App\Models\MailForwarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Mail Forwards — forward/catchall/alias rows of mail_forwarding (contract:
 * api/modules/mail/forwards.yaml; legacy: mail_forward_edit.php and
 * siblings).
 *
 * Rows with type='aliasdomain' belong to /mail/alias-domains and 404 here.
 * server_id and sys_groupid are inherited from the source's mail_domain
 * (legacy parity).
 */
class MailForwardingController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/forwards — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        if ($type !== null && (! is_string($type) || ! in_array($type, MailForwarding::FORWARD_TYPES, true))) {
            throw new BadRequestHttpException("Invalid type filter. Allowed: 'forward', 'catchall', 'alias'.");
        }

        $result = $this->listQuery(
            MailForwarding::query()->forwardTypes(),
            $request,
            sortable: ['forwarding_id', 'source', 'destination', 'type', 'active'],
            defaultSort: 'source',
            filters: [
                'source' => 'wildcard',
                'destination' => 'wildcard',
                'type' => 'string',
                'active' => 'boolean',
            ],
            extra: ['type'],
        );

        return response()->json($result);
    }

    /**
     * GET /mail/forwards/{id} — includes the computed is_catchall flag;
     * scoped binding 404s alias-domain rows.
     */
    public function show(MailForwarding $mailForwarding): JsonResponse
    {
        return response()->json($mailForwarding);
    }

    /**
     * POST /mail/forwards — 201; datalog 'i'. server_id/sys_groupid come
     * from the source's mail_domain; per-type allow_send_as default
     * (y for alias, n for forward/catchall — legacy form defaults).
     */
    public function store(StoreMailForwardingRequest $request): JsonResponse
    {
        $payload = $request->payload();

        if (! array_key_exists('allow_send_as', $payload)) {
            $payload['allow_send_as'] = $payload['type'] === 'alias';
        }

        $forwarding = new MailForwarding($payload);

        $domain = $this->resolveSourceDomain((string) $payload['source']);
        $forwarding->setAttribute('server_id', (int) $domain->server_id);
        $forwarding->setAttribute('sys_groupid', (int) $domain->sys_groupid);

        DB::transaction(function () use ($forwarding): void {
            $forwarding->save();
        });

        return response()->json($forwarding->refresh(), 201);
    }

    /**
     * PUT /mail/forwards/{id} — 200; datalog 'u' (suppressed when nothing
     * changed). source and type are immutable.
     */
    public function update(UpdateMailForwardingRequest $request, MailForwarding $mailForwarding): JsonResponse
    {
        $mailForwarding->fill($request->payload());

        DB::transaction(function () use ($mailForwarding): void {
            $mailForwarding->save();
        });

        return response()->json($mailForwarding->refresh());
    }

    /**
     * DELETE /mail/forwards/{id} — 204; datalog 'd'.
     */
    public function destroy(MailForwarding $mailForwarding): Response
    {
        DB::transaction(function () use ($mailForwarding): void {
            $mailForwarding->delete();
        });

        return response()->noContent();
    }

    /**
     * The source's mail_domain (domain part of the address, or the
     * '@'-stripped domain for catchalls) — 400 when it is not a mail domain
     * (legacy no_domain_perm; the route param binding for updates never
     * needs this because source is immutable).
     *
     * @return object{server_id: mixed, sys_groupid: mixed}
     */
    protected function resolveSourceDomain(string $source): object
    {
        $domainPart = str_starts_with($source, '@')
            ? substr($source, 1)
            : substr((string) strrchr($source, '@'), 1);

        $domain = DB::table('mail_domain')->where('domain', $domainPart)->first();

        if ($domain === null) {
            throw new BadRequestHttpException("The domain '{$domainPart}' is not an existing mail domain.");
        }

        return $domain;
    }
}
