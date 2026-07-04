<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSpamfilterConfigRequest;
use App\Services\ServerIniConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Spamfilter Config — the [server] and [mail] INI sections of the
 * serialized server.config column, one implicit resource per mail server
 * (contract: api/modules/mail/spamfilter-config.yaml; legacy:
 * spamfilter_config.tform.php with db_table=server +
 * spamfilter_config_edit.php).
 *
 * There is NO model and NO datalog here by design: legacy writes the blob
 * with a plain `UPDATE server SET config = ?` and no sys_datalog row —
 * the documented constitution Principle II exception (C-8, plan Complexity
 * Tracking). PUT is a read-merge-write that only touches the exposed keys;
 * configs are never created or deleted through the API (no POST/DELETE).
 */
class SpamfilterConfigController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected ServerIniConfigService $service)
    {
    }

    /**
     * GET /mail/spamfilter/config — one entry per mail server, hostname
     * filter, sorted and paginated.
     */
    public function index(Request $request): JsonResponse
    {
        $sortable = ['server_id', 'hostname'];
        $sort = $request->query('sort', 'server_id');

        if (! is_string($sort) || ! in_array($sort, $sortable, true)) {
            throw new BadRequestHttpException(
                sprintf("Invalid sort column '%s'. Allowed: %s.", is_string($sort) ? $sort : gettype($sort), implode(', ', $sortable))
            );
        }

        $order = $request->query('order', 'asc');
        if (! is_string($order) || ! in_array(strtolower($order), ['asc', 'desc'], true)) {
            throw new BadRequestHttpException("Invalid order value. Allowed: 'asc', 'desc'.");
        }

        $limit = $this->positiveIntParam($request, 'limit', 25, min: 1, max: 100);
        $offset = $this->positiveIntParam($request, 'offset', 0, min: 0);

        $items = $this->mailServerQuery()
            ->orderBy('server_id')
            ->get()
            ->map(fn (object $server): array => $this->view((int) $server->server_id));

        $hostname = $request->query('hostname');
        if (is_string($hostname) && $hostname !== '') {
            $items = $items->filter(fn (array $item): bool => $item['hostname'] === $hostname);
        }

        $items = $items
            ->sortBy([[$sort, strtolower($order)]])
            ->values();

        return response()->json([
            'data' => $items->slice($offset, $limit)->values(),
            'meta' => [
                'total' => $items->count(),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * GET /mail/spamfilter/config/{server_id} — 404 for unknown or
     * non-mail servers.
     */
    public function show(int $serverId): JsonResponse
    {
        $this->assertMailServer($serverId);

        return response()->json($this->view($serverId));
    }

    /**
     * PUT /mail/spamfilter/config/{server_id} — read-merge-write of the
     * exposed [server]/[mail] keys; all other sections and keys of the
     * config blob are preserved. Plain UPDATE, no datalog (C-8).
     */
    public function update(UpdateSpamfilterConfigRequest $request, int $serverId): JsonResponse
    {
        $this->assertMailServer($serverId);

        $this->service->mergeSections($serverId, $request->sections());

        return response()->json($this->view($serverId));
    }

    /**
     * The SpamfilterConfig contract shape for one server.
     *
     * @return array<string, mixed>
     */
    protected function view(int $serverId): array
    {
        $config = $this->service->getConfig($serverId);
        $server = $config['server'] ?? [];
        $mail = $config['mail'] ?? [];

        return [
            'server_id' => $serverId,
            'ip_address' => $server['ip_address'] ?? '',
            'netmask' => $server['netmask'] ?? '',
            'gateway' => $server['gateway'] ?? '',
            'hostname' => $server['hostname'] ?? '',
            'nameservers' => $server['nameservers'] ?? '',
            'module' => $mail['module'] ?? 'postfix_mysql',
            'maildir_path' => $mail['maildir_path'] ?? '/var/vmail/[domain]/[localpart]',
            'homedir_path' => $mail['homedir_path'] ?? '/var/vmail',
            'mailuser_uid' => (int) ($mail['mailuser_uid'] ?? 5000),
            'mailuser_gid' => (int) ($mail['mailuser_gid'] ?? 5000),
            'mailuser_name' => $mail['mailuser_name'] ?? 'vmail',
            'mailuser_group' => $mail['mailuser_group'] ?? 'vmail',
        ];
    }

    protected function mailServerQuery()
    {
        return DB::table('server')
            ->where('mail_server', 1)
            ->where('mirror_server_id', 0);
    }

    /**
     * Configs exist implicitly for every mail server — anything else 404s.
     */
    protected function assertMailServer(int $serverId): void
    {
        $exists = $this->mailServerQuery()->where('server_id', $serverId)->exists();

        if (! $exists) {
            throw new NotFoundHttpException("Server {$serverId} is not an existing mail server.");
        }
    }
}
