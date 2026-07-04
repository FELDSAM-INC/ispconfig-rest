<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /sites/ftp-users (api/modules/sites/ftp-users.yaml; legacy
 * form/ftp_user.tform.php + ftp_user_edit.php). The username submitted
 * here is the UN-prefixed name; prefixing, full-name uniqueness and the
 * parent-derived fields are handled in the controller/service.
 */
class StoreFtpUserRequest extends SitesRequest
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
            'username' => ['required', 'string', 'max:64', 'regex:/^[\w\.\-@\+]{1,64}$/'],
            'password' => ['required', 'string', 'max:255'],
            'quota_size' => ['sometimes', ...$this->quotaRules()],
            'expires' => ['sometimes', 'nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
