<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for server-module feature tests
 * (pattern documented in tests/Support/MailSchema.php — column names
 * verbatim from source_code/install/sql/ispconfig3.sql; shared tables
 * hasTable-guarded so module schemas can coexist).
 *
 * Note: the full `server` table lives HERE (config/updated/dbversion/all
 * role flags) — call ServerSchema::create() BEFORE any other module schema
 * in tests that need the complete table.
 */
class ServerSchema
{
    public static function create(): void
    {
        self::createSharedTables();

        if (! Schema::hasTable('server')) {
            Schema::create('server', function (Blueprint $table): void {
                $table->increments('server_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->string('server_name')->default('');
                $table->unsignedTinyInteger('mail_server')->default(0);
                $table->unsignedTinyInteger('web_server')->default(0);
                $table->unsignedTinyInteger('dns_server')->default(0);
                $table->unsignedTinyInteger('file_server')->default(0);
                $table->unsignedTinyInteger('db_server')->default(0);
                $table->unsignedTinyInteger('vserver_server')->default(0);
                $table->unsignedTinyInteger('proxy_server')->default(0);
                $table->unsignedTinyInteger('firewall_server')->default(0);
                $table->unsignedTinyInteger('xmpp_server')->default(0);
                $table->text('config')->nullable();
                $table->unsignedBigInteger('updated')->default(0);
                $table->unsignedInteger('mirror_server_id')->default(0);
                $table->unsignedInteger('dbversion')->default(1);
                $table->unsignedTinyInteger('active')->default(1);
            });
        }

        if (! Schema::hasTable('server_ip')) {
            Schema::create('server_ip', function (Blueprint $table): void {
                $table->increments('server_ip_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('client_id')->default(0);
                $table->string('ip_type', 4)->default('IPv4');
                $table->string('ip_address', 39)->nullable();
                $table->string('virtualhost', 1)->default('y');
                $table->string('virtualhost_port')->default('80,443');
            });
        }

        if (! Schema::hasTable('server_ip_map')) {
            Schema::create('server_ip_map', function (Blueprint $table): void {
                $table->increments('server_ip_map_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->string('source_ip', 15)->nullable();
                $table->string('destination_ip', 35)->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('firewall')) {
            Schema::create('firewall', function (Blueprint $table): void {
                $table->increments('firewall_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0)->unique();
                $table->text('tcp_port')->nullable();
                $table->text('udp_port')->nullable();
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('server_php')) {
            Schema::create('server_php', function (Blueprint $table): void {
                $table->increments('server_php_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('client_id')->default(0);
                $table->string('name')->nullable();
                $table->string('php_fastcgi_binary')->nullable();
                $table->string('php_fastcgi_ini_dir')->nullable();
                $table->string('php_fpm_init_script')->nullable();
                $table->string('php_fpm_ini_dir')->nullable();
                $table->string('php_fpm_pool_dir')->nullable();
                $table->string('php_fpm_socket_dir')->nullable();
                $table->string('php_cli_binary')->nullable();
                $table->string('php_jk_section')->nullable();
                $table->string('active', 1)->default('y');
                $table->integer('sortprio')->default(100);
            });
        }

        // client_id reference checks on server_ip (400 on bad reference).
        if (! Schema::hasTable('client')) {
            Schema::create('client', function (Blueprint $table): void {
                $table->increments('client_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('company_name', 64)->nullable();
                $table->string('contact_name', 64)->nullable();
                $table->string('username', 64)->nullable();
            });
        }

        // rspamd content_filter switch side effect (config mail section)
        // force-datalogs these rows.
        if (! Schema::hasTable('spamfilter_users')) {
            Schema::create('spamfilter_users', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedTinyInteger('priority')->default(7);
                $table->unsignedInteger('policy_id')->default(0);
                $table->string('email')->default('');
                $table->string('fullname', 64)->nullable();
                $table->string('local', 1)->nullable();
            });
        }

        if (! Schema::hasTable('spamfilter_wblist')) {
            Schema::create('spamfilter_wblist', function (Blueprint $table): void {
                $table->increments('wblist_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->unsignedInteger('server_id')->default(0);
                $table->string('wb', 1)->default('W');
                $table->unsignedInteger('rid')->default(0);
                $table->string('email')->default('');
                $table->string('active', 1)->default('y');
            });
        }
    }

    /**
     * Infrastructure tables every module schema needs; hasTable-guarded so
     * multiple module schemas can be combined in one test run.
     */
    protected static function createSharedTables(): void
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

        if (! Schema::hasTable('sys_user')) {
            Schema::create('sys_user', function (Blueprint $table): void {
                $table->increments('userid');
                $table->unsignedInteger('sys_userid')->default(1);
                $table->unsignedInteger('sys_groupid')->default(1);
                $table->string('username', 64)->default('');
                $table->string('typ', 16)->default('user');
                $table->unsignedInteger('default_group')->default(0);
            });
        }
    }
}
