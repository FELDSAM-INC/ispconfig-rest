<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant tables for authorization-matrix tests (spec 011).
 *
 * Follows the module-schema pattern (see MailSchema): hasTable-guarded
 * creates so it composes with any module schema in either order. Because
 * some module schemas ship a minimal sys_user/sys_group, this helper also
 * ADDS the columns the AuthScope resolution reads (sys_user.groups /
 * client_id, …) when the table pre-exists without them — column names
 * verbatim from source_code/install/sql/ispconfig3.sql:1852-1880 (sys_user),
 * :1734-1740 (sys_group), :139-260 (client limit defaults).
 */
class TenantSchema
{
    public static function create(): void
    {
        if (! Schema::hasTable('sys_datalog')) {
            Schema::create('sys_datalog', function (Blueprint $table): void {
                $table->increments('datalog_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('dbtable')->default('');
                $table->string('dbidx')->default('');
                $table->string('action', 1)->default('');
                $table->integer('tstamp')->default(0);
                $table->string('user')->default('');
                $table->text('data')->nullable();
                $table->string('status')->default('ok');
                $table->text('error')->nullable();
                $table->string('session_id', 64)->default('');
            });
        }

        if (! Schema::hasTable('server')) {
            Schema::create('server', function (Blueprint $table): void {
                $table->increments('server_id');
                $table->string('server_name')->default('');
                $table->boolean('mail_server')->default(false);
                $table->boolean('web_server')->default(false);
                $table->boolean('dns_server')->default(false);
                $table->boolean('db_server')->default(false);
                $table->unsignedInteger('mirror_server_id')->default(0);
                $table->boolean('active')->default(true);
            });
        }

        if (! Schema::hasTable('sys_user')) {
            Schema::create('sys_user', function (Blueprint $table): void {
                $table->increments('userid');
                $table->unsignedInteger('sys_userid')->default(1);
                $table->unsignedInteger('sys_groupid')->default(1);
                $table->string('sys_perm_user', 5)->default('riud');
                $table->string('sys_perm_group', 5)->default('riud');
                $table->string('sys_perm_other', 5)->default('');
                $table->string('username', 64)->default('');
                $table->string('passwort', 200)->default('');
                $table->string('modules')->default('');
                $table->string('startmodule')->default('');
                $table->string('typ', 16)->default('user');
                $table->boolean('active')->default(true);
                $table->string('language', 2)->default('en');
                $table->text('groups')->nullable();
                $table->unsignedInteger('default_group')->default(0);
                $table->unsignedInteger('client_id')->default(0);
            });
        } else {
            // Minimal module-schema sys_user (MailSchema & friends): add the
            // columns AuthScope resolution reads.
            self::ensureColumns('sys_user', function (Blueprint $table, array $missing): void {
                if (in_array('groups', $missing, true)) {
                    $table->text('groups')->nullable();
                }
                if (in_array('client_id', $missing, true)) {
                    $table->unsignedInteger('client_id')->default(0);
                }
                if (in_array('default_group', $missing, true)) {
                    $table->unsignedInteger('default_group')->default(0);
                }
                if (in_array('typ', $missing, true)) {
                    $table->string('typ', 16)->default('user');
                }
            }, ['groups', 'client_id', 'default_group', 'typ']);
        }

        if (! Schema::hasTable('sys_group')) {
            Schema::create('sys_group', function (Blueprint $table): void {
                $table->increments('groupid');
                $table->string('name', 64)->default('');
                $table->text('description')->nullable();
                $table->unsignedInteger('client_id')->default(0);
            });
        }

        // The client-column subset scoping/limit gates read; the full-width
        // table (ClientSchema) satisfies the guard when it came first, and a
        // module schema's minimal client table gets the missing columns.
        if (! Schema::hasTable('client')) {
            Schema::create('client', function (Blueprint $table): void {
                $table->increments('client_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->string('username', 64)->nullable();
                $table->string('contact_name', 64)->nullable();
                $table->unsignedInteger('parent_client_id')->default(0);
                $table->integer('limit_client')->default(0);
                // FR-017 access-gate columns (legacy defaults 0 = not booked)
                $table->integer('limit_mailrouting')->default(0);
                $table->integer('limit_mail_wblist')->default(0);
                $table->integer('limit_spamfilter_wblist')->default(0);
                // Common P3 columns so limit fixtures stay reusable
                $table->integer('limit_maildomain')->default(-1);
                $table->integer('limit_dns_zone')->default(-1);
                $table->integer('limit_web_domain')->default(-1);
            });
        } else {
            self::ensureColumns('client', function (Blueprint $table, array $missing): void {
                if (in_array('username', $missing, true)) {
                    $table->string('username', 64)->nullable();
                }
                if (in_array('contact_name', $missing, true)) {
                    $table->string('contact_name', 64)->nullable();
                }
                if (in_array('parent_client_id', $missing, true)) {
                    $table->unsignedInteger('parent_client_id')->default(0);
                }
                foreach (['limit_client', 'limit_mailrouting', 'limit_mail_wblist', 'limit_spamfilter_wblist'] as $limit) {
                    if (in_array($limit, $missing, true)) {
                        $table->integer($limit)->default(0);
                    }
                }
            }, ['username', 'contact_name', 'parent_client_id', 'limit_client', 'limit_mailrouting', 'limit_mail_wblist', 'limit_spamfilter_wblist']);

            self::ensureSysFields(['client']);
        }
    }

    /**
     * Add the ISPConfig system fields to tables whose module test schema
     * omitted some of them (the real DDL always carries all five — verified
     * against ispconfig3.sql for every scoped table).
     *
     * @param  array<int, string>  $tables
     */
    public static function ensureSysFields(array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $missing = array_values(array_filter(
                ['sys_userid', 'sys_groupid', 'sys_perm_user', 'sys_perm_group', 'sys_perm_other'],
                fn (string $column): bool => ! Schema::hasColumn($table, $column)
            ));

            if ($missing === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $column) {
                    if (in_array($column, ['sys_userid', 'sys_groupid'], true)) {
                        $blueprint->unsignedInteger($column)->default(0);
                    } else {
                        $blueprint->string($column, 5)->default('');
                    }
                }
            });
        }
    }

    /**
     * @param  callable(Blueprint, array<int, string>): void  $adder
     * @param  array<int, string>  $columns
     */
    protected static function ensureColumns(string $table, callable $adder, array $columns): void
    {
        $missing = array_values(array_filter(
            $columns,
            fn (string $column): bool => ! Schema::hasColumn($table, $column)
        ));

        if ($missing === []) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $adder($blueprint, $missing));
    }
}
