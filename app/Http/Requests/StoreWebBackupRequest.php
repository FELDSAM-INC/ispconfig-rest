<?php

namespace App\Http\Requests;

use App\Models\WebDomain;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /sites/web-domains/{id}/backups (api/modules/sites/web-backups.yaml).
 *
 * On-demand backups need update permission on the website (legacy
 * plugin_backuplist checks getAuthSQL('u'), research R8 step 4) — checked
 * before validation, so a read-only key gets 403, not 422.
 */
class StoreWebBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $website = $this->route('webDomain');

        return $website instanceof WebDomain
            && app(IspContext::class)->authScope()->allows($website->getAttributes(), 'u');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('You do not have permission to update this resource.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['web', 'mysql'])],
        ];
    }
}
