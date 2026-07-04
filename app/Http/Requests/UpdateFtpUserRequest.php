<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * PUT /sites/ftp-users/{id} (api/modules/sites/ftp-users.yaml).
 *
 * Partial updates: the stored username keeps its original prefix; a
 * parent_domain_id change re-derives server_id/dir/uid/gid/sys_groupid
 * (handled in the controller).
 */
class UpdateFtpUserRequest extends SitesRequest
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
                'sometimes',
                'integer',
                Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias']),
            ],
            'username' => ['sometimes', 'string', 'max:64', 'regex:/^[\w\.\-@\+]{1,64}$/'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quota_size' => ['sometimes', ...$this->quotaRules()],
            'expires' => ['sometimes', 'nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
