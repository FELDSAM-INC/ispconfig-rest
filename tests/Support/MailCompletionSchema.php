<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite-compatible ISPConfig tables for the mail-module COMPLETION feature
 * tests (spec 005 — mailboxes, forwards, alias domains, fetchmail,
 * transports, relay, access rules, content filters, spamfilter resources).
 *
 * Column names verbatim from source_code/install/sql/ispconfig3.sql. Every
 * table is hasTable-guarded — including the tables MailSchema also creates —
 * so the two schemas can coexist without either file being edited
 * (tests/Support/MailSchema.php documents the pattern). Tests for this
 * feature call MailCompletionSchema::create() FIRST so the full-width
 * definitions below win.
 */
class MailCompletionSchema
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
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('email')->default('');
                $table->string('login')->default('');
                $table->string('password')->default('');
                $table->string('name')->default('');
                $table->integer('uid')->default(5000);
                $table->integer('gid')->default(5000);
                $table->string('maildir')->default('');
                $table->string('maildir_format')->default('maildir');
                $table->bigInteger('quota')->default(0);
                $table->text('cc')->nullable();
                $table->string('forward_in_lda', 1)->default('n');
                $table->string('sender_cc')->default('');
                $table->string('homedir')->default('');
                $table->string('autoresponder', 1)->default('n');
                $table->dateTime('autoresponder_start_date')->nullable();
                $table->dateTime('autoresponder_end_date')->nullable();
                $table->string('autoresponder_subject')->default('Out of office reply');
                $table->mediumText('autoresponder_text')->nullable();
                $table->string('move_junk', 1)->default('y');
                $table->integer('purge_trash_days')->default(0);
                $table->integer('purge_junk_days')->default(0);
                $table->mediumText('custom_mailfilter')->nullable();
                $table->string('postfix', 1)->default('y');
                $table->string('greylisting', 1)->default('n');
                $table->string('access', 1)->default('y');
                $table->string('disableimap', 1)->default('n');
                $table->string('disablepop3', 1)->default('n');
                $table->string('disabledeliver', 1)->default('n');
                $table->string('disablesmtp', 1)->default('n');
                $table->string('disablesieve', 1)->default('n');
                $table->string('disablesieve-filter', 1)->default('n');
                $table->string('disablelda', 1)->default('n');
                $table->string('disablelmtp', 1)->default('n');
                $table->string('disabledoveadm', 1)->default('n');
                $table->integer('last_access')->nullable();
                $table->string('disablequota-status', 1)->default('n');
                $table->string('disableindexer-worker', 1)->default('n');
                $table->date('last_quota_notification')->nullable();
                $table->string('backup_interval')->default('none');
                $table->integer('backup_copies')->default(1);
                $table->string('imap_prefix')->nullable();
            });
        }

        if (! Schema::hasTable('mail_user_filter')) {
            Schema::create('mail_user_filter', function (Blueprint $table): void {
                $table->increments('filter_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('mailuser_id')->default(0);
                $table->string('rulename', 64)->nullable();
                $table->string('source')->nullable();
                $table->string('searchterm')->nullable();
                $table->string('op')->nullable();
                $table->string('action')->nullable();
                $table->string('target')->nullable();
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_forwarding')) {
            Schema::create('mail_forwarding', function (Blueprint $table): void {
                $table->increments('forwarding_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('source')->default('');
                $table->text('destination')->nullable();
                $table->string('type')->default('alias');
                $table->string('active', 1)->default('n');
                $table->string('allow_send_as', 1)->default('n');
                $table->string('greylisting', 1)->default('n');
            });
        }

        if (! Schema::hasTable('mail_get')) {
            Schema::create('mail_get', function (Blueprint $table): void {
                $table->increments('mailget_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->unsignedInteger('server_id')->default(0);
                $table->string('type')->nullable();
                $table->string('source_server')->nullable();
                $table->string('source_username')->nullable();
                $table->string('source_password', 64)->nullable();
                $table->string('source_delete')->default('y');
                $table->string('source_read_all')->default('y');
                $table->string('destination')->nullable();
                $table->string('active')->default('y');
            });
        }

        if (! Schema::hasTable('mail_transport')) {
            Schema::create('mail_transport', function (Blueprint $table): void {
                $table->increments('transport_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('domain')->default('');
                $table->string('transport')->default('');
                $table->unsignedInteger('sort_order')->default(5);
                $table->string('active', 1)->default('n');
            });
        }

        if (! Schema::hasTable('mail_relay_domain')) {
            Schema::create('mail_relay_domain', function (Blueprint $table): void {
                $table->bigIncrements('relay_domain_id');
                $table->integer('sys_userid')->default(0);
                $table->integer('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->integer('server_id')->default(0);
                $table->string('domain')->nullable();
                $table->string('access')->default('OK');
                $table->string('active')->default('y');
            });
        }

        if (! Schema::hasTable('mail_relay_recipient')) {
            Schema::create('mail_relay_recipient', function (Blueprint $table): void {
                $table->bigIncrements('relay_recipient_id');
                $table->integer('sys_userid')->default(0);
                $table->integer('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->integer('server_id')->default(0);
                $table->string('source')->nullable();
                $table->string('access')->default('OK');
                $table->string('active')->default('y');
            });
        }

        if (! Schema::hasTable('mail_access')) {
            Schema::create('mail_access', function (Blueprint $table): void {
                $table->increments('access_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->integer('server_id')->default(0);
                $table->string('source')->default('');
                $table->string('access')->default('');
                $table->string('type')->default('recipient');
                $table->string('active', 1)->default('y');
            });
        }

        if (! Schema::hasTable('mail_content_filter')) {
            Schema::create('mail_content_filter', function (Blueprint $table): void {
                $table->increments('content_filter_id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->nullable();
                $table->string('sys_perm_group', 5)->nullable();
                $table->string('sys_perm_other', 5)->nullable();
                $table->integer('server_id')->default(0);
                $table->string('type')->nullable();
                $table->string('pattern')->nullable();
                $table->string('data')->nullable();
                $table->string('action')->nullable();
                $table->string('active')->default('y');
            });
        }

        if (! Schema::hasTable('spamfilter_policy')) {
            Schema::create('spamfilter_policy', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->string('policy_name', 64)->nullable();
                $table->string('virus_lover', 1)->default('N');
                $table->string('spam_lover', 1)->default('N');
                $table->string('banned_files_lover', 1)->default('N');
                $table->string('bad_header_lover', 1)->default('N');
                $table->string('bypass_virus_checks', 1)->default('N');
                $table->string('bypass_spam_checks', 1)->default('N');
                $table->string('bypass_banned_checks', 1)->default('N');
                $table->string('bypass_header_checks', 1)->default('N');
                // Unexposed legacy columns (subset) — must keep DB defaults.
                $table->string('spam_modifies_subj', 1)->default('N');
                $table->string('virus_quarantine_to')->nullable();
                $table->string('spam_quarantine_to')->nullable();
                $table->string('banned_quarantine_to')->nullable();
                $table->string('bad_header_quarantine_to')->nullable();
                $table->string('clean_quarantine_to')->nullable();
                $table->string('other_quarantine_to')->nullable();
                $table->decimal('spam_tag_level', 5, 2)->nullable();
                $table->decimal('spam_tag2_level', 5, 2)->nullable();
                $table->decimal('spam_kill_level', 5, 2)->nullable();
                $table->string('policyd_greylist', 1)->default('N');
                $table->string('rspamd_greylisting', 1)->default('n');
                $table->decimal('rspamd_spam_greylisting_level', 5, 2)->nullable();
                $table->string('rspamd_spam_tag_method')->default('rewrite_subject');
            });
        }

        if (! Schema::hasTable('spamfilter_users')) {
            Schema::create('spamfilter_users', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('sys_userid')->default(0);
                $table->unsignedInteger('sys_groupid')->default(0);
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
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
                $table->string('sys_perm_user', 5)->default('');
                $table->string('sys_perm_group', 5)->default('');
                $table->string('sys_perm_other', 5)->default('');
                $table->unsignedInteger('server_id')->default(0);
                $table->string('wb', 1)->default('W');
                $table->unsignedInteger('rid')->default(0);
                $table->string('email')->default('');
                $table->unsignedTinyInteger('priority')->default(0);
                $table->string('active', 1)->default('y');
            });
        }
    }

    /**
     * Infrastructure tables — hasTable-guarded like every module schema.
     * NOTE: this feature needs server.config (the serialized INI blob), so
     * the definition here is wider than MailSchema's; feature tests call
     * MailCompletionSchema::create() before any other schema.
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
                $table->text('config')->nullable();
                $table->unsignedInteger('mirror_server_id')->default(0);
                $table->boolean('active')->default(true);
            });
        }
    }
}
