<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for DNS-module feature tests, column
 * names verbatim from source_code/install/sql/ispconfig3.sql (see
 * MailSchema for the module test-schema pattern). Shared infrastructure
 * tables (sys_datalog, sys_user, server) are Schema::hasTable-guarded so
 * schemas from several modules can coexist in one test.
 */
class DnsSchema
{
    public static function create(): void
    {
        self::createSharedTables();

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
                $table->string('ns')->default('');
                $table->string('mbox')->default('');
                $table->unsignedInteger('serial')->default(1);
                $table->unsignedInteger('refresh')->default(28800);
                $table->unsignedInteger('retry')->default(7200);
                $table->unsignedInteger('expire')->default(604800);
                $table->unsignedInteger('minimum')->default(3600);
                $table->unsignedInteger('ttl')->default(3600);
                $table->string('active', 1)->default('N');
                $table->text('xfer')->nullable();
                $table->text('also_notify')->nullable();
                $table->string('update_acl')->nullable();
                $table->string('dnssec_initialized', 1)->default('N');
                $table->string('dnssec_wanted', 1)->default('N');
                $table->string('dnssec_algo', 64)->default('ECDSAP256SHA256');
                $table->bigInteger('dnssec_last_signed')->default(0);
                $table->text('dnssec_info')->nullable();
                $table->text('rendered_zone')->nullable();
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

        if (! Schema::hasTable('dns_slave')) {
            Schema::create('dns_slave', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->integer('server_id')->default(1);
                $table->string('origin')->default('');
                $table->string('ns')->default('');
                $table->string('active', 1)->default('N');
                $table->text('xfer')->nullable();
            });
        }

        if (! Schema::hasTable('dns_template')) {
            Schema::create('dns_template', function (Blueprint $table): void {
                $table->increments('template_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->string('name', 64)->nullable();
                $table->string('fields')->nullable();
                $table->text('template')->nullable();
                $table->string('visible', 1)->default('Y');
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

        if (! Schema::hasTable('sys_group')) {
            Schema::create('sys_group', function (Blueprint $table): void {
                $table->increments('groupid');
                $table->string('name', 64)->default('');
                $table->text('description')->nullable();
                $table->unsignedInteger('client_id')->default(0);
            });
        }

        if (! Schema::hasTable('client')) {
            Schema::create('client', function (Blueprint $table): void {
                $table->increments('client_id');
                $table->string('username', 64)->default('');
                $table->unsignedInteger('parent_client_id')->default(0);
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
