<?php

require '/app/app/Support/WebWafPolicy.php';
require '/app/app/Support/WebWafAudit.php';
use App\Support\WebWafAudit;
use App\Support\WebWafPolicy;

$identity = WebWafPolicy::identity(['domain_id' => 1, 'server_id' => 1, 'sys_groupid' => 5, 'domain' => 'waf.test']);
$catalog = WebWafAudit::catalog();
if (! isset($catalog[942100])) {
    throw new RuntimeException('CRS descriptions unavailable');
}
$data = WebWafAudit::read($identity, [], $catalog);
if (! array_filter($data['events'], fn ($event) => $event['outcome'] === 'detected') || ! array_filter($data['events'], fn ($event) => $event['outcome'] === 'blocked')) {
    echo json_encode($data),"\n";
    throw new RuntimeException('Detection/block classification incorrect');
}
$encoded = json_encode($data);
foreach (['SECRET_VALUE', 'User-Agent', 'headers', '1=1', 'password='] as $secret) {
    if (str_contains($encoded, $secret)) {
        throw new RuntimeException('Private request data leaked');
    }
}
if (WebWafAudit::read($identity, $data['position'], $catalog)['events'] !== []) {
    throw new RuntimeException('Cursor repeats events');
}
foreach ($data['events'] as $event) {
    if ($event['rule_id'] === 942100 && ($event['parameter'] === '' || $event['message'] === 'Rule 942100 matched')) {
        throw new RuntimeException('Rule context missing');
    }
}
$summary = array_filter($data['events'], fn ($event) => $event['rule_id'] === 949110);
if (! $summary) {
    throw new RuntimeException('Native summary event missing');
}
foreach ($summary as $event) {
    if ($event['message'] !== 'Inbound anomaly score exceeded (total: 5)') {
        throw new RuntimeException('Native summary score missing or incorrect');
    }
}
echo 'PASS '.$argv[1]." audit metadata, real interventions, privacy, argument names, cursor\n";

$unsafe = '/tmp/waf-audit-safety';
mkdir($unsafe, 0700);
symlink('/etc/passwd', $unsafe.'/'.$identity.'.json');
try {
    WebWafAudit::read($identity, [], [], $unsafe);
    throw new LogicException('Followed symlink');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'waf_log_unavailable') {
        throw $e;
    }
}
unlink($unsafe.'/'.$identity.'.json');
file_put_contents($unsafe.'/'.$identity.'.json', "{}\n");
chmod($unsafe.'/'.$identity.'.json', 0666);
clearstatcache();
try {
    WebWafAudit::read($identity, [], [], $unsafe);
    throw new LogicException('Accepted writable file');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'waf_log_unavailable') {
        throw $e;
    }
}
echo "PASS audit symlink and writable file rejection\n";
