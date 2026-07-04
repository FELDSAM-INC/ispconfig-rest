<?php

namespace App\Support;

/**
 * One row of the ISPConfig client-limit classification table (spec 012):
 * the resolved counting/quota configuration for a single gated create.
 *
 * A model's table (and, for the type-discriminated tables, its `type`
 * attribute) resolves to zero or more LimitSpecs in
 * ClientLimitService::countSpecsFor() / quotaSpecsFor(). Each spec maps to
 * exactly one legacy checkClientLimit()/checkResellerLimit() (or the P3
 * quota-SUM) call site.
 *
 * Immutable — the map is a set of constant descriptors.
 */
final class LimitSpec
{
    /**
     * @param  string  $limitColumn  the client.limit_* column that caps this resource
     * @param  string  $table  the resource table to count/sum
     * @param  string  $pk  the table's primary key (COUNT column / exclude-id column)
     * @param  array<int, string>|null  $typeValues  when set, an `type IN (...)` filter
     *                                               narrows the count to these row kinds
     * @param  string  $predicate  'u' = AuthScope::applyReadPredicate($q,'u')
     *                             (legacy getAuthSQL('u')); 'grp' = the bespoke
     *                             `sys_groupid = {scope.sysGroupId}` predicate
     *                             (limit_client, limit_database_postgresql,
     *                             limit_database_quota)
     * @param  bool  $resellerCap  also enforce the parent reseller's cap
     *                             (checkResellerLimit) — true for every 'u' spec,
     *                             false for the bespoke `limit_client`
     * @param  string  $label  human resource name for the 403 detail
     * @param  string|null  $quotaColumn  the SUM(quota) column (P3 quota-sum specs
     *                                    only; null for row-count specs)
     * @param  int  $quotaDivisor  DB-unit → MB divisor for the quota column
     *                             (mail_user.quota is bytes → 1048576; web/db MB → 1)
     */
    public function __construct(
        public readonly string $limitColumn,
        public readonly string $table,
        public readonly string $pk,
        public readonly ?array $typeValues,
        public readonly string $predicate,
        public readonly bool $resellerCap,
        public readonly string $label,
        public readonly ?string $quotaColumn = null,
        public readonly int $quotaDivisor = 1,
    ) {}
}
