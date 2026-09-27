<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWebWafRequest;
use App\Models\WebDomain;
use App\Services\WebWafService;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class WebWafController extends Controller
{
    public function show(WebDomain $webDomain, WebWafService $waf): JsonResponse
    {
        return response()->json($waf->view($webDomain), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(UpdateWebWafRequest $request, WebDomain $webDomain, WebWafService $waf): JsonResponse
    {
        $site = DB::transaction(function () use ($request, $webDomain, $waf): WebDomain {
            $site = WebDomain::query()->readable()->whereKey($webDomain->getKey())->lockForUpdate()->firstOrFail();
            $scope = app(IspContext::class)->authScope();
            if (! $scope->isAdmin && DB::table('client')->join('sys_group', 'client.client_id', '=', 'sys_group.client_id')->where('sys_group.groupid', $site->sys_groupid)->where('client.locked', 'y')->exists()) {
                throw new AuthorizationException('The account is locked; WAF settings cannot be changed.');
            }
            $waf->update($site, $request->validated());

            return $site;
        });

        return response()->json($waf->view($site), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request, WebDomain $webDomain, WebWafService $waf): JsonResponse
    {
        $filters = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'outcome' => ['sometimes', Rule::in(['blocked', 'detected'])], 'rule_id' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);

        return response()->json($waf->events($webDomain, $filters), 200, ['Cache-Control' => 'private, no-store']);
    }
}
