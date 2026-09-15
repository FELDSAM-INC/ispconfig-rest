<?php

namespace App\Http\Requests\Concerns;

use App\Support\IspContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Exists;

/**
 * Spec 024: rows referenced by value in a write body (parent website, DNS
 * zone, database user, web folder, mailbox) must be readable by the acting
 * key, exactly like legacy's `SELECT … WHERE id = ? AND getAuthSQL('r')`
 * lookups (e.g. ftp_user_edit.php:98-100, dns_edit_base.php:100-103). A row
 * the key cannot read fails like a nonexistent one; admin scopes are
 * unaffected (the predicate is a no-op for them).
 */
trait ScopesReferences
{
    /**
     * Restrict an exists rule to rows the acting key can read. The predicate
     * is registered as a query callback, so the validation message stays the
     * one for a nonexistent value.
     */
    protected function readable(Exists $rule): Exists
    {
        return $rule->where(fn ($query) => app(IspContext::class)->authScope()->applyReadPredicate($query, 'r'));
    }

    /**
     * A query over $table limited to rows the acting key can read.
     */
    protected function readableQuery(string $table): Builder
    {
        return app(IspContext::class)->authScope()->applyReadPredicate(DB::table($table), 'r');
    }
}
