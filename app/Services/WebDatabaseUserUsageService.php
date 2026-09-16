<?php

namespace App\Services;

use App\Support\IspContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many databases depend on a database user (spec 039).
 *
 * A database references a user as its credentials (`database_user_id`) or as
 * its read-only user (`database_ro_user_id`); legacy
 * database_user_del.php::onBeforeDelete() refuses a delete while either points
 * at the user. The same count feeds the `databases_in_use` field, so the
 * number a panel shows and the guard that refuses always agree.
 *
 * Counting is scoped to the rows the acting key may read (spec 011), and a
 * whole page is counted with one query — list screens never issue a query per
 * row.
 */
class WebDatabaseUserUsageService
{
    /**
     * Databases visible to the acting key that reference this user.
     */
    public function countFor(int $userId): int
    {
        return $this->countsFor([$userId])[$userId] ?? 0;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int> user id => number of databases (0 for every requested id without rows)
     */
    public function countsFor(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn ($id): bool => (int) $id > 0)));
        $counts = array_fill_keys($userIds, 0);

        if ($userIds === [] || ! Schema::hasTable('web_database')) {
            return $counts;
        }

        $query = DB::table('web_database')
            ->where(function ($builder) use ($userIds): void {
                $builder->whereIn('database_user_id', $userIds)
                    ->orWhereIn('database_ro_user_id', $userIds);
            });

        app(IspContext::class)->authScope()->applyReadPredicate($query, 'r');

        $rows = $query->get(['database_id', 'database_user_id', 'database_ro_user_id']);

        // Tallied in PHP so a database naming the same user in both columns
        // counts once — a GROUP BY over two columns cannot express that.
        $seen = [];

        foreach ($rows as $row) {
            foreach ([$row->database_user_id, $row->database_ro_user_id] as $referenced) {
                $referenced = (int) $referenced;

                if (! array_key_exists($referenced, $counts)) {
                    continue;
                }

                $key = $referenced.':'.(int) $row->database_id;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $counts[$referenced]++;
            }
        }

        return $counts;
    }
}
