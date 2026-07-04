<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailUserRequest;
use App\Http\Requests\UpdateMailUserRequest;
use App\Models\MailUser;
use App\Services\MailUserService;
use App\Support\LegacyCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mail Users — mailboxes (contract: api/modules/mail/users.yaml).
 *
 * Thin HTTP layer: validation in the Form Requests, legacy composition
 * (server_id/maildir/homedir/uid/gid/login, sys_groupid forcing, companion
 * spamfilter_users upsert) in MailUserService, CRYPTMAIL password hashing in
 * LegacyCrypt::hashMail, datalogging in BaseModel/DatalogService. Success
 * responses confirm the sys_datalog entry — ISPConfig provisions the
 * mailbox asynchronously.
 */
class MailUserController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected MailUserService $service)
    {
    }

    /**
     * GET /mail/users — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MailUser::query();

        // `domain` filters by the domain part of the email address.
        $domain = $request->query('domain');
        if (is_string($domain) && $domain !== '') {
            $query->where('email', 'like', '%@'.strtolower($domain));
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['mailuser_id', 'email', 'login', 'name', 'server_id', 'quota'],
            defaultSort: 'email',
            filters: [
                'email' => 'wildcard',
                'login' => 'wildcard',
                'postfix' => 'boolean',
            ],
            extra: ['domain'],
        );

        return response()->json($result);
    }

    /**
     * GET /mail/users/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailUser $mailUser): JsonResponse
    {
        return response()->json($mailUser);
    }

    /**
     * POST /mail/users — 201; datalog 'i' on mail_user plus the companion
     * spamfilter_users upsert (legacy parity).
     */
    public function store(StoreMailUserRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $payload['password'] = LegacyCrypt::hashMail((string) $payload['password']);

        $user = new MailUser($payload);

        $domain = $this->service->resolveMailDomain((string) $payload['email']); // 400 when missing
        $this->service->applyCreateDerivations($user, $domain);

        DB::transaction(function () use ($user, $domain): void {
            $user->save();
            $this->service->syncSpamfilterUser($user, $domain);
        });

        return response()->json($user->refresh(), 201);
    }

    /**
     * PUT /mail/users/{id} — 200; datalog 'u' (suppressed when nothing
     * changed). email/login immutable, password re-hashed only when a
     * non-empty value is provided, maildir_format preserved.
     */
    public function update(UpdateMailUserRequest $request, MailUser $mailUser): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('password', $payload)) {
            $payload['password'] = LegacyCrypt::hashMail((string) $payload['password']);
        }

        $mailUser->fill($payload);

        $domain = $this->service->resolveMailDomain((string) $mailUser->getRawOriginal('email'));
        $this->service->applyUpdateDerivations($mailUser, $domain);

        DB::transaction(function () use ($mailUser, $domain): void {
            $mailUser->save();
            $this->service->syncSpamfilterUser($mailUser, $domain);
        });

        return response()->json($mailUser->refresh());
    }

    /**
     * DELETE /mail/users/{id} — 204; datalog 'd'. Forwards pointing at the
     * deleted address are NOT removed (legacy parity: the interface layer
     * does not cascade, server plugins handle the maildir).
     */
    public function destroy(MailUser $mailUser): Response
    {
        DB::transaction(function () use ($mailUser): void {
            $mailUser->delete();
        });

        return response()->noContent();
    }
}
