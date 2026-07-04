<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpamfilterWBListRequest;
use App\Http\Requests\UpdateSpamfilterWBListRequest;
use App\Models\SpamfilterWBList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Spamfilter WB List — per-recipient white/blacklist entries (contract:
 * api/modules/mail/spamfilter-wblist.yaml; legacy:
 * spamfilter_blacklist.tform.php + spamfilter_whitelist.tform.php).
 *
 * A non-zero rid must reference an existing spamfilter user (404 per the
 * contract). rid=0 is accepted but has NO effect on Rspamd systems — the
 * server plugin (rspamd_plugin.inc.php:399-445) skips entries whose rid
 * does not resolve (C-10); global rules belong in /mail/access-rules.
 * email/rid immutable on update.
 */
class SpamfilterWBListController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/spamfilter/wblist — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            SpamfilterWBList::query(),
            $request,
            sortable: ['wblist_id', 'email', 'wb', 'rid', 'priority', 'active'],
            defaultSort: 'wblist_id',
            filters: [
                'email' => 'wildcard',
                'wb' => 'string',
                'rid' => 'integer',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/spamfilter/wblist/{id} — implicit binding 404s as
     * problem+json.
     */
    public function show(SpamfilterWBList $spamfilterWblist): JsonResponse
    {
        return response()->json($spamfilterWblist);
    }

    /**
     * POST /mail/spamfilter/wblist — 201; datalog 'i'.
     */
    public function store(StoreSpamfilterWBListRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $this->guardRidReference((int) $payload['rid']);

        $entry = new SpamfilterWBList($payload);

        DB::transaction(function () use ($entry): void {
            $entry->save();
        });

        return response()->json($entry->refresh(), 201);
    }

    /**
     * PUT /mail/spamfilter/wblist/{id} — 200; datalog 'u' (suppressed when
     * nothing changed). email/rid immutable.
     */
    public function update(UpdateSpamfilterWBListRequest $request, SpamfilterWBList $spamfilterWblist): JsonResponse
    {
        $spamfilterWblist->fill($request->payload());

        DB::transaction(function () use ($spamfilterWblist): void {
            $spamfilterWblist->save();
        });

        return response()->json($spamfilterWblist->refresh());
    }

    /**
     * DELETE /mail/spamfilter/wblist/{id} — 204; datalog 'd'.
     */
    public function destroy(SpamfilterWBList $spamfilterWblist): Response
    {
        DB::transaction(function () use ($spamfilterWblist): void {
            $spamfilterWblist->delete();
        });

        return response()->noContent();
    }

    /**
     * A non-zero rid must reference an existing spamfilter user — 404 per
     * the contract.
     */
    protected function guardRidReference(int $rid): void
    {
        if ($rid === 0) {
            return;
        }

        if (! DB::table('spamfilter_users')->where('id', $rid)->exists()) {
            throw new NotFoundHttpException("Spamfilter user {$rid} does not exist.");
        }
    }
}
