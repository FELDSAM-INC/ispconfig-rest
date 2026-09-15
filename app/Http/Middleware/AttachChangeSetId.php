<?php

namespace App\Http\Middleware;

use App\Support\IspContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `X-Change-Set-Id` to successful responses of requests that wrote at
 * least one sys_datalog entry (spec 015 FR-001, research R4).
 *
 * The value is the request's journal session id (IspContext::sessionId()),
 * shared by every entry the request wrote, including cascades. No-change
 * updates, failed requests and writes to API-owned tables write no journal
 * entry and therefore get no header.
 */
class AttachChangeSetId
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(IspContext::class);
        $context->beginChangeSet();

        $response = $next($request);

        if ($response->isSuccessful() && $context->journalEntryCount() > 0) {
            $response->headers->set('X-Change-Set-Id', $context->sessionId());
        }

        return $response;
    }
}
