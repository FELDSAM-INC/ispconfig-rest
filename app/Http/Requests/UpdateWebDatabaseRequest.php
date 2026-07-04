<?php

namespace App\Http\Requests;

use App\Models\WebDatabase;
use Illuminate\Validation\Rule;

/**
 * PUT /sites/databases/{id} (api/modules/sites/databases.yaml).
 *
 * Immutable per legacy onBeforeUpdate: database_name, database_charset,
 * server_id — sending the current value is accepted (idempotent PUTs).
 * database_user_id is required on update (legacy database_user_missing).
 */
class UpdateWebDatabaseRequest extends SitesRequest
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
                'sometimes',
                'integer',
                $this->immutableRule(fn () => $this->currentValue('server_id', true), 'server'),
            ],
            'parent_domain_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias']),
            ],
            'type' => ['sometimes', Rule::in(['mysql', 'postgresql'])],
            'database_name' => [
                'sometimes',
                'string',
                // The un-prefixed name must equal the stored one.
                $this->immutableRule(fn () => $this->currentDatabase()?->database_name, 'database name'),
            ],
            'database_quota' => ['sometimes', ...$this->quotaRules()],
            'database_user_id' => [
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
            'database_charset' => [
                'sometimes',
                'nullable',
                $this->immutableRule(fn () => $this->currentValue('database_charset'), 'database charset'),
            ],
            'remote_access' => ['sometimes', 'boolean'],
            'remote_ips' => ['sometimes', 'nullable', 'string'],
            'backup_interval' => ['sometimes', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'backup_copies' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function currentDatabase(): ?WebDatabase
    {
        $database = $this->route('webDatabase');

        return $database instanceof WebDatabase ? $database : null;
    }

    protected function currentValue(string $attribute, bool $asInt = false): mixed
    {
        $database = $this->currentDatabase();

        if ($database === null) {
            return null;
        }

        $value = $database->getAttributes()[$attribute] ?? null;

        return $asInt ? (int) $value : $value;
    }
}
