<?php

namespace App\Services;

use App\Models\BaseModel;
use App\Models\ClientDomain;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Keep aliases selectable in ISPConfig's Client Domains dropdown (spec 044). */
class AliasClientDomainService
{
    public function __construct(
        protected SitesConfigService $config,
        protected IspContext $context,
    ) {}

    /** Called after alias creation, inside the same transaction and change set. */
    public function ensure(BaseModel $alias): void
    {
        if (! in_array($alias->getAttribute('type'), ['alias', 'vhostalias'], true)
            || ($this->config->globalConfig('domains')['use_domain_module'] ?? 'n') !== 'y') {
            return;
        }

        $groupId = (int) DB::table('web_domain')
            ->where('domain_id', $alias->getAttribute('parent_domain_id'))
            ->value('sys_groupid');
        $scope = $this->context->authScope();
        if ((int) $alias->getAttribute('sys_groupid') !== $groupId
            || (! $scope->isAdmin && ! in_array($groupId, $scope->groupIds, true))) {
            throw new AuthorizationException('The parent website is not owned by this account.');
        }

        // An administrator's unassigned website has no client domain dropdown.
        if (! DB::table('sys_group')->join('client', 'client.client_id', '=', 'sys_group.client_id')
            ->where('sys_group.groupid', $groupId)->exists()) {
            return;
        }

        $name = (string) $alias->getAttribute('domain');
        if ($this->alreadyRegistered($name, $groupId)) {
            return;
        }

        try {
            // A savepoint lets a concurrent unique-key collision be rechecked
            // without leaving the outer alias transaction in an aborted state.
            DB::transaction(function () use ($name, $groupId): void {
                $domain = new ClientDomain(['domain' => $name]);
                $domain->setAttribute('sys_groupid', $groupId);
                $domain->setAttribute('sys_perm_group', 'ru');
                $domain->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->alreadyRegistered($name, $groupId)) {
                throw $e;
            }
        }
    }

    private function alreadyRegistered(string $name, int $groupId): bool
    {
        // Global uniqueness must include other tenants. Only compare ownership;
        // never return the existing row or its owner's identity to the caller.
        $existing = ClientDomain::query()->where('domain', $name)->lockForUpdate()->first();
        if ($existing === null) {
            return false;
        }
        if ((int) $existing->sys_groupid !== $groupId) {
            throw new ConflictHttpException('This domain is already registered to another account.');
        }

        return true;
    }
}
