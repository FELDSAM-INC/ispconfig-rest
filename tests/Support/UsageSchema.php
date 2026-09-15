<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig traffic tables for the usage statistics feature
 * tests (spec 017). monitor_data comes from MonitorCompletionSchema; resources,
 * clients and tenants from SitesSchema, MailCompletionSchema and TenantSchema.
 *
 * Column shapes mirror source_code/install/sql/ispconfig3.sql: web_traffic is
 * keyed by (hostname, traffic_date) with a DATE per day written nightly by
 * 200-logfiles; mail_traffic stores one row per mailbox per month as a
 * 'YYYY-MM' string (100-mailbox_stats).
 */
class UsageSchema
{
    public static function create(): void
    {
        if (! Schema::hasTable('web_traffic')) {
            Schema::create('web_traffic', function (Blueprint $table): void {
                $table->string('hostname')->default('');
                $table->date('traffic_date');
                $table->unsignedBigInteger('traffic_bytes')->default(0);
                $table->primary(['hostname', 'traffic_date']);
            });
        }

        if (! Schema::hasTable('mail_traffic')) {
            Schema::create('mail_traffic', function (Blueprint $table): void {
                $table->increments('traffic_id');
                $table->unsignedInteger('mailuser_id')->default(0);
                $table->string('month', 7)->default('');
                $table->unsignedBigInteger('traffic')->default(0);
            });
        }
    }
}
