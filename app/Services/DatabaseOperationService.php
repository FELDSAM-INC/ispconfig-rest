<?php

namespace App\Services;

use App\Http\Requests\StoreWebDatabaseRequest;
use App\Models\WebDatabase;
use App\Support\IspContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DatabaseOperationService
{
    public const MAX_IMPORT = 8388608;

    public const CHUNK = 786432;

    public const MAX_CHUNK = 8388608;

    public const MAX_UPLOAD = 2147483648;

    /** Request-scoped cache keeps list serialization to one heartbeat query. */
    private ?array $servers = null;

    public function capabilities(WebDatabase $database): array
    {
        if ($this->servers === null) {
            $this->servers = Schema::hasTable('api_database_workers')
                ? DB::table('api_database_workers')->where('heartbeat', '>=', time() - 180)->pluck('server_id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        $scope = app(IspContext::class)->authScope();

        return ($scope->isAdmin || in_array((int) $database->sys_groupid, $scope->groupIds, true)) && $database->type === 'mysql' && $database->active && in_array((int) $database->server_id, $this->servers, true)
            ? ['import', 'export', 'copy'] : [];
    }

    public function create(WebDatabase $source, array $input): array
    {
        $scope = app(IspContext::class)->authScope();
        abort_unless(($scope->isAdmin || in_array((int) $source->sys_groupid, $scope->groupIds, true)) && $scope->allows($source->getAttributes(), 'u'), 403);
        app(LockedClientGuard::class)->checkBackupWrite($source);
        abort_unless(in_array($input['action'], $this->capabilities($source), true), 409, 'Database operations are unavailable on this server.');
        $dump = null;
        if ($input['action'] === 'import' && isset($input['dump_base64'])) {
            $dump = base64_decode($input['dump_base64'], true);
            if ($dump === false || $dump === '' || strlen($dump) > self::MAX_IMPORT || (str_contains($dump, "\0") && ! str_starts_with($dump, "\x1f\x8b\x08"))) {
                throw ValidationException::withMessages(['dump_base64' => 'Upload a non-empty SQL or gzip SQL file, at most 8 MiB.']);
            }
        }

        return DB::transaction(function () use ($source, $input, $dump): array {
            // Lock an API-owned server row, not an ISPConfig row. Serializes duplicate
            // submissions and target-name checks across source databases on this server.
            DB::table('api_database_workers')->where('server_id', $source->server_id)->lockForUpdate()->first();
            $busy = DB::table('api_database_operations')->whereIn('status', ['uploading', 'queued', 'running'])
                ->where(fn ($q) => $q->where('database_id', $source->getKey())->orWhere('target_database_id', $source->getKey()))->exists();
            abort_if($busy, 409, 'A database operation is already pending.');
            $target = null;
            if ($input['action'] === 'copy') {
                $payload = array_intersect_key($source->toArray(), array_flip([
                    'server_id', 'parent_domain_id', 'type', 'database_user_id', 'database_ro_user_id',
                    'database_quota', 'database_charset', 'remote_access', 'remote_ips',
                ]));
                $payload['database_name'] = $input['database_name'];
                $payload['database_quota'] = $source->database_quota ?? -1;
                // Reuse the ordinary create request's reference/assigned-server checks,
                // then the same limits, prefix, permissions and datalog creation service.
                $request = StoreWebDatabaseRequest::create('/api/v1/sites/databases', 'POST', $payload);
                $request->setContainer(app())->setRedirector(app('redirect'));
                $request->validateResolved();
                $target = app(DatabaseProvisioningService::class)->create($request->payload());
            }
            $id = (string) Str::uuid();
            $row = [
                'id' => $id, 'database_id' => (int) $source->getKey(), 'target_database_id' => $target?->getKey(),
                'sys_groupid' => (int) $source->sys_groupid, 'server_id' => (int) $source->server_id,
                'database_name' => $source->database_name_full, 'action' => $input['action'], 'status' => isset($input['upload_bytes']) ? 'uploading' : 'queued',
                'chunk_size' => isset($input['chunk_size']) ? $this->chunkSize((int) $input['chunk_size']) : null,
                'upload_bytes' => $input['upload_bytes'] ?? ($dump === null ? null : strlen($dump)),
                'uploaded_bytes' => $dump === null ? 0 : strlen($dump), 'download_bytes' => null,
                'created_at' => time(), 'updated_at' => time(), 'expires_at' => time() + 86400, 'error' => null,
            ];
            DB::table('api_database_operations')->insert($row);
            if ($dump !== null) {
                foreach (str_split($dump, self::CHUNK) as $sequence => $chunk) {
                    DB::table('api_database_operation_chunks')->insert(['operation_id' => $id, 'sequence' => $sequence, 'content' => base64_encode($chunk)]);
                }
            }

            return $this->present((object) $row);
        });
    }

    private function chunkSize(int $requested): int
    {
        $maxPacket = DB::connection()->getDriverName() === 'mysql'
            ? (int) DB::selectOne('SELECT @@max_allowed_packet AS bytes')->bytes : 16777216;
        foreach ([8388608, 4194304, 2097152, self::CHUNK] as $size) {
            if ($size <= $requested && 4 * (int) ceil($size / 3) + 65536 <= $maxPacket) {
                return $size;
            }
        }
        abort(409, 'The database packet limit is too small for uploads.');
    }

    private function writable(WebDatabase $source, string $id): object
    {
        $job = $this->find($source, $id);
        abort_unless(app(IspContext::class)->authScope()->allows($source->getAttributes(), 'u'), 403);
        app(LockedClientGuard::class)->checkBackupWrite($source);

        return $job;
    }

    public function chunk(WebDatabase $source, string $id, int $sequence, string $encoded): array
    {
        $this->writable($source, $id);
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_CHUNK) {
            throw ValidationException::withMessages(['dump_base64' => 'Invalid upload chunk.']);
        }
        $length = strlen($bytes);
        $content = base64_encode($bytes);
        if ($content === $encoded) {
            $content = $encoded;
        }
        unset($bytes);

        return DB::transaction(function () use ($id, $sequence, $length, $content): array {
            $job = DB::table('api_database_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($job->action === 'import' && $job->status === 'uploading', 409);
            $size = (int) ($job->chunk_size ?? self::CHUNK);
            $expected = min($size, (int) $job->upload_bytes - $sequence * $size);
            abort_unless($expected > 0 && $length === $expected, 422, 'Incorrect chunk length.');
            $chunks = DB::table('api_database_operation_chunks')->where('operation_id', $id)->where('sequence', $sequence);
            $stored = $chunks->value('content');
            if ($stored !== null) {
                abort_unless(hash_equals($stored, $content), 409, 'Chunk differs from the previous upload.');
            } else {
                if ($job->chunk_size === null) {
                    abort_unless($sequence * $size === (int) $job->uploaded_bytes, 409, 'Upload chunks in sequence.');
                }
                $chunks->insert(['operation_id' => $id, 'sequence' => $sequence, 'content' => $content]);
                $job->uploaded_bytes += $length;
            }
            DB::table('api_database_operations')->where('id', $id)->update(['uploaded_bytes' => $job->uploaded_bytes, 'updated_at' => time()]);

            return $this->present($job);
        });
    }

    public function finish(WebDatabase $source, string $id): array
    {
        $this->writable($source, $id);

        return DB::transaction(function () use ($id): array {
            $job = DB::table('api_database_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($job->action === 'import' && $job->status !== 'failed' && (int) $job->upload_bytes > 0 && (int) $job->uploaded_bytes === (int) $job->upload_bytes, 409, 'Upload is incomplete.');
            if ($job->status === 'uploading') {
                $job->status = 'queued';
                DB::table('api_database_operations')->where('id', $id)->update(['status' => 'queued', 'updated_at' => time()]);
            }

            return $this->present($job);
        });
    }

    public function cancel(WebDatabase $source, string $id): void
    {
        $this->writable($source, $id);
        $changed = DB::table('api_database_operations')->where('id', $id)->where('status', 'uploading')
            ->update(['status' => 'failed', 'error' => 'upload_cancelled', 'updated_at' => time()]);
        abort_unless($changed, 409, 'Upload is already finalized.');
    }

    public function find(WebDatabase $source, string $id): object
    {
        $scope = app(IspContext::class)->authScope();
        abort_unless($scope->isAdmin || in_array((int) $source->sys_groupid, $scope->groupIds, true), 404);
        $row = DB::table('api_database_operations')->where('id', $id)->where('database_id', $source->getKey())
            ->where('sys_groupid', $source->sys_groupid)->where('server_id', $source->server_id)
            ->where('database_name', $source->database_name_full)->where('expires_at', '>', time())->first();
        abort_if($row === null, 404);
        if (in_array($row->status, ['uploading', 'running'], true) && $row->updated_at < time() - 1800) {
            $expired = DB::table('api_database_operations')->where('id', $id)->where('status', $row->status)->where('updated_at', '<', time() - 1800)
                ->update(['status' => 'failed', 'error' => 'operation_expired', 'updated_at' => time()]);
            if ($expired) {
                $row->status = 'failed';
                $row->error = 'operation_expired';
            }
        }

        return $row;
    }

    public function present(object $row): array
    {
        return [
            'upload_bytes' => isset($row->upload_bytes) ? (int) $row->upload_bytes : null,
            'uploaded_bytes' => (int) ($row->uploaded_bytes ?? 0),
            'download_bytes' => isset($row->download_bytes) ? (int) $row->download_bytes : null, 'chunk_size' => (int) ($row->chunk_size ?? self::CHUNK),
            'upload_concurrency' => isset($row->chunk_size) ? 4 : 1,
            'id' => $row->id, 'action' => $row->action, 'status' => $row->status,
            'database_id' => (int) $row->database_id, 'target_database_id' => $row->target_database_id === null ? null : (int) $row->target_database_id,
            'created_at' => gmdate('c', $row->created_at), 'expires_at' => gmdate('c', $row->expires_at), 'error' => $row->error,
        ];
    }
}
