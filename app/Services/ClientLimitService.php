<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client resource-limit checks (spec 011).
 *
 * P2 ships the security-critical access gate only (FR-017): resource types
 * whose legacy limit column defaults to 0 — mail routing (transports),
 * mail access rules and the spamfilter wblist — are DISABLED for a client
 * until the operator books them (limit != 0). Legacy "enforces" this by
 * hiding the menu item (mail/lib/module.conf.php:58-77,104-113;
 * mail_transport_edit.php:60); the API hardens it into a hard 403.
 *
 * The full checkClientLimit()/checkResellerLimit() counting parity
 * (tform.inc.php:183-250 — -1 unlimited / 0 disabled / n counted with the
 * 'u' predicate, plus the reseller cap) is user story 3 (P3), deferred to
 * feature 012 per the pre-authorized deferral gate; it will extend this
 * service with a checkCreate() built on AuthScope::applyReadPredicate('u').
 */
class ClientLimitService
{
    /**
     * Whether the acting scope may use a limit-gated resource type at all.
     *
     * Rules (parity auth.inc.php:124-146 get_client_limit):
     *  - admin scopes: always allowed;
     *  - keys bound to users without a client row: unlimited (-1), allowed;
     *  - otherwise: allowed iff the client's limit column is non-zero
     *    (-1 = unlimited, n > 0 = booked; 0 = feature not booked).
     */
    public function resourceEnabled(AuthScope $scope, string $limitColumn): bool
    {
        if ($scope->isAdmin) {
            return true;
        }

        $limit = $this->limitValue($scope, $limitColumn);

        return $limit === null || $limit !== 0;
    }

    /**
     * The acting client's value for a limit column, or null when the key's
     * user has no client row (legacy get_client_limit returns -1 there —
     * unlimited).
     */
    protected function limitValue(AuthScope $scope, string $limitColumn): ?int
    {
        if ($scope->clientId <= 0 || ! Schema::hasTable('client')) {
            return null;
        }

        $value = DB::table('client')
            ->where('client_id', $scope->clientId)
            ->value($limitColumn);

        return $value === null ? null : (int) $value;
    }
}
