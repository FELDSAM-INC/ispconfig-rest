<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemConfigRequest;
use App\Http\Requests\UpdateSystemConfigSectionRequest;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;

/**
 * Global system configuration — the sys_ini singleton exposed as one
 * composite resource and five per-section resources (contract:
 * api/modules/system/{system,sites,mail,dns,domains,misc}-config.yaml).
 *
 * GET/PUT only — the configuration is one record (sysini_id = 1); there is
 * no create, delete or list. PUT is a read-merge-write on the INI blob:
 * unsubmitted keys (including legacy keys the API never exposes) are
 * preserved, and the write is persisted through exactly one sys_datalog 'u'
 * entry for sys_ini (never a direct, unjournaled table write). Success
 * responses confirm the datalog entry — ISPConfig applies changes
 * asynchronously.
 *
 * The route file binds each literal section path with a 'section' default,
 * so unknown sections can never reach this controller (route miss = 404).
 */
class SystemConfigController extends Controller
{
    public function __construct(protected SystemConfigService $config) {}

    /**
     * GET /system/config — id + all five sections.
     */
    public function show(): JsonResponse
    {
        return response()->json($this->config->getConfig());
    }

    /**
     * PUT /system/config — merge any subset of sections, echo the whole
     * configuration (200).
     */
    public function update(UpdateSystemConfigRequest $request): JsonResponse
    {
        $this->config->updateSections($request->sectionChanges());

        return response()->json($this->config->getConfig());
    }

    /**
     * GET /system/config/{section}.
     */
    public function showSection(string $section): JsonResponse
    {
        return response()->json($this->config->getSection($section));
    }

    /**
     * PUT /system/config/{section} — merge the submitted keys, echo the
     * updated section (200; the mail panel echoes its section like every
     * sibling).
     */
    public function updateSection(UpdateSystemConfigSectionRequest $request, string $section): JsonResponse
    {
        $changes = $request->sectionChanges();

        if ($changes !== []) {
            $this->config->updateSections([$section => $changes]);
        }

        return response()->json($this->config->getSection($section));
    }
}
