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

    public function test_summary_scores_are_validated_numbers_on_both_engines(): void
    {
        $cases = [
            [949110, 'Inbound Anomaly Score Exceeded (Total Score: 5)', 'Inbound anomaly score exceeded (total: 5)'],
            [959100, 'Outbound Anomaly Score Exceeded (Total Score: 4)', 'Outbound anomaly score exceeded (total: 4)'],
            [980130, 'Inbound Anomaly Score Exceeded (Total Inbound Score: 8 - SQLI=5,XSS=3,RFI=0,LFI=0,RCE=0,PHPI=0,HTTP=0,SESS=0): individual paranoia level scores: 8, 0, 0, 0', 'Inbound anomaly score exceeded (total: 8; SQLI: 5; XSS: 3)'],
            [980140, 'Outbound Anomaly Score Exceeded (score 4): individual paranoia level scores: 4, 0, 0, 0', 'Outbound anomaly score exceeded (total: 4)'],
        ];
        foreach ($cases as [$id, $native, $expected]) {
            $nginx = $this->nginx();
            $nginx['transaction']['messages'][0]['details']['ruleId'] = (string) $id;
            $nginx['transaction']['messages'][0]['message'] = $native;
            $this->assertSame($expected, WebWafAudit::events($nginx, [$id => 'Summary [value]'])[0]['message']);
            $apache = ['transaction' => ['transaction_id' => 'apache-summary', 'time' => date('c')],
                'audit_data' => ['engine_mode' => 'ENABLED', 'messages' => ['Warning [id "'.$id.'"] [msg "'.$native.'"] [data "PRIVATE_DATA"]']]];
            $this->assertSame($expected, WebWafAudit::events($apache, [$id => 'Summary [value]'])[0]['message']);
        }
    }

    public function test_unknown_missing_or_unsafe_scores_have_concise_fallback_without_echoing_log_text(): void
    {
        foreach (['', 'Unexpected PRIVATE_MESSAGE', 'Inbound Anomaly Score Exceeded (Total Score: [value])',
            'Inbound Anomaly Score Exceeded (Total Score: 1234567890)', 'Inbound Anomaly Score Exceeded (Total Score: -1)',
            'Inbound Anomaly Score Exceeded (Total Score: 5<script>)', 'Inbound Anomaly Score Exceeded (Total Score: 5) PRIVATE_DATA'] as $native) {
            $record = $this->nginx();
            $record['transaction']['messages'][0]['details']['ruleId'] = '949110';
            $record['transaction']['messages'][0]['message'] = $native;
            $this->assertSame('Inbound anomaly score exceeded', WebWafAudit::events($record, [949110 => 'Summary [value]'])[0]['message']);
        }
        $this->assertSame('SQL injection', WebWafAudit::description(942100, 'SQL injection', 'PRIVATE_MESSAGE'));
    }
}
