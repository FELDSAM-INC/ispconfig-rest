<?php

namespace App\Http\Requests;

use App\Models\WebFolderUser;

/**
 * PUT /sites/web-folder-users/{id}
 * (api/modules/sites/web-folder-users.yaml): only `password` and `active`
 * are updatable — `username` and `web_folder_id` are immutable after
 * creation (contract restriction, stricter than legacy). Sending the
 * current values is accepted (idempotent PUTs).
 */
class UpdateWebFolderUserRequest extends SitesRequest
{
    protected function booleanFields(): array
    {
        return ['active'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'username' => [
                'sometimes',
                'string',
                $this->immutableRule(fn () => $this->routeUserAttribute('username'), 'username'),
            ],
            'web_folder_id' => [
                'sometimes',
                'integer',
                $this->immutableRule(fn () => $this->routeUserAttribute('web_folder_id', true), 'web folder'),
            ],
        ];
    }

    /**
     * Only password/active reach the model (contract restriction).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_intersect_key($this->validated(), array_flip(['password', 'active']));
    }

    protected function routeUserAttribute(string $attribute, bool $asInt = false): mixed
    {
        $user = $this->route('webFolderUser');

        if (! $user instanceof WebFolderUser) {
            return null;
        }

        $value = $user->getAttributes()[$attribute] ?? null;

        return $asInt ? (int) $value : $value;
    }
}
