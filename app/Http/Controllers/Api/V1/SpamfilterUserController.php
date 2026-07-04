<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpamfilterUserRequest;
use App\Http\Requests\UpdateSpamfilterUserRequest;
use App\Models\SpamfilterUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Spamfilter Users — email-to-policy mappings (contract:
 * api/modules/mail/spamfilter-users.yaml; legacy:
 * spamfilter_users.tform.php).
 *
 * email is globally unique (DB key -> 409); a non-zero policy_id must
 * reference an existing policy (404 per the contract, 0 = inherit);
 * email/server_id immutable on update.
 */
class SpamfilterUserController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/spamfilter/users — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            SpamfilterUser::query(),
            $request,
            sortable: ['id', 'email', 'priority', 'policy_id', 'server_id'],
            defaultSort: 'email',
            filters: [
                'email' => 'wildcard',
                'server_id' => 'integer',
                'policy_id' => 'integer',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/spamfilter/users/{id} — implicit binding 404s as
     * problem+json.
     */
    public function show(SpamfilterUser $spamfilterUser): JsonResponse
    {
        return response()->json($spamfilterUser);
    }

    /**
     * POST /mail/spamfilter/users — 201; datalog 'i'.
     */
    public function store(StoreSpamfilterUserRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $this->guardPolicyReference((int) $payload['policy_id']);

        if (SpamfilterUser::query()->where('email', (string) $payload['email'])->exists()) {
            throw new ConflictHttpException(
                "A spamfilter user for '{$payload['email']}' already exists."
            );
        }

        $spamfilterUser = new SpamfilterUser($payload);

        DB::transaction(function () use ($spamfilterUser): void {
            $spamfilterUser->save();
        });

        return response()->json($spamfilterUser->refresh(), 201);
    }

    /**
     * PUT /mail/spamfilter/users/{id} — 200; datalog 'u' (suppressed when
     * nothing changed). email/server_id immutable.
     */
    public function update(UpdateSpamfilterUserRequest $request, SpamfilterUser $spamfilterUser): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('policy_id', $payload)) {
            $this->guardPolicyReference((int) $payload['policy_id']);
        }

        $spamfilterUser->fill($payload);

        DB::transaction(function () use ($spamfilterUser): void {
            $spamfilterUser->save();
        });

        return response()->json($spamfilterUser->refresh());
    }

    /**
     * DELETE /mail/spamfilter/users/{id} — 204; datalog 'd'.
     */
    public function destroy(SpamfilterUser $spamfilterUser): Response
    {
        DB::transaction(function () use ($spamfilterUser): void {
            $spamfilterUser->delete();
        });

        return response()->noContent();
    }

    /**
     * A non-zero policy_id must reference an existing policy — 404 per the
     * contract (0 = inherit from a lower-priority match).
     */
    protected function guardPolicyReference(int $policyId): void
    {
        if ($policyId === 0) {
            return;
        }

        if (! DB::table('spamfilter_policy')->where('id', $policyId)->exists()) {
            throw new NotFoundHttpException("Spamfilter policy {$policyId} does not exist.");
        }
    }
}
