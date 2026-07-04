<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for system-module feature tests
 * (column names verbatim from source_code/install/sql/ispconfig3.sql; see
 * MailSchema for the module-schema pattern). Includes minimal versions of
 * every table the resync tool re-emits, so a resync_all run can be asserted
 * end-to-end. Shared infrastructure tables are hasTable-guarded.
 */
class SystemSchema
{
    public static function create(): void
    {
        self::createSharedTables();

        if (! Schema::hasTable('sys_ini')) {
            Schema::create('sys_ini', function (Blueprint $table): void {
                $table->increments('sysini_id');
                $table->text('config')->nullable();
                $table->text('default_logo')->nullable();
                $table->text('custom_logo')->nullable();
            });
        }

        if (! Schema::hasTable('directive_snippets')) {
            Schema::create('directive_snippets', function (Blueprint $table): void {
                $table->increments('directive_snippets_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->string('name')->nullable();
                $table->string('type')->nullable();
                $table->text('snippet')->nullable();
                $table->string('customer_viewable', 1)->default('n');
                $table->string('required_php_snippets')->default('');
                $table->string('active', 1)->default('y');
                $table->unsignedInteger('master_directive_snippets_id')->default(0);
                $table->string('update_sites', 1)->default('n');
            });
        }

        if (! Schema::hasTable('dns_ssl_ca')) {
            Schema::create('dns_ssl_ca', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->string('active', 1)->default('N');
                $table->string('ca_name')->default('');
                $table->string('ca_issue')->default('');
                $table->string('ca_wildcard', 1)->default('N');
                $table->text('ca_iodef');
                $table->boolean('ca_critical')->default(false);
                $table->unique('ca_issue');
            });
        }

        if (! Schema::hasTable('web_domain')) {
            Schema::create('web_domain', function (Blueprint $table): void {
                $table->increments('domain_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('domain')->default('');
                $table->string('type', 32)->default('vhost');
                $table->unsignedInteger('directive_snippets_id')->default(0);
                $table->string('active', 1)->default('y');
            });
        }

        // ------------------------------------------------------------------
        // Tables the resync tool re-emits (tools/resync.php), minimal shapes.
        // ------------------------------------------------------------------

        if (! Schema::hasTable('ftp_user')) {
            Schema::create('ftp_user', function (Blueprint $table): void {
                $table->increments('ftp_user_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('username', 64)->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('webdav_user')) {
            Schema::create('webdav_user', function (Blueprint $table): void {
                $table->increments('webdav_user_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('username', 64)->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('shell_user')) {
            Schema::create('shell_user', function (Blueprint $table): void {
                $table->increments('shell_user_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('username', 64)->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('cron')) {
            Schema::create('cron', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('server_id')->default(0);
                $table->text('command')->nullable();
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('web_database_user')) {
            // No `active` column — legacy resyncs ALL rows of this table.
            Schema::create('web_database_user', function (Blueprint $table): void {
                $table->increments('database_user_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('database_user', 64)->default('');
            });
        }

        if (! Schema::hasTable('web_database')) {
            Schema::create('web_database', function (Blueprint $table): void {
                $table->increments('database_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('database_name', 64)->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_domain')) {
            Schema::create('mail_domain', function (Blueprint $table): void {
                $table->increments('domain_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('domain')->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('spamfilter_policy')) {
            // No server_id column — legacy never server-filters this table.
            Schema::create('spamfilter_policy', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('policy_name', 64)->default('');
            });
        }

        if (! Schema::hasTable('mail_get')) {
            Schema::create('mail_get', function (Blueprint $table): void {
                $table->increments('mailget_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('source_username', 64)->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_user')) {
            Schema::create('mail_user', function (Blueprint $table): void {
                $table->increments('mailuser_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('email')->default('');
            });
        }

        if (! Schema::hasTable('mail_forwarding')) {
            Schema::create('mail_forwarding', function (Blueprint $table): void {
                $table->increments('forwarding_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('source')->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_access')) {
            Schema::create('mail_access', function (Blueprint $table): void {
                $table->increments('access_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('source')->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_content_filter')) {
            Schema::create('mail_content_filter', function (Blueprint $table): void {
                $table->increments('content_filter_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->text('pattern')->nullable();
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_user_filter')) {
            // No server_id column — legacy never server-filters this table.
            Schema::create('mail_user_filter', function (Blueprint $table): void {
                $table->increments('filter_id');
                $table->unsignedInteger('mailuser_id')->default(0);
                $table->string('rulename', 64)->default('');
            });
        }

        if (! Schema::hasTable('spamfilter_users')) {
            Schema::create('spamfilter_users', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('email')->default('');
            });
        }

        if (! Schema::hasTable('spamfilter_wblist')) {
            Schema::create('spamfilter_wblist', function (Blueprint $table): void {
                $table->increments('wblist_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('email')->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_mailinglist')) {
            Schema::create('mail_mailinglist', function (Blueprint $table): void {
                $table->increments('mailinglist_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('listname', 64)->default('');
            });
        }

        if (! Schema::hasTable('mail_transport')) {
            Schema::create('mail_transport', function (Blueprint $table): void {
                $table->increments('transport_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('domain')->default('');
            });
        }

        if (! Schema::hasTable('mail_relay_recipient')) {
            Schema::create('mail_relay_recipient', function (Blueprint $table): void {
                $table->increments('relay_recipient_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('source')->default('');
            });
        }

        if (! Schema::hasTable('openvz_vm')) {
            Schema::create('openvz_vm', function (Blueprint $table): void {
                $table->increments('vm_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('hostname')->default('');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('client')) {
            // No server_id column — legacy re-emits every client row.
            Schema::create('client', function (Blueprint $table): void {
                $table->increments('client_id');
                $table->string('contact_name', 64)->default('');
            });
        }

        // DNS resync path (serial bumps) — uppercase Y/N flags per DDL.
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
                $table->string('active', 1)->default('Y');
                $table->unsignedInteger('serial')->nullable();
            });
        }
    }

    /**
     * Infrastructure tables every module schema needs; hasTable-guarded so
     * multiple module schemas can be combined in one test run. The server
     * table carries the full contract column set (Server.yaml) because the
     * resync servers endpoint projects it.
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
                $table->unsignedInteger('sys_userid')->default(1);
                $table->unsignedInteger('sys_groupid')->default(1);
                $table->string('sys_perm_user', 5)->default('riud');
                $table->string('sys_perm_group', 5)->default('riud');
                $table->string('sys_perm_other', 5)->default('');
                $table->string('server_name')->default('');
                $table->boolean('mail_server')->default(false);
                $table->boolean('web_server')->default(false);
                $table->boolean('dns_server')->default(false);
                $table->boolean('file_server')->default(false);
                $table->boolean('db_server')->default(false);
                $table->boolean('vserver_server')->default(false);
                $table->boolean('proxy_server')->default(false);
                $table->boolean('firewall_server')->default(false);
                $table->boolean('xmpp_server')->default(false);
                $table->unsignedInteger('mirror_server_id')->default(0);
                $table->unsignedInteger('updated')->default(0);
                $table->unsignedInteger('dbversion')->default(1);
                $table->boolean('active')->default(true);
            });
        }
    }
}
