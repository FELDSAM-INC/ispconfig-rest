<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMailUserPasswordRequest;
use App\Models\MailUser;
use App\Support\LegacyCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Mail user password rotation (contract: api/modules/mail/user-password.yaml).
 *
 * Only the password column is updated (datalog 'u' on mail_user, hash only —
 * plaintext never persists anywhere).
 */
class MailUserPasswordController extends Controller
{
    /**
     * PUT /mail/users/{id}/password — 200 {success, message}.
     */
    public function update(UpdateMailUserPasswordRequest $request, MailUser $mailUser): JsonResponse
    {
        $mailUser->setAttribute('password', LegacyCrypt::hashMail((string) $request->validated('password')));

        DB::transaction(function () use ($mailUser): void {
            $mailUser->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully',
        ]);
    }
}
