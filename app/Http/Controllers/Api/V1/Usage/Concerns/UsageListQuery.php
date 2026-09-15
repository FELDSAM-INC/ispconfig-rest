<?php

namespace App\Http\Controllers\Api\V1\Usage\Concerns;

use App\Support\IspContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Shared list behaviour of the usage module (spec 017 FR-009, research R10).
 *
 * The lists run on the real resource models through HandlesListQuery, so the
 * read predicate, strict parameters and pagination come from the shared
 * machinery. What is usage-specific lives here: the `client_id` owning-client
 * filter is available to admin and reseller keys only — a client key can see
 * only its own rows, so the parameter is rejected with 400 (owner decision
 * 2026-09-14).
 */
trait UsageListQuery
{
    protected function rejectClientIdForClientKeys(Request $request): void
    {
        if ($request->query('client_id') === null) {
            return;
        }

        $scope = app(IspContext::class)->authScope();

        if (! $scope->isAdmin && ! $scope->isReseller()) {
            throw new BadRequestHttpException("The 'client_id' filter is available to admin and reseller keys only.");
        }
    }
}
