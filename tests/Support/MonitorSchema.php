<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for monitor-module feature tests
 * (pattern documented in tests/Support/MailSchema.php: modules ship their
 * own schema helper; ISPConfig tables are never migrated).
 *
 * The data-logs endpoints only read sys_datalog plus the server table's
 * `updated` datalog-ID watermark, so this schema is just the shared
 * infrastructure tables — all Schema::hasTable-guarded so schemas from
 * several modules can coexist in one test.
 */
class MonitorSchema
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

        // `updated` is the per-server datalog-ID watermark (ispconfig3.sql:
        // bigint default 0) driving the unprocessed_only filter.
        if (! Schema::hasTable('server')) {
            Schema::create('server', function (Blueprint $table): void {
                $table->increments('server_id');
                $table->string('server_name')->default('');
                $table->boolean('mail_server')->default(false);
                $table->boolean('web_server')->default(false);
                $table->boolean('dns_server')->default(false);
                $table->unsignedBigInteger('updated')->default(0);
                $table->unsignedInteger('mirror_server_id')->default(0);
                $table->boolean('active')->default(true);
            });
        }
    }
}
