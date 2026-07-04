<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for sites-module feature tests,
 * column names verbatim from source_code/install/sql/ispconfig3.sql
 * (see MailSchema for the module-schema pattern). Shared infrastructure
 * tables (sys_datalog, sys_user, sys_group, server, sys_ini, client) are
 * hasTable-guarded so schemas from several modules can coexist.
 *
 * The sites `server` table carries the serialized `config` INI blob
 * (website_path / prefixes / SNI / ip_address) that SitesConfigService
 * reads, plus the web_server/db_server role flags the requests validate
 * against.
 */
class SitesSchema
{
    public static function create(): void
    {
        self::createSharedTables();

        if (! Schema::hasTable('web_domain')) {
            Schema::create('web_domain', function (Blueprint $table): void {
                $table->increments('domain_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->string('ip_address', 39)->nullable();
                $table->string('ipv6_address')->nullable();
                $table->string('domain')->nullable();
                $table->string('type', 32)->nullable();
                $table->unsignedInteger('parent_domain_id')->default(0);
                $table->string('vhost_type', 32)->nullable();
                $table->string('document_root')->nullable();
                $table->string('web_folder', 100)->nullable();
                $table->string('system_user')->nullable();
                $table->string('system_group')->nullable();
                $table->bigInteger('hd_quota')->default(0);
                $table->bigInteger('traffic_quota')->default(-1);
                $table->string('cgi', 1)->default('y');
                $table->string('ssi', 1)->default('y');
                $table->string('suexec', 1)->default('y');
                $table->unsignedTinyInteger('errordocs')->default(1);
                $table->unsignedTinyInteger('is_subdomainwww')->default(1);
                $table->string('subdomain', 16)->default('none');
                $table->string('php', 32)->default('y');
                $table->string('ruby', 1)->default('n');
                $table->string('python', 1)->default('n');
                $table->string('perl', 1)->default('n');
                $table->string('redirect_type')->nullable();
                $table->string('redirect_path')->nullable();
                $table->string('seo_redirect')->nullable();
                $table->string('rewrite_to_https', 1)->default('n');
                $table->string('ssl', 1)->default('n');
                $table->string('ssl_letsencrypt', 1)->default('n');
                $table->string('ssl_letsencrypt_exclude', 1)->default('n');
                $table->string('ssl_state')->nullable();
                $table->string('ssl_locality')->nullable();
                $table->string('ssl_organisation')->nullable();
                $table->string('ssl_organisation_unit')->nullable();
                $table->string('ssl_country')->nullable();
                $table->string('ssl_domain')->nullable();
                $table->text('ssl_request')->nullable();
                $table->text('ssl_cert')->nullable();
                $table->text('ssl_bundle')->nullable();
                $table->text('ssl_key')->nullable();
                $table->string('ssl_action', 16)->nullable();
                $table->string('stats_password')->nullable();
                $table->string('stats_type')->default('awstats');
                $table->string('allow_override')->default('All');
                $table->text('apache_directives')->nullable();
                $table->text('nginx_directives')->nullable();
                $table->string('php_fpm_use_socket', 1)->default('y');
                $table->string('php_fpm_chroot', 1)->default('n');
                $table->string('pm', 16)->default('ondemand');
                $table->integer('pm_max_children')->default(10);
                $table->integer('pm_start_servers')->default(2);
                $table->integer('pm_min_spare_servers')->default(1);
                $table->integer('pm_max_spare_servers')->default(5);
                $table->integer('pm_process_idle_timeout')->default(10);
                $table->integer('pm_max_requests')->default(0);
                $table->text('php_open_basedir')->nullable();
                $table->text('custom_php_ini')->nullable();
                $table->string('backup_interval')->default('none');
                $table->integer('backup_copies')->default(1);
                $table->string('backup_format_web')->default('default');
                $table->string('backup_format_db')->default('gzip');
                $table->string('backup_encrypt', 1)->default('n');
                $table->string('backup_password')->default('');
                $table->text('backup_excludes')->nullable();
                $table->string('active', 1)->default('y');
                $table->string('traffic_quota_lock', 1)->default('n');
                $table->text('proxy_directives')->nullable();
                $table->date('last_quota_notification')->nullable();
                $table->text('rewrite_rules')->nullable();
                $table->date('added_date')->nullable();
                $table->string('added_by')->nullable();
                $table->unsignedInteger('directive_snippets_id')->default(0);
                $table->string('enable_pagespeed', 1)->default('n');
                $table->unsignedInteger('http_port')->default(80);
                $table->unsignedInteger('https_port')->default(443);
                $table->text('folder_directive_snippets')->nullable();
                $table->integer('log_retention')->default(10);
                $table->string('proxy_protocol', 1)->default('n');
                $table->unsignedInteger('server_php_id')->default(0);
                $table->text('jailkit_chroot_app_sections')->nullable();
                $table->text('jailkit_chroot_app_programs')->nullable();
                $table->string('delete_unused_jailkit', 1)->default('n');
                $table->date('last_jailkit_update')->nullable();
                $table->string('last_jailkit_hash')->nullable();
                $table->string('disable_symlinknotowner', 1)->default('n');
            });
        }

        if (! Schema::hasTable('ftp_user')) {
            Schema::create('ftp_user', function (Blueprint $table): void {
                $table->increments('ftp_user_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('parent_domain_id')->default(0);
                $table->string('username', 64)->nullable();
                $table->string('username_prefix', 50)->default('');
                $table->string('password', 200)->nullable();
                $table->bigInteger('quota_size')->default(-1);
                $table->string('active', 1)->default('y');
                $table->string('uid', 64)->nullable();
                $table->string('gid', 64)->nullable();
                $table->string('dir')->nullable();
                $table->bigInteger('quota_files')->default(-1);
                $table->integer('ul_ratio')->default(-1);
                $table->integer('dl_ratio')->default(-1);
                $table->integer('ul_bandwidth')->default(-1);
                $table->integer('dl_bandwidth')->default(-1);
                $table->dateTime('expires')->nullable();
                $table->string('user_type', 16)->default('user');
                $table->text('user_config')->nullable();
            });
        }

        if (! Schema::hasTable('shell_user')) {
            Schema::create('shell_user', function (Blueprint $table): void {
                $table->increments('shell_user_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('parent_domain_id')->default(0);
                $table->string('username', 64)->nullable();
                $table->string('username_prefix', 50)->default('');
                $table->string('password', 200)->nullable();
                $table->bigInteger('quota_size')->default(-1);
                $table->string('active', 1)->default('y');
                $table->string('puser')->nullable();
                $table->string('pgroup')->nullable();
                $table->string('shell')->default('/bin/bash');
                $table->string('dir')->nullable();
                $table->string('chroot')->default('');
                $table->text('ssh_rsa')->nullable();
            });
        }

        if (! Schema::hasTable('web_database')) {
            Schema::create('web_database', function (Blueprint $table): void {
                $table->increments('database_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('parent_domain_id')->default(0);
                $table->string('type', 16)->default('mysql');
                $table->string('database_name', 64)->nullable();
                $table->string('database_name_prefix', 50)->default('');
                $table->integer('database_quota')->nullable();
                $table->string('quota_exceeded', 1)->default('n');
                $table->date('last_quota_notification')->nullable();
                $table->unsignedInteger('database_user_id')->nullable();
                $table->unsignedInteger('database_ro_user_id')->nullable();
                $table->string('database_charset', 64)->nullable();
                $table->string('remote_access', 1)->default('y');
                $table->text('remote_ips')->nullable();
                $table->string('backup_interval')->default('none');
                $table->integer('backup_copies')->default(1);
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('web_database_user')) {
            Schema::create('web_database_user', function (Blueprint $table): void {
                $table->increments('database_user_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->string('database_user', 64)->nullable();
                $table->string('database_user_prefix', 50)->default('');
                $table->string('database_password', 64)->nullable();
                $table->string('database_password_sha2', 70)->nullable();
                $table->string('database_password_mongo', 32)->nullable();
                $table->string('database_password_postgres')->nullable();
            });
        }

        if (! Schema::hasTable('cron')) {
            Schema::create('cron', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('parent_domain_id')->default(0);
                $table->string('type', 16)->default('url');
                $table->text('command')->nullable();
                $table->string('run_min', 100)->nullable();
                $table->string('run_hour', 100)->nullable();
                $table->string('run_mday', 100)->nullable();
                $table->string('run_month', 100)->nullable();
                $table->string('run_wday', 100)->nullable();
                $table->string('log', 1)->default('n');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('web_folder')) {
            Schema::create('web_folder', function (Blueprint $table): void {
                $table->bigIncrements('web_folder_id');
                $table->integer('sys_userid')->default(0);
                $table->integer('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->integer('server_id')->default(0);
                $table->integer('parent_domain_id')->default(0);
                $table->string('path')->nullable();
                $table->string('active')->default('y');
            });
        }

        if (! Schema::hasTable('web_folder_user')) {
            Schema::create('web_folder_user', function (Blueprint $table): void {
                $table->bigIncrements('web_folder_user_id');
                $table->integer('sys_userid')->default(0);
                $table->integer('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->integer('server_id')->default(0);
                $table->integer('web_folder_id')->default(0);
                $table->string('username')->nullable();
                $table->string('password')->nullable();
                $table->string('active')->default('y');
            });
        }

        if (! Schema::hasTable('webdav_user')) {
            Schema::create('webdav_user', function (Blueprint $table): void {
                $table->increments('webdav_user_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('parent_domain_id')->default(0);
                $table->string('username', 64)->nullable();
                $table->string('username_prefix', 50)->default('');
                $table->string('password', 200)->nullable();
                $table->string('active', 1)->default('y');
                $table->string('dir')->nullable();
            });
        }

        if (! Schema::hasTable('web_backup')) {
            Schema::create('web_backup', function (Blueprint $table): void {
                $table->increments('backup_id');
                $table->unsignedInteger('server_id')->default(0);
                $table->unsignedInteger('parent_domain_id')->default(0);
                $table->string('backup_type', 16)->default('web');
                $table->string('backup_mode', 64)->default('');
                $table->string('backup_format', 64)->default('');
                $table->unsignedInteger('tstamp')->default(0);
                $table->string('filename')->default('');
                $table->string('filesize', 20)->default('');
                $table->string('backup_password')->default('');
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

        if (! Schema::hasTable('server')) {
            Schema::create('server', function (Blueprint $table): void {
                $table->increments('server_id');
                $table->string('server_name')->default('');
                $table->boolean('mail_server')->default(false);
                $table->boolean('web_server')->default(false);
                $table->boolean('dns_server')->default(false);
                $table->boolean('db_server')->default(false);
                $table->unsignedInteger('mirror_server_id')->default(0);
                $table->text('config')->nullable();
                $table->boolean('active')->default(true);
            });
        }

        if (! Schema::hasTable('sys_ini')) {
            Schema::create('sys_ini', function (Blueprint $table): void {
                $table->increments('sysini_id');
                $table->text('config')->nullable();
            });
        }

        if (! Schema::hasTable('client')) {
            Schema::create('client', function (Blueprint $table): void {
                $table->increments('client_id');
                $table->string('contact_name', 64)->default('');
                $table->string('limit_cron_type', 16)->default('url');
                $table->integer('limit_cron')->default(0);
                $table->integer('limit_cron_frequency')->default(5);
            });
        }
    }
}
