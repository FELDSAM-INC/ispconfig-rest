<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Support\Facades\DB;

/**
 * Account capabilities for scoped keys (spec 021; contracts
 * api/modules/me/capabilities.yaml, api/modules/me/php-versions.yaml).
 *
 * Describes what an account's own key may do with websites. The derivation
 * stays in WebPermissionService and PhpVersionService, the rules spec 020
 * enforces, so a panel only offers choices the website endpoints accept.
 */
class AccountCapabilitiesService
{
    public function __construct(
        protected UsageService $usage,
        protected WebPermissionService $permissions,
        protected PhpVersionService $phpVersions,
        protected ServerAssignmentService $servers,
    ) {}

    /**
     * The client a request describes (research R1): the `/usage/summary`
     * rules — admin keys must name it, client keys get their own, reseller
     * keys their own or one of their clients; anything else 404.
     */
    public function resolveTarget(AuthScope $scope, ?int $clientId): int
    {
        return $this->usage->resolveTargetClient($scope, $clientId);
    }

    /**
     * Website capabilities of a client (FR-003, research R2): the view of the
     * client's own key (reseller when limit_client != 0).
     *
     * @return array<string, mixed>
     */
    public function capabilities(int $clientId): array
    {
        $account = $this->permissions->forClient($clientId);
        $flags = $account['flags'];

        return [
            'client_id' => $clientId,
            'account_type' => $account['is_reseller'] ? 'reseller' : 'client',
            'locked' => $account['locked'],
            'canceled' => $account['canceled'],
            'web' => [
                'ssl' => $flags['ssl'],
                'ssl_letsencrypt' => $flags['ssl_letsencrypt'],
                'wildcard' => $flags['wildcard_subdomains'],
                'cgi' => $flags['cgi'],
                'ssi' => $flags['ssi'],
                'perl' => $flags['perl'],
                'ruby' => $flags['ruby'],
                'python' => $flags['python'],
                'error_documents' => $flags['error_documents'],
                'directive_snippets' => $flags['directive_snippets'],
                'suexec_forced' => $account['force_suexec'],
                'backup' => $flags['backup'],
                'advanced_options' => $account['advanced_options'],
                'php_modes' => $account['php_modes'],
                'php_default_mode' => $this->permissions->defaultPhpMode($account),
            ],
        ];
    }

    /**
     * Web servers of a client (FR-005, research R5): the valid assigned web
     * servers in `web_servers` order, then the non-mirror web servers hosting
     * the client's websites, by id.
     *
     * @return array<int, int>
     */
    public function accountWebServers(int $clientId): array
    {
        // Lookup scope for the assignment columns of the client row.
        $assigned = $this->servers->assignedServerIds(new AuthScope(0, 0, [], false, $clientId), 'web');

        $hosting = DB::table('web_domain')
            ->join('sys_group', 'sys_group.groupid', '=', 'web_domain.sys_groupid')
            ->join('server', 'server.server_id', '=', 'web_domain.server_id')
            ->where('sys_group.client_id', $clientId)
            ->where('server.web_server', 1)
            ->where('server.mirror_server_id', 0)
            ->distinct()
            ->orderBy('web_domain.server_id')
            ->pluck('web_domain.server_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($assigned, $hosting)));
    }
}
