<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMailUserSpamFilterRequest;
use App\Models\MailUser;
use App\Services\SpamfilterUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Mail user spam filter settings — a singleton view over the move_junk /
 * purge_* / custom_mailfilter columns of the mail_user row (contract:
 * api/modules/mail/user-spamfilter.yaml).
 *
 * Note: the `### BEGIN/END FILTER_ID:<id>` blocks inside custom_mailfilter
 * are generated from /mail/users/{id}/filters and rewritten automatically
 * on every filter change.
 *
 * policy_id (spec 026) is the spam filter level from the mailbox's
 * spamfilter_users row, written in the same transaction and change set.
 */
class MailUserSpamFilterController extends Controller
{
    public function __construct(protected SpamfilterUserService $spamfilterUsers) {}

    /**
     * GET /mail/users/{id}/spamfilter
     */
    public function show(MailUser $mailUser): JsonResponse
    {
        return response()->json($this->view($mailUser));
    }

    /**
     * PUT /mail/users/{id}/spamfilter — 200; datalog 'u' on mail_user.
     */
    public function update(UpdateMailUserSpamFilterRequest $request, MailUser $mailUser): JsonResponse
    {
        $mailUser->fill($request->payload());
        $policyId = $request->policyId();

        DB::transaction(function () use ($mailUser, $policyId): void {
            // The save also enforces update permission on the mailbox when only the level changes.
            $mailUser->save();

            if ($policyId !== null) {
                $this->spamfilterUsers->assignMailbox($mailUser, $policyId);
            }
        });

        return response()->json($this->view($mailUser->refresh()));
    }

    /**
     * The MailUserSpamFilter contract shape.
     *
     * @return array<string, mixed>
     */
    protected function view(MailUser $mailUser): array
    {
        $raw = $mailUser->getAttributes();

        return [
            'id' => (int) $mailUser->getKey(),
            'move_junk' => $raw['move_junk'] ?? 'y',
            'purge_trash_days' => (int) ($raw['purge_trash_days'] ?? 0),
            'purge_junk_days' => (int) ($raw['purge_junk_days'] ?? 0),
            'custom_mailfilter' => $raw['custom_mailfilter'] ?? null,
            'policy_id' => $this->spamfilterUsers->policyFor((string) ($raw['email'] ?? '')),
        ];
    }
}
