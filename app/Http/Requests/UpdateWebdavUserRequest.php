<?php

namespace App\Http\Requests;

use App\Models\WebdavUser;

/**
 * PUT /sites/webdav-users/{id} (api/modules/sites/webdav-users.yaml):
 * only `password` and `active` are updatable — `username` and `dir` are
 * immutable (legacy onBeforeUpdate restores the stored values). A changed
 * password is re-digested with the STORED username and dir. Sending the
 * current values is accepted (idempotent PUTs; for `username` the
 * un-prefixed stored name matches).
 */
class UpdateWebdavUserRequest extends SitesRequest
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
                $this->immutableRule(fn () => $this->currentUser()?->username, 'username'),
            ],
            'dir' => [
                'sometimes',
                'string',
                $this->immutableRule(fn () => $this->currentUser()?->getAttributes()['dir'], 'directory'),
            ],
            'parent_domain_id' => [
                'sometimes',
                'integer',
                $this->immutableRule(
                    fn () => $this->currentUser() ? (int) $this->currentUser()->getAttributes()['parent_domain_id'] : null,
                    'parent domain'
                ),
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

    protected function currentUser(): ?WebdavUser
    {
        $user = $this->route('webdavUser');

        return $user instanceof WebdavUser ? $user : null;
    }
}
