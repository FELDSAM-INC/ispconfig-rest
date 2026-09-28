<?php

namespace App\Http\Controllers\Api\V1\Usage;

use App\Http\Controllers\Controller;
use App\Services\ResourceUsageService;
use App\Services\UsageService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * PHP-FPM resource limits and usage (contract: api/modules/usage/resources.yaml,
 * spec 053). Target-client rules are those of the usage summary.
 */
class ResourceUsageController extends Controller
{
    public function __construct(
        private readonly UsageService $usage,
        private readonly ResourceUsageService $resources,
    ) {}

    /** GET /usage/resources — only `client_id` is accepted. */
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
                throw ValidationException::withMessages(['client_id' => 'The client id must be a positive integer.']);
            }
            $clientId = (int) $raw;
        }
        $target = $this->usage->resolveTargetClient(app(IspContext::class)->authScope(), $clientId);

        return response()->json($this->resources->summary($target))->header('Cache-Control', 'private, no-store');
    }
}
