<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpamfilterPolicyRequest;
use App\Http\Requests\UpdateSpamfilterPolicyRequest;
use App\Models\SpamfilterPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Spamfilter Policies (contract: api/modules/mail/spamfilter-policies.yaml;
 * legacy: spamfilter_policy.tform.php).
 *
 * Only the contract-exposed column subset is writable/serialized; all other
 * legacy policy columns keep their DB defaults. Deleting a policy that is
 * still referenced by spamfilter_users returns 400 — an intentional
 * API-added guard (legacy spamfilter_policy_del.php has no in-use check).
 */
class SpamfilterPolicyController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/spamfilter/policies — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            SpamfilterPolicy::query(),
            $request,
            sortable: ['id', 'policy_name'],
            defaultSort: 'policy_name',
            filters: [
                'policy_name' => 'wildcard',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/spamfilter/policies/{id} — implicit binding 404s as
     * problem+json.
     */
    public function show(SpamfilterPolicy $spamfilterPolicy): JsonResponse
    {
        return response()->json($spamfilterPolicy);
    }

    /**
     * POST /mail/spamfilter/policies — 201; datalog 'i';
     * sys_perm_other='r' (policies are readable by all panel users).
     */
    public function store(StoreSpamfilterPolicyRequest $request): JsonResponse
    {
        $policy = new SpamfilterPolicy($request->payload());

        DB::transaction(function () use ($policy): void {
            $policy->save();
        });

        return response()->json($policy->refresh(), 201);
    }

    /**
     * PUT /mail/spamfilter/policies/{id} — 200; datalog 'u' (suppressed
     * when nothing changed).
     */
    public function update(UpdateSpamfilterPolicyRequest $request, SpamfilterPolicy $spamfilterPolicy): JsonResponse
    {
        $spamfilterPolicy->fill($request->payload());

        DB::transaction(function () use ($spamfilterPolicy): void {
            $spamfilterPolicy->save();
        });

        return response()->json($spamfilterPolicy->refresh());
    }

    /**
     * DELETE /mail/spamfilter/policies/{id} — 204; 400 when the policy is
     * still referenced by any spamfilter_users row (contract guard).
     */
    public function destroy(SpamfilterPolicy $spamfilterPolicy): Response
    {
        $inUse = DB::table('spamfilter_users')
            ->where('policy_id', $spamfilterPolicy->getKey())
            ->exists();

        if ($inUse) {
            throw new BadRequestHttpException(
                'The policy is in use by one or more spamfilter users and cannot be deleted.'
            );
        }

        DB::transaction(function () use ($spamfilterPolicy): void {
            $spamfilterPolicy->delete();
        });

        return response()->noContent();
    }
}
