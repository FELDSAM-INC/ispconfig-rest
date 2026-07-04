<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for the monitor-completion feature
 * tests (spec 009: server-status + system-logs). Complements — never
 * duplicates — tests/Support/MonitorSchema.php, which owns sys_datalog,
 * sys_user and server; all tables Schema::hasTable-guarded so module
 * schemas can coexist in one test.
 *
 * Column shapes mirror source_code/install/sql/ispconfig3.sql:
 * monitor_data has a composite PK (server_id, type, created) and a
 * PHP-serialized mediumtext `data` blob; sys_log is keyed by syslog_id.
 */
class MonitorCompletionSchema
{
    public static function create(): void
    {
        if (! Schema::hasTable('monitor_data')) {
            Schema::create('monitor_data', function (Blueprint $table): void {
                $table->unsignedInteger('server_id')->default(0);
                $table->string('type')->default('');
                $table->unsignedInteger('created')->default(0);
                $table->text('data')->nullable();
                // enum('no_state','unknown','ok','info','warning','critical','error')
                $table->string('state')->default('unknown');
                $table->primary(['server_id', 'type', 'created']);
            });
        }

        if (! Schema::hasTable('sys_log')) {
            Schema::create('sys_log', function (Blueprint $table): void {
                $table->increments('syslog_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('datalog_id')->default(0);
                $table->tinyInteger('loglevel')->default(0);
                $table->unsignedInteger('tstamp')->default(0);
                $table->text('message')->nullable();
            });
        }
    }
}
