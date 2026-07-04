<?php

namespace App\Http\Requests;

/**
 * PUT /sites/database-users/{id} (api/modules/sites/database-users.yaml).
 * The stored username keeps its original prefix; a changed password
 * re-populates all hash columns.
 */
class UpdateWebDatabaseUserRequest extends SitesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'database_user' => ['sometimes', 'string', 'regex:/^[a-zA-Z0-9_]{2,64}$/'],
            'database_password' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
