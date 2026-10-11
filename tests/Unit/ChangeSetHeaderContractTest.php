<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract lint: every journaling write operation documents X-Change-Set-Id
 * on its 2xx responses (spec 015 FR-001, FR-012, SC-004).
 */
class ChangeSetHeaderContractTest extends TestCase
{
    private const HEADER_REF = '../../components/headers/ChangeSetId.yaml';

    /**
     * Write operations that never journal and therefore never send the
     * header (owner decision 2026-09-14): 014 API keys live in the
     * API-owned api_keys table. 018 backup remote actions are added here
     * when that feature is merged.
     *
     * @var array<int, string>
     */
    private const NON_JOURNALING_WRITES = [
        'POST /system/api-keys',
        'PUT /system/api-keys/{id}',
        'DELETE /system/api-keys/{id}',
        // Feature 018: backup remote actions go to sys_remoteaction, never the datalog.
        'POST /sites/web-domains/{id}/backups',
        'DELETE /sites/web-domains/{id}/backups/{backup_id}',
        'POST /sites/web-domains/{id}/backups/{backup_id}/restore',
        'POST /sites/web-domains/{id}/backups/{backup_id}/download',
    ];

    /**
     * @return array<int, array{file: string, path: string, method: string, responses: array<string, mixed>}>
     */
    private function operations(): array
    {
        $root = dirname(__DIR__, 2).'/api/modules';
        $operations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'yaml' || $file->getFilename() === '_index.yaml') {
                continue;
            }

            $document = Yaml::parseFile($file->getPathname());

            foreach ((array) $document as $path => $methods) {
                if (! is_string($path) || ! str_starts_with($path, '/') || ! is_array($methods)) {
                    continue;
                }

                foreach ($methods as $method => $operation) {
                    if (! is_array($operation)) {
                        continue;
                    }

                    $operations[] = [
                        'file' => substr($file->getPathname(), strlen($root) + 1),
                        'path' => $path,
                        'method' => strtoupper((string) $method),
                        'responses' => (array) ($operation['responses'] ?? []),
                    ];
                }
            }
        }

        return $operations;
    }

    public function test_every_journaling_write_documents_the_change_set_header(): void
    {
        $checked = 0;
        $missing = [];
        $exceptionsFound = [];

        foreach ($this->operations() as $operation) {
            if (! in_array($operation['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                continue;
            }

            $checked++;
            $name = $operation['method'].' '.$operation['path'];
            $isException = in_array($name, self::NON_JOURNALING_WRITES, true);

            if ($isException) {
                $exceptionsFound[] = $name;
            }

            foreach ($operation['responses'] as $code => $response) {
                if (! preg_match('/^2\d\d$/', (string) $code)) {
                    continue;
                }

                $ref = $response['headers']['X-Change-Set-Id']['$ref'] ?? null;

                if ($isException && $ref !== null) {
                    $missing[] = "{$name} {$code} ({$operation['file']}) documents the header but never journals";
                } elseif (! $isException && $ref !== self::HEADER_REF) {
                    $missing[] = "{$name} {$code} ({$operation['file']})";
                }
            }
        }

        $this->assertGreaterThanOrEqual(151, $checked, 'expected at least 151 write operations in api/modules');
        $this->assertSame([], $missing, "2xx write responses without a correct X-Change-Set-Id header reference:\n".implode("\n", $missing));
        $this->assertEqualsCanonicalizing(self::NON_JOURNALING_WRITES, $exceptionsFound, 'every NON_JOURNALING_WRITES entry must exist in the contract');
    }

    public function test_change_status_reads_carry_no_change_set_header(): void
    {
        foreach ($this->operations() as $operation) {
            if ($operation['file'] !== 'changes/changes.yaml') {
                continue;
            }

            foreach ($operation['responses'] as $code => $response) {
                $this->assertArrayNotHasKey('headers', (array) $response, "{$operation['method']} {$operation['path']} {$code}");
            }
        }
    }
}
