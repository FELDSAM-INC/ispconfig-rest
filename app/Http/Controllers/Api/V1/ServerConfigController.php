<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateServerConfigSectionRequest;
use App\Models\Server;
use App\Services\ServerConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Server configuration — the server.config INI blob (contract:
 * api/modules/server/server-config.yaml).
 *
 * Read-only as a whole (GET /servers/{id}/configs), written one section at
 * a time (GET/PUT /servers/{id}/configs/{section}). All blob semantics —
 * ISPConfig-compatible parsing/serialization, byte-safe section merge,
 * checkbox backfill, mail-section guards and the rspamd re-sync side
 * effect — live in ServerConfigService; the datalog 'u' on the `server`
 * row is produced by Server::save() (BaseModel).
 *
 * The {section} route parameter is constrained to the contract's eleven
 * sections in routes/api/server.php — anything else 404s before reaching
 * this controller.
 */
class ServerConfigController extends Controller
{
    public function __construct(protected ServerConfigService $service)
    {
    }

    /**
     * GET /servers/{id}/configs — the fully parsed configuration
     * (ServerConfig.yaml: server_id + every stored section, including the
     * read-only [global] and any unknown sections, verbatim).
     */
    public function show(Server $server): JsonResponse
    {
        return response()->json($this->service->getConfig($server));
    }

    /**
     * GET /servers/{id}/configs/{section} — one parsed section ({} when the
     * blob does not contain it yet).
     */
    public function showSection(Server $server, string $section): JsonResponse
    {
        return $this->sectionResponse($this->service->getSection($server, $section));
    }

    /**
     * PUT /servers/{id}/configs/{section} — byte-safe read-merge-write of
     * one section; 200 with the updated section. Exactly one datalog 'u'
     * row on table `server` carries the full re-serialized blob (plus the
     * spamfilter re-sync rows when the mail section switches
     * content_filter to rspamd).
     */
    public function updateSection(UpdateServerConfigSectionRequest $request, Server $server, string $section): JsonResponse
    {
        $updated = DB::transaction(
            fn (): array => $this->service->updateSection($server, $section, $request->payload())
        );

        return $this->sectionResponse($updated);
    }

    /**
     * Empty sections must serialize as {} (an object per the schemas),
     * never [].
     *
     * @param  array<string, mixed>  $section
     */
    protected function sectionResponse(array $section): JsonResponse
    {
        return response()->json($section === [] ? new stdClass : $section);
    }
}
