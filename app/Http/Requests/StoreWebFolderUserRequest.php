<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/web-folder-users (api/modules/sites/web-folder-users.yaml;
 * legacy form/web_folder_user.tform.php + web_folder_user_edit.php). The
 * duplicate (web_folder_id, username) check, CRYPT hashing and derived
 * fields are handled in the controller.
 */
class StoreWebFolderUserRequest extends SitesRequest
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
            'web_folder_id' => [
                'required',
                'integer',
                Rule::exists('web_folder', 'web_folder_id'),
            ],
            'username' => ['required', 'string', 'max:64', 'regex:/^[\w\.\-]{1,64}$/'],
            'password' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
