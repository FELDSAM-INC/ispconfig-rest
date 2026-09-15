<?php

namespace App\Services;

use App\Support\IspContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Record view of the change list (spec 015 US3, FR-008, research R5).
 *
 * Maps the ISPConfig tables exposed as API resources to their primary keys
 * (data-model.md) and decides whether the acting key may read one record:
 * non-admin keys through the legacy getAuthSQL('r') predicate
 * (AuthScope::applyReadPredicate, spec 011), admin keys always. Tables
 * without sys permission fields are readable only by admin keys.
 */
class ChangeRecordResolver
{
    /**
     * table => [primary key column, has sys permission fields]
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const TABLES = [
        'client' => ['client_id', true],
        'client_circle' => ['circle_id', true],
        'client_template' => ['template_id', true],
        'client_template_assigned' => ['assigned_template_id', false],
        'cron' => ['id', true],
        'directive_snippets' => ['directive_snippets_id', true],
        'dns_rr' => ['id', true],
        'dns_slave' => ['id', true],
        'dns_soa' => ['id', true],
        'dns_ssl_ca' => ['id', true],
        'dns_template' => ['template_id', true],
        'domain' => ['domain_id', true],
        'firewall' => ['firewall_id', true],
        'ftp_user' => ['ftp_user_id', true],
        'mail_access' => ['access_id', true],
        'mail_content_filter' => ['content_filter_id', true],
        'mail_domain' => ['domain_id', true],
        'mail_forwarding' => ['forwarding_id', true],
        'mail_get' => ['mailget_id', true],
        'mail_relay_domain' => ['relay_domain_id', true],
        'mail_relay_recipient' => ['relay_recipient_id', true],
        'mail_transport' => ['transport_id', true],
        'mail_user' => ['mailuser_id', true],
        'mail_user_filter' => ['filter_id', true],
        'server' => ['server_id', true],
        'server_ip' => ['server_ip_id', true],
        'server_ip_map' => ['server_ip_map_id', true],
        'server_php' => ['server_php_id', true],
        'shell_user' => ['shell_user_id', true],
        'spamfilter_policy' => ['id', true],
        'spamfilter_users' => ['id', true],
        'spamfilter_wblist' => ['wblist_id', true],
        'sys_ini' => ['sysini_id', false],
        'web_database' => ['database_id', true],
        'web_database_user' => ['database_user_id', true],
        'web_domain' => ['domain_id', true],
        'web_folder' => ['web_folder_id', true],
        'web_folder_user' => ['web_folder_user_id', true],
        'webdav_user' => ['webdav_user_id', true],
    ];

    public function supports(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }

    /**
     * The sys_datalog.dbidx value of a record ("<primary key>:<id>").
     */
    public function dbidx(string $table, int $id): string
    {
        return self::TABLES[$table][0].':'.$id;
    }

    /**
     * 404 unless the acting key may read the record. Admin keys always pass,
     * so they can see entries of records that no longer exist.
     */
    public function assertReadable(string $table, int $id): void
    {
        $scope = app(IspContext::class)->authScope();

        if ($scope->isAdmin) {
            return;
        }

        [$primaryKey, $hasSysFields] = self::TABLES[$table];

        if ($hasSysFields) {
            $query = DB::table($table)->where($primaryKey, $id);
            $scope->applyReadPredicate($query, 'r');

            if ($query->exists()) {
                return;
            }
        }

        throw new NotFoundHttpException('The requested record does not exist.');
    }
}
