<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsTemplateRequest;
use App\Http\Requests\UpdateDnsTemplateRequest;
use App\Models\DnsTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * DNS zone templates (contract: api/modules/dns/template.yaml).
 *
 * Thin HTTP layer: validation (incl. the legacy placeholder whitelist for
 * `fields`) lives in the Form Requests, datalogging in BaseModel/
 * DatalogService. The API stores templates only — the legacy zone wizard
 * that expands them has no REST counterpart (spec 002, out of scope).
 */
class DnsTemplateController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /dns/templates — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            DnsTemplate::query(),
            $request,
            sortable: ['template_id', 'name', 'visible'],
            defaultSort: 'name',
            filters: [
                'name' => 'wildcard',
                'visible' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /dns/templates/{id} — implicit binding 404s as problem+json.
     */
    public function show(DnsTemplate $dnsTemplate): JsonResponse
    {
        return response()->json($dnsTemplate);
    }

    /**
     * POST /dns/templates — 201 with the created template; datalog 'i'.
     */
    public function store(StoreDnsTemplateRequest $request): JsonResponse
    {
        $template = new DnsTemplate($request->payload());

        DB::transaction(function () use ($template): void {
            $template->save();
        });

        return response()->json($template->refresh(), 201);
    }

    /**
     * PUT /dns/templates/{id} — 200 with the updated template; datalog 'u'
     * (suppressed when nothing changed).
     */
    public function update(UpdateDnsTemplateRequest $request, DnsTemplate $dnsTemplate): JsonResponse
    {
        $dnsTemplate->fill($request->payload());

        DB::transaction(function () use ($dnsTemplate): void {
            $dnsTemplate->save();
        });

        return response()->json($dnsTemplate->refresh());
    }

    /**
     * DELETE /dns/templates/{id} — 204; datalog action 'd'.
     */
    public function destroy(DnsTemplate $dnsTemplate): Response
    {
        DB::transaction(function () use ($dnsTemplate): void {
            $dnsTemplate->delete();
        });

        return response()->noContent();
    }
}
