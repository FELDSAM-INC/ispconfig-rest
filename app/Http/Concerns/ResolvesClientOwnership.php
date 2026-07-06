<?php

namespace App\Http\Concerns;

use App\Models\BaseModel;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Assign a client-owned resource to an owning client on create, mirroring
 * ISPConfig's admin "Client" select (dns_wizard.inc.php:139-260 and every
 * *_edit.php with a client_group_id field): the row's sys_groupid becomes the
 * client's sys_group, while sys_userid and the permission letters keep the
 * BaseModel defaults (acting user, riud/riud/'').
 */
trait ResolvesClientOwnership
{
    /**
     * Resolve $clientId to its sys_group and set it as the model's owning
     * group. Admin may assign to any client; a non-admin only to a client
     * whose group is within its own scope (itself, or a managed sub-client for
     * resellers) — otherwise 403. A client with no sys_group is a 422.
     */
    protected function assignOwningClient(BaseModel $model, int $clientId): void
    {
        $model->setAttribute('sys_groupid', $this->resolveOwningClientGroup($clientId));
    }

    /**
     * Resolve $clientId to its authorized owning sys_group id, applying the
     * same rules as assignOwningClient (admin any; non-admin only within its
     * own scope → 403; groupless/unknown client → 422). Exposed so services
     * that write via a raw insert (WebDomainService, bypassing BaseModel's
     * guard) can enforce the identical check on the record array.
     */
    protected function resolveOwningClientGroup(int $clientId): int
    {
        $groupId = DB::table('sys_group')->where('client_id', $clientId)->value('groupid');

        if ($groupId === null) {
            throw new UnprocessableEntityHttpException(
                "Client {$clientId} has no system group and cannot own this resource."
            );
        }

        $scope = app(IspContext::class)->authScope();
        if (! $scope->isAdmin && ! in_array((int) $groupId, $scope->groupIds, true)) {
            throw new AuthorizationException('You may not assign this resource to that client.');
        }

        return (int) $groupId;
    }
}
