<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/webdav-users (api/modules/sites/webdav-users.yaml; legacy
 * form/webdav_user.tform.php + webdav_user_edit.php). The username
 * submitted here is the UN-prefixed name; prefixing, table-wide
 * uniqueness of the full name and the digest hash are handled in the
 * controller.
 */
class StoreWebdavUserRequest extends SitesRequest
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
            'parent_domain_id' => [
                'required',
                'integer',
                Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias']),
            ],
            'username' => ['required', 'string', 'max:64', 'regex:/^[\w\.\-@]{1,64}$/'],
            'password' => ['required', 'string', 'max:255'],
            'dir' => ['required', 'string', 'max:255', $this->noPathTraversalRule()],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
