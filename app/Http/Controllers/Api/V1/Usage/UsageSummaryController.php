<?php

namespace App\Http\Controllers\Api\V1\Usage;

use App\Http\Controllers\Controller;
use App\Services\UsageService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Usage summary (contract: api/modules/usage/summary.yaml, spec 017 US1).
 *
 * Read-only: totals and resource counts of one client against its plan
 * limits. Target-client rules live in UsageService::resolveTargetClient().
 */
class UsageSummaryController extends Controller
{
    public function __construct(private readonly UsageService $usage) {}

    /**
     * GET /usage/summary — only `client_id` is accepted (other parameters 400,
     * an invalid value 422).
     */
    public function show(Request $request): JsonResponse
    {
        $unknown = array_diff(array_keys($request->query()), ['client_id']);

        if ($unknown !== []) {
            throw new BadRequestHttpException(sprintf("Unknown parameter '%s'. Allowed: client_id.", implode("', '", $unknown)));
        }

        $clientId = null;
        $raw = $request->query('client_id');

        if ($raw !== null) {
            if (! is_string($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 1) {
                throw ValidationException::withMessages([
                    'client_id' => 'The client id must be a positive integer.',
                ]);
            }

            $clientId = (int) $raw;
        }

        $target = $this->usage->resolveTargetClient(app(IspContext::class)->authScope(), $clientId);

        return response()->json($this->usage->summary($target));
    }
}
