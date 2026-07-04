<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailUserFilterRequest;
use App\Http\Requests\UpdateMailUserFilterRequest;
use App\Models\MailUser;
use App\Models\MailUserFilter;
use App\Services\MailUserFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mail user filter rules — nested CRUD under a mailbox (contract:
 * api/modules/mail/user-filters.yaml).
 *
 * Every operation is scoped to the mailbox in the URL (a filter belonging
 * to a different mailbox 404s). Every write regenerates the owning
 * mailbox's custom_mailfilter block via MailUserFilterService — one API
 * call therefore emits a mail_user_filter datalog entry AND a companion
 * mail_user datalog update, exactly like legacy
 * mail_user_filter_plugin.inc.php.
 */
class MailUserFilterController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected MailUserFilterService $service)
    {
    }

    /**
     * GET /mail/users/{id}/filters — filtered, sorted, paginated list.
     */
    public function index(Request $request, MailUser $mailUser): JsonResponse
    {
        $result = $this->listQuery(
            MailUserFilter::query()->where('mailuser_id', $mailUser->getKey()),
            $request,
            sortable: ['filter_id', 'rulename', 'source', 'active'],
            defaultSort: 'filter_id',
            filters: [
                'source' => 'string',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/users/{id}/filters/{filter_id} — 404 when the filter
     * belongs to another mailbox.
     */
    public function show(MailUser $mailUser, int $filterId): JsonResponse
    {
        return response()->json($this->findFilter($mailUser, $filterId));
    }

    /**
     * POST /mail/users/{id}/filters — 201; datalog 'i' on mail_user_filter
     * plus the companion mail_user custom_mailfilter update.
     */
    public function store(StoreMailUserFilterRequest $request, MailUser $mailUser): JsonResponse
    {
        $filter = new MailUserFilter($request->payload());
        $filter->setAttribute('mailuser_id', (int) $mailUser->getKey());

        DB::transaction(function () use ($filter, $mailUser): void {
            $filter->save();
            $this->service->applyFilter($mailUser, $filter);
        });

        return response()->json($filter->refresh(), 201);
    }

    /**
     * PUT /mail/users/{id}/filters/{filter_id} — 200; the filter's block in
     * custom_mailfilter is regenerated (removed when the rule is inactive).
     */
    public function update(UpdateMailUserFilterRequest $request, MailUser $mailUser, int $filterId): JsonResponse
    {
        $filter = $this->findFilter($mailUser, $filterId);

        $filter->fill($request->payload());

        DB::transaction(function () use ($filter, $mailUser): void {
            $filter->save();
            $this->service->applyFilter($mailUser, $filter);
        });

        return response()->json($filter->refresh());
    }

    /**
     * DELETE /mail/users/{id}/filters/{filter_id} — 204; datalog 'd' plus
     * the block removal from custom_mailfilter (mail_user datalog 'u').
     */
    public function destroy(MailUser $mailUser, int $filterId): Response
    {
        $filter = $this->findFilter($mailUser, $filterId);

        DB::transaction(function () use ($filter, $mailUser, $filterId): void {
            $filter->delete();
            $this->service->removeFilter($mailUser, $filterId);
        });

        return response()->noContent();
    }

    /**
     * The filter scoped to the mailbox (mismatch => 404 problem+json).
     */
    protected function findFilter(MailUser $mailUser, int $filterId): MailUserFilter
    {
        return MailUserFilter::query()
            ->where('mailuser_id', $mailUser->getKey())
            ->findOrFail($filterId);
    }
}
