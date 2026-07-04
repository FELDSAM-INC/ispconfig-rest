<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for mail-module feature tests.
 *
 * PATTERN FOR MODULE TEST SCHEMAS
 * -------------------------------
 * database/migrations only contains API-owned tables (api_keys) — ISPConfig
 * tables must never be migrated (constitution, Code Boundaries). Each module
 * therefore ships its own tests/Support/<Module>Schema.php that creates the
 * subset of ISPConfig tables its endpoints touch, column names verbatim from
 * source_code/install/sql/ispconfig3.sql. Call <Module>Schema::create() in
 * the test's setUp() after RefreshDatabase has run. Shared infrastructure
 * tables (sys_datalog, sys_user, server) are guarded with Schema::hasTable()
 * so schemas from several modules can coexist in one test — no shared file
 * is ever edited when a new module lands.
 */
class MailSchema
{
    public static function create(): void
    {
        self::createSharedTables();

        if (! Schema::hasTable('mail_domain')) {
            Schema::create('mail_domain', function (Blueprint $table): void {
                $table->increments('domain_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('domain')->default('');
                $table->string('dkim', 1)->default('n');
                $table->string('dkim_selector', 63)->default('default');
                $table->text('dkim_private')->nullable();
                $table->text('dkim_public')->nullable();
                $table->string('relay_host')->default('');
                $table->string('relay_user')->default('');
                $table->string('relay_pass')->default('');
                $table->string('active', 1)->default('n');
                $table->string('local_delivery', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_user')) {
            Schema::create('mail_user', function (Blueprint $table): void {
                $table->increments('mailuser_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->unsignedInteger('server_id')->default(0);
                $table->string('email')->default('');
                $table->string('login')->default('');
                $table->string('maildir')->default('');
            });
        }

        if (! Schema::hasTable('mail_forwarding')) {
            Schema::create('mail_forwarding', function (Blueprint $table): void {
                $table->increments('forwarding_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->unsignedInteger('server_id')->default(0);
                $table->string('source')->default('');
                $table->text('destination')->nullable();
                $table->string('type')->default('alias');
                $table->string('active', 1)->default('n');
            });
        }

        if (! Schema::hasTable('mail_get')) {
            Schema::create('mail_get', function (Blueprint $table): void {
                $table->increments('mailget_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->unsignedInteger('server_id')->default(0);
                $table->string('destination')->nullable();
                $table->string('active')->default('y');
            });
        }

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

        // DKIM DNS side effects (mail_domain_edit.php::update_dns) touch the
        // DNS module's tables, so the mail schema needs them too.
        if (! Schema::hasTable('dns_soa')) {
            Schema::create('dns_soa', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->integer('server_id')->default(1);
                $table->string('origin')->default('');
                $table->unsignedInteger('serial')->default(1);
                $table->unsignedInteger('ttl')->default(3600);
                $table->string('active', 1)->default('N');
            });
        }

        if (! Schema::hasTable('dns_rr')) {
            Schema::create('dns_rr', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->integer('server_id')->default(1);
                $table->unsignedInteger('zone')->default(0);
                $table->string('name')->default('');
                $table->string('type', 10)->nullable();
                $table->text('data');
                $table->unsignedInteger('aux')->default(0);
                $table->unsignedInteger('ttl')->default(3600);
                $table->string('active', 1)->default('Y');
                $table->string('stamp')->nullable();
                $table->unsignedInteger('serial')->nullable();
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

        if (! Schema::hasTable('server')) {
            Schema::create('server', function (Blueprint $table): void {
                $table->increments('server_id');
                $table->string('server_name')->default('');
                $table->boolean('mail_server')->default(false);
                $table->boolean('web_server')->default(false);
                $table->boolean('dns_server')->default(false);
                $table->unsignedInteger('mirror_server_id')->default(0);
                $table->boolean('active')->default(true);
            });
        }
    }
}
