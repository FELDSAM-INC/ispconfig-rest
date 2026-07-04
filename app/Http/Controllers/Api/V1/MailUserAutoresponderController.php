<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMailUserAutoresponderRequest;
use App\Models\MailUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mail user autoresponder — a singleton view over the autoresponder columns
 * of the mail_user row (contract: api/modules/mail/user-autoresponder.yaml).
 *
 * DELETE is NOT a row delete: it disables the autoresponder and clears both
 * dates, exactly like legacy mail_user_edit.php (dates are dropped whenever
 * the autoresponder checkbox is unchecked). All writes are mail_user
 * datalog updates.
 */
class MailUserAutoresponderController extends Controller
{
    /**
     * GET /mail/users/{id}/autoresponder
     */
    public function show(MailUser $mailUser): JsonResponse
    {
        return response()->json($this->view($mailUser));
    }

    /**
     * PUT /mail/users/{id}/autoresponder — 200; datalog 'u' on mail_user.
     */
    public function update(UpdateMailUserAutoresponderRequest $request, MailUser $mailUser): JsonResponse
    {
        $payload = $request->payload();

        $mailUser->fill($payload);

        // Legacy parity: disabling the autoresponder clears both dates.
        if (! $request->boolean('autoresponder')) {
            $mailUser->setAttribute('autoresponder_start_date', null);
            $mailUser->setAttribute('autoresponder_end_date', null);
        }

        DB::transaction(function () use ($mailUser): void {
            $mailUser->save();
        });

        return response()->json($this->view($mailUser->refresh()));
    }

    /**
     * DELETE /mail/users/{id}/autoresponder — 204; sets autoresponder='n'
     * and clears both dates (datalog 'u', never a row delete).
     */
    public function destroy(MailUser $mailUser): Response
    {
        $mailUser->setAttribute('autoresponder', 'n');
        $mailUser->setAttribute('autoresponder_start_date', null);
        $mailUser->setAttribute('autoresponder_end_date', null);

        DB::transaction(function () use ($mailUser): void {
            $mailUser->save();
        });

        return response()->noContent();
    }

    /**
     * The MailUserAutoresponder contract shape.
     *
     * @return array<string, mixed>
     */
    protected function view(MailUser $mailUser): array
    {
        $raw = $mailUser->getAttributes();

        return [
            'id' => (int) $mailUser->getKey(),
            'email' => $raw['email'] ?? '',
            'autoresponder' => ($raw['autoresponder'] ?? 'n') === 'y',
            'autoresponder_start_date' => $this->dateOrNull($raw['autoresponder_start_date'] ?? null),
            'autoresponder_end_date' => $this->dateOrNull($raw['autoresponder_end_date'] ?? null),
            'autoresponder_subject' => $raw['autoresponder_subject'] ?? 'Out of office reply',
            'autoresponder_text' => $raw['autoresponder_text'] ?? null,
        ];
    }

    /**
     * DATETIME column -> RFC 3339 date-time (schema format), null when unset.
     */
    protected function dateOrNull(mixed $value): ?string
    {
        if (blank($value) || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date(DATE_ATOM, $timestamp);
    }
}
