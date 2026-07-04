<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/databases (api/modules/sites/databases.yaml; legacy
 * form/database.tform.php + database_edit.php). The database_name
 * submitted here is the UN-prefixed name; prefixing, the ≤64-char cap,
 * blacklist, per-server uniqueness, remote-access auto-fix and linked-user
 * sync are handled in the controller.
 */
class StoreWebDatabaseRequest extends SitesRequest
{
    protected function booleanFields(): array
    {
        return ['remote_access', 'active'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => [
                'required',
                'integer',
                Rule::exists('server', 'server_id')
                    ->where('db_server', 1)
                    ->where('mirror_server_id', 0),
            ],
            'parent_domain_id' => [
                // Legacy rejects parent_domain_id = 0 (database_site_error_empty).
                'required',
                'integer',
                'min:1',
                Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias']),
            ],
            'type' => ['sometimes', Rule::in(['mysql', 'postgresql'])],
            'database_name' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{2,64}$/'],
            'database_quota' => ['sometimes', ...$this->quotaRules()],
            'database_user_id' => [
                // Legacy database_user_missing check.
                'required',
                'integer',
                Rule::exists('web_database_user', 'database_user_id'),
            ],
            'database_ro_user_id' => [
                'sometimes',
                'integer',
                Rule::when(
                    (int) $this->input('database_ro_user_id', 0) !== 0,
                    [Rule::exists('web_database_user', 'database_user_id')]
                ),
            ],
            'database_charset' => ['sometimes', 'nullable', Rule::in(['', 'latin1', 'utf8', 'utf8mb4'])],
            'remote_access' => ['sometimes', 'boolean'],
            'remote_ips' => ['sometimes', 'nullable', 'string'],
            'backup_interval' => ['sometimes', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'backup_copies' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
