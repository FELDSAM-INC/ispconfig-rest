<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMailUserCCRequest;
use App\Models\MailUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Mail user CC settings — a singleton view over the cc / forward_in_lda
 * columns of the mail_user row (contract: api/modules/mail/user-cc.yaml).
 */
class MailUserCCController extends Controller
{
    /**
     * GET /mail/users/{id}/cc
     */
    public function show(MailUser $mailUser): JsonResponse
    {
        return response()->json($this->view($mailUser));
    }

    /**
     * PUT /mail/users/{id}/cc — 200; datalog 'u' on mail_user.
     */
    public function update(UpdateMailUserCCRequest $request, MailUser $mailUser): JsonResponse
    {
        $mailUser->fill($request->payload());

        DB::transaction(function () use ($mailUser): void {
            $mailUser->save();
        });

        return response()->json($this->view($mailUser->refresh()));
    }

    /**
     * The MailUserCC contract shape.
     *
     * @return array<string, mixed>
     */
    protected function view(MailUser $mailUser): array
    {
        $raw = $mailUser->getAttributes();

        return [
            'id' => (int) $mailUser->getKey(),
            'cc' => $raw['cc'] ?? '',
            'forward_in_lda' => ($raw['forward_in_lda'] ?? 'n') === 'y',
        ];
    }
}
