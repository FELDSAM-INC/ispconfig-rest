<?php

namespace App\Http\Controllers\Api\V1\Usage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Usage\TrafficHistoryRequest;
use App\Models\MailUser;
use App\Services\TrafficPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Mailbox traffic history (contract: api/modules/usage/mail-users.yaml
 * `/usage/mail-users/{id}/traffic`, spec 017 US3). ISPConfig stores mail
 * traffic per month, so only monthly points exist.
 */
class MailUserTrafficController extends Controller
{
    public function __construct(private readonly TrafficPeriodService $traffic) {}

    /**
     * GET /usage/mail-users/{id}/traffic — `granularity=day` is 422; 404 when unreadable.
     */
    public function show(TrafficHistoryRequest $request, int $id): JsonResponse
    {
        if ($request->granularity() === 'day') {
            throw ValidationException::withMessages([
                'granularity' => 'Mail traffic is stored per month; daily history is not available.',
            ]);
        }

        $mailUser = MailUser::query()->readable()->whereKey($id)->firstOrFail();

        return response()->json($this->traffic->mailHistory((int) $mailUser->getKey(), $request->months()));
    }
}
