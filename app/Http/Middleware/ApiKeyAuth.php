<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\AuthScope;
use App\Support\IspContext;
use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * X-API-Key authentication (constitution Principle V).
 *
 * Keys are stored SHA-256 hashed in the API-owned api_keys table, each bound
 * to an ISPConfig sys_userid/sys_groupid pair that downstream datalog writes
 * are attributed to. In local/testing environments a configured dev key
 * (config api.dev_key) authenticates as the admin user.
 *
 * After key validation the AuthScope is resolved (spec 011 FR-001/FR-002):
 * sys_userid 1 short-circuits to admin with zero extra queries (the dev key
 * is a synthetic 1/1 admin and never consults sys_user); any other id costs
 * one sys_user read — typ='admin' yields an admin scope, otherwise the
 * groups CSV is expanded into the scope's group set. A key bound to a
 * sys_userid without a sys_user row is rejected with 401, fail-closed, using
 * the same problem body as an invalid key (FR-005, owner decision).
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-API-Key');

        if ($key === null || $key === '') {
            return Problem::response(401, 'Unauthorized', 'API key is required. Provide it in the X-API-Key header.');
        }

        $devKey = config('api.dev_key');

        if ($devKey && app()->environment(['local', 'development', 'testing']) && hash_equals($devKey, $key)) {
            $request->attributes->set('sys_userid', 1);
            $request->attributes->set('sys_groupid', 1);
            $context = app(IspContext::class);
            $context->actAs(1, 1);
            $context->actAsScope(AuthScope::admin());

            return $next($request);
        }

        $apiKey = ApiKey::query()
            ->where('key_hash', hash('sha256', $key))
            ->where('active', true)
            ->first();

        if ($apiKey === null) {
            return Problem::response(401, 'Unauthorized', 'The provided API key is invalid or has been revoked.');
        }

        $scope = $this->resolveScope((int) $apiKey->sys_userid, (int) $apiKey->sys_groupid);

        if ($scope === null) {
            // FR-005: bound sys_user row is gone — fail closed, same body as
            // an invalid key (no information about why).
            return Problem::response(401, 'Unauthorized', 'The provided API key is invalid or has been revoked.');
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('sys_userid', $apiKey->sys_userid);
        $request->attributes->set('sys_groupid', $apiKey->sys_groupid);
        $request->attributes->set('api_key_id', $apiKey->id);
        $context = app(IspContext::class);
        $context->actAs((int) $apiKey->sys_userid, (int) $apiKey->sys_groupid);
        $context->actAsScope($scope);

        return $next($request);
    }

    /**
     * Resolve the key's AuthScope (spec 011 FR-001/FR-002): userid 1 is the
     * installer-seeded superadmin (zero-query path, preserves current
     * behavior); otherwise one sys_user read supplies typ, the groups CSV
     * and client_id. Returns null when the sys_user row is missing (401).
     */
    protected function resolveScope(int $sysUserId, int $sysGroupId): ?AuthScope
    {
        if ($sysUserId === 1) {
            return AuthScope::admin(1, $sysGroupId);
        }

        $user = DB::table('sys_user')
            ->where('userid', $sysUserId)
            ->first(['typ', 'groups', 'client_id']);

        if ($user === null) {
            return null;
        }

        if ($user->typ === 'admin') {
            return AuthScope::admin($sysUserId, $sysGroupId);
        }

        // Legacy predicate group source: the sys_user.groups CSV verbatim
        // (tform_base.inc.php:1758-1763), united with the key's bound group.
        $groupIds = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string) $user->groups)
        ), fn (int $id): bool => $id > 0)));

        if ($sysGroupId > 0 && ! in_array($sysGroupId, $groupIds, true)) {
            $groupIds[] = $sysGroupId;
        }

        return new AuthScope($sysUserId, $sysGroupId, $groupIds, false, (int) $user->client_id);
    }
}
