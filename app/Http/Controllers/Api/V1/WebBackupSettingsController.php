<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWebBackupSettingsRequest;
use App\Models\WebDomain;
use App\Services\WebBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Backup settings of a website (contract: api/modules/sites/web-backups.yaml,
 * feature 018) — the web_domain backup_* columns, written through the
 * datalog (BaseModel: `u` write gate, one web_domain `u` entry, suppressed
 * when nothing changed).
 */
class WebBackupSettingsController extends Controller
{
    public function __construct(protected WebBackupService $backups) {}

    /**
     * GET /sites/web-domains/{id}/backup-settings
     */
    public function show(WebDomain $webDomain): JsonResponse
    {
        return response()->json($this->backups->settingsRepresentation($webDomain));
    }

    /**
     * PUT /sites/web-domains/{id}/backup-settings — partial update, 200.
     */
    public function update(UpdateWebBackupSettingsRequest $request, WebDomain $webDomain): JsonResponse
    {
        $data = $request->validated();
        $native = [];

        foreach (['backup_interval', 'backup_format_web', 'backup_format_db'] as $field) {
            if (array_key_exists($field, $data)) {
                $native[$field] = (string) $data[$field];
            }
        }

        if (array_key_exists('backup_copies', $data)) {
            $native['backup_copies'] = (int) $data['backup_copies'];
        }

        if (array_key_exists('backup_excludes', $data)) {
            $native['backup_excludes'] = (string) ($data['backup_excludes'] ?? '');
        }

        if (array_key_exists('backup_encrypt', $data)) {
            $native['backup_encrypt'] = $data['backup_encrypt'] ? 'y' : 'n';
        }

        // Stored as plain text, like legacy (research R11); write-only.
        if (array_key_exists('backup_password', $data) && $data['backup_password'] !== null) {
            $native['backup_password'] = (string) $data['backup_password'];
        }

        if ($native !== []) {
            DB::transaction(fn () => $webDomain->forceFill($native)->save());
        }

        return response()->json($this->backups->settingsRepresentation($webDomain->refresh()));
    }
}
