<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * A reseller — the same `client` table scoped to rows satisfying the legacy
 * reseller condition limit_client > 0 OR limit_client = -1 (contract:
 * api/components/schemas/ClientReseller.yaml; legacy: reseller_list /
 * reseller.tform.php limit_client validator).
 *
 * The global scope guarantees /resellers endpoints only ever read or bind
 * reseller rows: route-model binding on a plain client id 404s.
 */
class ClientReseller extends Client
{
    protected static function booted(): void
    {
        static::addGlobalScope('reseller', function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->where('limit_client', '>', 0)
                    ->orWhere('limit_client', -1);
            });
        });
    }
}
