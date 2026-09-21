<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailRecipientWBListRequest;
use App\Models\BaseModel;
use App\Models\MailDomain;
use App\Models\MailUser;
use App\Models\SpamfilterWBList;
use App\Services\SpamfilterUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Recipient-bound creation, including recipients without a spamfilter_users row. */
class MailRecipientWBListController extends Controller
{
    public function domain(StoreMailRecipientWBListRequest $request, MailDomain $mailDomain): JsonResponse
    {
        return $this->store($request, $mailDomain, '@'.$mailDomain->domain);
    }

    public function mailbox(StoreMailRecipientWBListRequest $request, MailUser $mailUser): JsonResponse
    {
        return $this->store($request, $mailUser, $mailUser->email);
    }

    private function store(StoreMailRecipientWBListRequest $request, BaseModel $recipient, string $email): JsonResponse
    {
        $entry = DB::transaction(function () use ($request, $recipient, $email): SpamfilterWBList {
            // Serialize first-row creation for this recipient, and enforce its update/lock permission.
            $recipient->newQuery()->whereKey($recipient->getKey())->lockForUpdate()->firstOrFail();
            $recipient->save();
            $profiles = app(SpamfilterUserService::class);
            $policy = $profiles->policyFor($email);
            if ($recipient instanceof MailDomain) {
                $profiles->assignDomain($recipient, $policy);
            } else {
                $profiles->assignMailbox($recipient, $policy);
            }
            $rid = DB::table('spamfilter_users')->where('email', $email)->orderBy('id')->value('id');
            $entry = new SpamfilterWBList($request->payload() + [
                'rid' => (int) $rid,
                'server_id' => (int) $recipient->server_id,
            ]);
            $entry->save();

            return $entry->refresh();
        });

        return response()->json($entry, 201);
    }
}
