<?php

namespace Tests\Unit;

use App\Support\WebWafAudit;
use PHPUnit\Framework\TestCase;

final class WebWafAuditTest extends TestCase
{
    private function nginx(): array
    {
        return ['transaction' => ['unique_id' => 'tx-123', 'time_stamp' => date('D M j H:i:s Y'), 'client_ip' => '192.0.2.1',
            'producer' => ['secrules_engine' => 'Enabled'], 'response' => ['http_code' => 403],
            'request' => ['method' => 'POST', 'uri' => '/search?password=SECRET', 'body' => 'PRIVATE_BODY'],
            'messages' => [['message' => 'PRIVATE_MESSAGE', 'details' => ['ruleId' => '942100', 'severity' => '2', 'data' => 'Matched at ARGS:q: PRIVATE_ARGUMENT']]]]];
    }

    public function test_http_403_is_not_treated_as_waf_block_without_intervention_and_mode(): void
    {
        $record = $this->nginx();
        $this->assertSame('detected', WebWafAudit::events($record)[0]['outcome']);
        $this->assertSame('blocked', WebWafAudit::events($record, [], ['tx-123' => true])[0]['outcome']);
        $record['transaction']['producer']['secrules_engine'] = 'DetectionOnly';
        $this->assertSame('detected', WebWafAudit::events($record, [], ['tx-123' => true])[0]['outcome']);
    }

    public function test_only_allowlisted_metadata_and_static_descriptions_leave_server(): void
    {
        $row = WebWafAudit::events($this->nginx(), [942100 => 'SQL injection'])[0];
        $this->assertSame('/search', $row['path']);
        $this->assertSame('q', $row['parameter']);
        $this->assertSame('SQL injection', $row['message']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($row));
        $this->assertStringNotContainsString('SECRET', json_encode($row));
        $this->assertSame(['tx-123' => true], WebWafAudit::blockedTransactions('ModSecurity: Access denied with code 403 [unique_id "tx-123"]'));
    }

    public function test_apache_microsecond_timestamp_is_preserved_and_stale_or_invalid_records_ignored(): void
    {
        $time = time() - 3600;
        $record = ['transaction' => ['transaction_id' => 'apache1', 'time' => date('d/M/Y:H:i:s', $time).'.123456 +0000', 'remote_address' => '192.0.2.1'],
            'request' => ['request_line' => 'GET /path?secret=VALUE HTTP/1.1'],
            'audit_data' => ['engine_mode' => 'ENABLED', 'messages' => ['Access denied with code 403 [id "942100"] [severity "CRITICAL"]']]];
        $this->assertSame($time, WebWafAudit::events($record)[0]['occurred_at']);
        $this->assertSame('blocked', WebWafAudit::events($record)[0]['outcome']);
        foreach (['invalid', date('c', time() - 8 * 86400), date('c', time() + 86400)] as $date) {
            $record['transaction']['time'] = $date;
            $this->assertSame([], WebWafAudit::events($record));
        }
    }
}
