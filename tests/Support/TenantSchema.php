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
    /**
     * Every client.limit_* column the spec 012 counting/quota fixtures need,
     * with its DDL default (ispconfig3.sql:174-247). Applied on both the
     * create branch and the ensureColumns else branch so any schema
     * composition order yields a fully limit-bearing client table.
     *
     * @var array<string, int>
     */
    private const LIMIT_COLUMNS = [
        // access-gate columns (011) — legacy default 0 = not booked
        'limit_mailrouting' => 0,
        'limit_mail_wblist' => 0,
        'limit_spamfilter_wblist' => 0,
        // reseller meta
        'limit_client' => 0,
        // P1/P2 row-count limits
        'limit_maildomain' => -1,
        'limit_mailbox' => -1,
        'limit_mailalias' => -1,
        'limit_mailforward' => -1,
        'limit_mailcatchall' => -1,
        'limit_mailaliasdomain' => -1,
        'limit_mailfilter' => -1,
        'limit_fetchmail' => -1,
        'limit_web_domain' => -1,
        'limit_web_subdomain' => -1,
        'limit_web_aliasdomain' => -1,
        'limit_ftp_user' => -1,
        'limit_shell_user' => 0,
        'limit_webdav_user' => 0,
        'limit_cron' => 0,
        'limit_cron_frequency' => 5,
        'limit_database' => -1,
        'limit_database_user' => -1,
        'limit_database_postgresql' => -1,
        'limit_dns_zone' => -1,
        'limit_dns_slave_zone' => -1,
        'limit_dns_record' => -1,
        // P3 quota-sum limits
        'limit_mailquota' => -1,
        'limit_web_quota' => -1,
        'limit_traffic_quota' => -1,
        'limit_database_quota' => -1,
    ];

    /**
     * Non-integer client limit columns read by spec 035 with their DDL
     * defaults (ispconfig3.sql client; live 3.3.1p1 information_schema).
     *
     * @var array<string, string>
     */
    private const SITES_LIMIT_COLUMNS = [
        'limit_cron_type' => 'url',
        'ssh_chroot' => 'no,jailkit,ssh-chroot',
    ];

    /**
     * Client web permission columns read by spec 020/021 with their DDL
     * defaults (ispconfig3.sql client; live 3.3.1p1 information_schema).
     *
     * @var array<string, string>
     */
    private const WEB_PERMISSION_COLUMNS = [
        'limit_cgi' => 'n',
        'limit_ssi' => 'n',
        'limit_perl' => 'n',
        'limit_ruby' => 'n',
        'limit_python' => 'n',
        'force_suexec' => 'y',
        'limit_hterror' => 'n',
        'limit_wildcard' => 'n',
        'limit_ssl' => 'n',
        'limit_ssl_letsencrypt' => 'n',
        'limit_directive_snippets' => 'n',
        'limit_backup' => 'y',
        'web_php_options' => 'no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm',
    ];

    /**
     * Client server-assignment columns read by spec 016
     * (ispconfig3.sql client: *_servers CSV lists, default_slave_dnsserver).
     *
     * @var array<int, string>
     */
    private const SERVER_ASSIGNMENT_COLUMNS = [
        'web_servers',
        'mail_servers',
        'db_servers',
        'dns_servers',
        'default_slave_dnsserver',
    ];

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
        } else {
            // Module schemas (DnsSchema, MailSchema, MailCompletionSchema) omit
            // the database-server flag the spec 016 assignment filter reads.
            self::ensureColumns('server', function (Blueprint $table, array $missing): void {
                if (in_array('db_server', $missing, true)) {
                    $table->boolean('db_server')->default(false);
                }
            }, ['db_server']);
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
                if (in_array('active', $missing, true)) {
                    $table->boolean('active')->default(true);
                }
            }, ['groups', 'client_id', 'default_group', 'typ', 'active']);
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

                foreach (self::LIMIT_COLUMNS as $column => $default) {
                    $table->integer($column)->default($default);
                }

                foreach (self::SITES_LIMIT_COLUMNS as $column => $default) {
                    $table->string($column, 255)->default($default);
                }

                self::addServerAssignmentColumns($table, self::SERVER_ASSIGNMENT_COLUMNS);

                self::addWebPermissionColumns($table, array_keys(self::WEB_PERMISSION_COLUMNS));

                // Spec 019: lock/cancel flags and the lock snapshot.
                $table->string('locked', 1)->default('n');
                $table->string('canceled', 1)->default('n');
                $table->text('tmp_data')->nullable();
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
                foreach (self::LIMIT_COLUMNS as $limit => $default) {
                    if (in_array($limit, $missing, true)) {
                        $table->integer($limit)->default($default);
                    }
                }
                foreach (self::SITES_LIMIT_COLUMNS as $column => $default) {
                    if (in_array($column, $missing, true)) {
                        $table->string($column, 255)->default($default);
                    }
                }
                self::addServerAssignmentColumns($table, array_values(array_intersect(self::SERVER_ASSIGNMENT_COLUMNS, $missing)));
                foreach (['locked', 'canceled'] as $flag) {
                    if (in_array($flag, $missing, true)) {
                        $table->string($flag, 1)->default('n');
                    }
                }
                if (in_array('tmp_data', $missing, true)) {
                    $table->text('tmp_data')->nullable();
                }
                self::addWebPermissionColumns($table, array_values(array_intersect(array_keys(self::WEB_PERMISSION_COLUMNS), $missing)));
            }, array_merge(['username', 'contact_name', 'parent_client_id', 'locked', 'canceled', 'tmp_data'], array_keys(self::LIMIT_COLUMNS), array_keys(self::SITES_LIMIT_COLUMNS), self::SERVER_ASSIGNMENT_COLUMNS, array_keys(self::WEB_PERMISSION_COLUMNS)));

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
     * @param  array<int, string>  $columns  subset of WEB_PERMISSION_COLUMNS
     */
    private static function addWebPermissionColumns(Blueprint $table, array $columns): void
    {
        foreach ($columns as $column) {
            $default = self::WEB_PERMISSION_COLUMNS[$column];
            $table->string($column, $column === 'web_php_options' ? 255 : 1)->default($default);
        }
    }

    /**
     * @param  array<int, string>  $columns  subset of SERVER_ASSIGNMENT_COLUMNS
     */
    private static function addServerAssignmentColumns(Blueprint $table, array $columns): void
    {
        foreach ($columns as $column) {
            if ($column === 'default_slave_dnsserver') {
                $table->unsignedInteger($column)->default(0);
            } else {
                $table->text($column)->nullable();
            }
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
