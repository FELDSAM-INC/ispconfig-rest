<?php

namespace App\Http\Requests;

use App\Services\ServerConfigService;

/**
 * PUT /servers/{id}/configs/{section} (api/modules/server/server-config.yaml).
 *
 * The rules come from ServerConfigService's per-section field inventory —
 * the single source of truth mirroring the Server*Config.yaml schemas and
 * the legacy tabs of server_config.tform.php. Every key is optional
 * (omitted text/select keys keep their current value; omitted checkbox
 * keys are backfilled with 'n' by the service); checkbox values are the
 * literal 'y'/'n' strings the schemas declare. rspamd_available has no
 * rule — it is readOnly and client input for it is ignored.
 *
 * Keys outside the section's field inventory are ignored (not validated,
 * not written) — see the interpretation note in ServerConfigService.
 */
class UpdateServerConfigSectionRequest extends ServerModuleRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return app(ServerConfigService::class)->rules((string) $this->route('section'));
    }
}
