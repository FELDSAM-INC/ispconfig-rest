<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/database-users (api/modules/sites/database-users.yaml;
 * legacy form/database_user.tform.php + database_user_edit.php). The
 * database_user submitted here is the UN-prefixed name; prefixing, the
 * ≤32-char cap on the prefixed name, the blacklist and the hash trio are
 * handled in the controller.
 */
class StoreWebDatabaseUserRequest extends SitesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'database_user' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{2,64}$/'],
            'database_password' => ['required', 'string', 'max:64'],
            // Optional owning client (resolved to its sys_group on create).
            'client_id' => ['sometimes', 'integer', Rule::exists('client', 'client_id')],
        ];
    }
}
