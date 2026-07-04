<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for client-module feature tests
 * (see MailSchema for the module-schema pattern). Column names and defaults
 * verbatim from source_code/install/sql/ispconfig3.sql: client,
 * client_template, client_template_assigned, client_circle, domain and
 * sys_group, plus the hasTable()-guarded shared infrastructure tables
 * (sys_datalog, sys_user, server). The sys_user table here carries the full
 * legacy column set because ClientService creates/syncs control-panel
 * logins (passwort, modules, groups, client_id, ...).
 */
class ClientSchema
{
    public static function create(): void
    {
        self::createSharedTables();

        if (! Schema::hasTable('client')) {
            Schema::create('client', function (Blueprint $table): void {
                $table->increments('client_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->string('company_name', 64)->nullable();
                $table->string('company_id')->nullable();
                $table->string('gender', 1)->default('');
                $table->string('contact_firstname', 64)->default('');
                $table->string('contact_name', 64)->nullable();
                $table->string('customer_no', 64)->nullable();
                $table->string('vat_id', 64)->nullable();
                $table->string('street')->nullable();
                $table->string('zip', 32)->nullable();
                $table->string('city', 64)->nullable();
                $table->string('state', 32)->nullable();
                $table->string('country', 2)->nullable();
                $table->string('telephone', 32)->nullable();
                $table->string('mobile', 32)->nullable();
                $table->string('fax', 32)->nullable();
                $table->string('email')->nullable();
                $table->string('internet')->default('');
                $table->string('icq', 16)->nullable();
                $table->text('notes')->nullable();
                $table->string('bank_account_owner')->nullable();
                $table->string('bank_account_number')->nullable();
                $table->string('bank_code')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('bank_account_iban')->nullable();
                $table->string('bank_account_swift')->nullable();
                $table->string('paypal_email')->nullable();
                $table->unsignedInteger('default_mailserver')->default(1);
                $table->text('mail_servers')->nullable();
                $table->integer('limit_maildomain')->default(-1);
                $table->integer('limit_mailbox')->default(-1);
                $table->integer('limit_mailalias')->default(-1);
                $table->integer('limit_mailaliasdomain')->default(-1);
                $table->integer('limit_mailforward')->default(-1);
                $table->integer('limit_mailcatchall')->default(-1);
                $table->integer('limit_mailrouting')->default(0);
                $table->integer('limit_mail_wblist')->default(0);
                $table->integer('limit_mailfilter')->default(-1);
                $table->integer('limit_fetchmail')->default(-1);
                $table->integer('limit_mailquota')->default(-1);
                $table->integer('limit_spamfilter_wblist')->default(0);
                $table->integer('limit_spamfilter_user')->default(0);
                $table->integer('limit_spamfilter_policy')->default(0);
                $table->string('limit_mail_backup', 1)->default('y');
                $table->string('limit_relayhost', 1)->default('n');
                $table->unsignedInteger('default_xmppserver')->default(1);
                $table->text('xmpp_servers')->nullable();
                $table->integer('limit_xmpp_domain')->default(-1);
                $table->integer('limit_xmpp_user')->default(-1);
                $table->string('limit_xmpp_muc', 1)->default('n');
                $table->string('limit_xmpp_anon', 1)->default('n');
                $table->string('limit_xmpp_auth_options')->default('plain,hashed,isp');
                $table->string('limit_xmpp_vjud', 1)->default('n');
                $table->string('limit_xmpp_proxy', 1)->default('n');
                $table->string('limit_xmpp_status', 1)->default('n');
                $table->string('limit_xmpp_pastebin', 1)->default('n');
                $table->string('limit_xmpp_httparchive', 1)->default('n');
                $table->unsignedInteger('default_webserver')->default(1);
                $table->text('web_servers')->nullable();
                $table->text('limit_web_ip')->nullable();
                $table->integer('limit_web_domain')->default(-1);
                $table->integer('limit_web_quota')->default(-1);
                $table->string('web_php_options')->default('no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm');
                $table->string('limit_cgi', 1)->default('n');
                $table->string('limit_ssi', 1)->default('n');
                $table->string('limit_perl', 1)->default('n');
                $table->string('limit_ruby', 1)->default('n');
                $table->string('limit_python', 1)->default('n');
                $table->string('force_suexec', 1)->default('y');
                $table->string('limit_hterror', 1)->default('n');
                $table->string('limit_wildcard', 1)->default('n');
                $table->string('limit_ssl', 1)->default('n');
                $table->string('limit_ssl_letsencrypt', 1)->default('n');
                $table->integer('limit_web_subdomain')->default(-1);
                $table->integer('limit_web_aliasdomain')->default(-1);
                $table->integer('limit_ftp_user')->default(-1);
                $table->integer('limit_shell_user')->default(0);
                $table->string('ssh_chroot')->default('no,jailkit,ssh-chroot');
                $table->integer('limit_webdav_user')->default(0);
                $table->string('limit_backup', 1)->default('y');
                $table->string('limit_directive_snippets', 1)->default('n');
                $table->integer('limit_aps')->default(-1);
                $table->unsignedInteger('default_dnsserver')->default(1);
                $table->text('db_servers')->nullable();
                $table->integer('limit_dns_zone')->default(-1);
                $table->unsignedInteger('default_slave_dnsserver')->default(1);
                $table->integer('limit_dns_slave_zone')->default(-1);
                $table->integer('limit_dns_record')->default(-1);
                $table->integer('default_dbserver')->default(1);
                $table->text('dns_servers')->nullable();
                $table->integer('limit_database')->default(-1);
                $table->integer('limit_database_postgresql')->default(-1);
                $table->integer('limit_database_user')->default(-1);
                $table->integer('limit_database_quota')->default(-1);
                $table->integer('limit_cron')->default(0);
                $table->string('limit_cron_type', 10)->default('url');
                $table->integer('limit_cron_frequency')->default(5);
                $table->integer('limit_traffic_quota')->default(-1);
                $table->integer('limit_client')->default(0);
                $table->integer('limit_domainmodule')->default(0);
                $table->integer('limit_mailmailinglist')->default(-1);
                $table->integer('limit_openvz_vm')->default(0);
                $table->integer('limit_openvz_vm_template_id')->default(0);
                $table->unsignedInteger('parent_client_id')->default(0);
                $table->string('username', 64)->nullable();
                $table->string('password', 200)->nullable();
                $table->string('language', 2)->default('en');
                $table->string('usertheme', 32)->default('default');
                $table->unsignedInteger('template_master')->default(0);
                $table->text('template_additional')->nullable();
                $table->bigInteger('created_at')->nullable();
                $table->string('locked', 1)->default('n');
                $table->string('canceled', 1)->default('n');
                $table->string('can_use_api', 1)->default('n');
                $table->text('tmp_data')->nullable();
                $table->text('id_rsa')->nullable();
                $table->string('ssh_rsa', 600)->default('');
                $table->string('customer_no_template')->nullable()->default('R[CLIENTID]C[CUSTOMER_NO]');
                $table->integer('customer_no_start')->default(1);
                $table->integer('customer_no_counter')->default(0);
                $table->date('added_date')->nullable();
                $table->string('added_by')->nullable();
                $table->string('validation_status', 10)->default('accept');
                $table->unsignedInteger('risk_score')->default(0);
                $table->string('activation_code', 10)->default('');
            });
        }

        if (! Schema::hasTable('client_template')) {
            Schema::create('client_template', function (Blueprint $table): void {
                $table->increments('template_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->string('template_name', 64)->default('');
                $table->string('template_type', 1)->default('m');
                $table->text('mail_servers')->nullable();
                $table->integer('limit_maildomain')->default(-1);
                $table->integer('limit_mailbox')->default(-1);
                $table->integer('limit_mailalias')->default(-1);
                $table->integer('limit_mailaliasdomain')->default(-1);
                $table->integer('limit_mailforward')->default(-1);
                $table->integer('limit_mailcatchall')->default(-1);
                $table->integer('limit_mailrouting')->default(0);
                $table->integer('limit_mail_wblist')->default(0);
                $table->integer('limit_mailfilter')->default(-1);
                $table->integer('limit_fetchmail')->default(-1);
                $table->integer('limit_mailquota')->default(-1);
                $table->integer('limit_spamfilter_wblist')->default(0);
                $table->integer('limit_spamfilter_user')->default(0);
                $table->integer('limit_spamfilter_policy')->default(0);
                $table->string('limit_mail_backup', 1)->default('y');
                $table->string('limit_relayhost', 1)->default('n');
                $table->unsignedInteger('default_xmppserver')->default(1);
                $table->text('xmpp_servers')->nullable();
                $table->integer('limit_xmpp_domain')->default(-1);
                $table->integer('limit_xmpp_user')->default(-1);
                $table->string('limit_xmpp_muc', 1)->default('n');
                $table->string('limit_xmpp_anon', 1)->default('n');
                $table->string('limit_xmpp_vjud', 1)->default('n');
                $table->string('limit_xmpp_proxy', 1)->default('n');
                $table->string('limit_xmpp_status', 1)->default('n');
                $table->string('limit_xmpp_pastebin', 1)->default('n');
                $table->string('limit_xmpp_httparchive', 1)->default('n');
                $table->text('web_servers')->nullable();
                $table->text('limit_web_ip')->nullable();
                $table->integer('limit_web_domain')->default(-1);
                $table->integer('limit_web_quota')->default(-1);
                $table->string('web_php_options')->default('');
                $table->string('limit_cgi', 1)->default('n');
                $table->string('limit_ssi', 1)->default('n');
                $table->string('limit_perl', 1)->default('n');
                $table->string('limit_ruby', 1)->default('n');
                $table->string('limit_python', 1)->default('n');
                $table->string('force_suexec', 1)->default('y');
                $table->string('limit_hterror', 1)->default('n');
                $table->string('limit_wildcard', 1)->default('n');
                $table->string('limit_ssl', 1)->default('n');
                $table->string('limit_ssl_letsencrypt', 1)->default('n');
                $table->integer('limit_web_subdomain')->default(-1);
                $table->integer('limit_web_aliasdomain')->default(-1);
                $table->integer('limit_ftp_user')->default(-1);
                $table->integer('limit_shell_user')->default(0);
                $table->string('ssh_chroot')->default('');
                $table->integer('limit_webdav_user')->default(0);
                $table->string('limit_backup', 1)->default('y');
                $table->string('limit_directive_snippets', 1)->default('n');
                $table->integer('limit_aps')->default(-1);
                $table->text('dns_servers')->nullable();
                $table->integer('limit_dns_zone')->default(-1);
                $table->integer('default_slave_dnsserver')->default(0);
                $table->integer('limit_dns_slave_zone')->default(-1);
                $table->integer('limit_dns_record')->default(-1);
                $table->text('db_servers')->nullable();
                $table->integer('limit_database')->default(-1);
                $table->integer('limit_database_postgresql')->default(-1);
                $table->integer('limit_database_user')->default(-1);
                $table->integer('limit_database_quota')->default(-1);
                $table->integer('limit_cron')->default(0);
                $table->string('limit_cron_type', 10)->default('url');
                $table->integer('limit_cron_frequency')->default(5);
                $table->integer('limit_traffic_quota')->default(-1);
                $table->integer('limit_client')->default(0);
                $table->integer('limit_domainmodule')->default(0);
                $table->integer('limit_mailmailinglist')->default(-1);
                $table->integer('limit_openvz_vm')->default(0);
                $table->integer('limit_openvz_vm_template_id')->default(0);
            });
        }

        if (! Schema::hasTable('client_template_assigned')) {
            Schema::create('client_template_assigned', function (Blueprint $table): void {
                $table->increments('assigned_template_id');
                $table->unsignedBigInteger('client_id')->default(0);
                $table->integer('client_template_id')->default(0);
            });
        }

        if (! Schema::hasTable('client_circle')) {
            Schema::create('client_circle', function (Blueprint $table): void {
                $table->increments('circle_id');
                $table->integer('sys_userid')->default(0);
                $table->integer('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->string('circle_name', 64)->nullable();
                $table->text('client_ids')->nullable();
                $table->text('description')->nullable();
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('domain')) {
            Schema::create('domain', function (Blueprint $table): void {
                $table->increments('domain_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->string('domain')->default('')->unique();
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

        // Full legacy column set (ispconfig3.sql) — ClientService writes
        // control-panel logins into this table.
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
                $table->string('app_theme', 32)->default('default');
                $table->string('typ', 16)->default('user');
                $table->boolean('active')->default(true);
                $table->string('language', 2)->default('en');
                $table->text('groups')->nullable();
                $table->unsignedInteger('default_group')->default(0);
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
                $table->boolean('active')->default(true);
            });
        }
    }
}
