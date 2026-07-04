<?php

namespace App\Http\Requests;

use App\Models\ShellUser;
use Closure;
use Illuminate\Validation\Rule;

/**
 * PUT /sites/shell-users/{id} (api/modules/sites/shell-users.yaml).
 */
class UpdateShellUserRequest extends SitesRequest
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
            'username' => [
                'sometimes', 'string', 'max:32', 'regex:/^[\w\.\-]{1,32}$/',
                $this->usernameNotBlacklistedRule(),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssh_rsa' => ['sometimes', 'nullable', 'string', 'max:600'],
            'chroot' => ['sometimes', Rule::in(['no', 'jailkit'])],
            'shell' => ['sometimes', 'string', 'max:255'],
            'quota_size' => ['sometimes', ...$this->quotaRules()],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function usernameNotBlacklistedRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $candidate = strtolower(trim($value));

            if (in_array($candidate, ShellUser::USERNAME_BLACKLIST, true)
                || ! ShellUser::isAllowedUser($candidate)) {
                $fail('This username is not allowed.');
            }
        };
    }
}
