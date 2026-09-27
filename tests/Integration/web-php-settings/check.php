<?php

// Disposable CGI/FPM acceptance fixture; no customer PHP is executed.
$root = '/tmp/php-runtime-'.bin2hex(random_bytes(6));
mkdir($root, 0755);
$script = $root.'/read.php';
file_put_contents($script, '<?php $out=[]; foreach (["memory_limit","max_execution_time","max_input_time","post_max_size","upload_max_filesize","opcache.enable","error_reporting","display_errors","log_errors","allow_url_fopen","file_uploads","short_open_tag"] as $key) {$out[$key]=ini_get($key);} foreach(["exec","shell_exec","opcache_get_status"] as $key){$out[$key]=function_exists($key);} echo json_encode($out);');
$base = "memory_limit=1024M\nmax_execution_time=30\nmax_input_time=30\npost_max_size=512M\nupload_max_filesize=512M\ndisable_functions=exec,shell_exec\n";
$changes = ['opcache.enable' => 'off', 'error_reporting' => 'E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED', 'display_errors' => 'on', 'log_errors' => 'off', 'allow_url_fopen' => 'off', 'file_uploads' => 'off', 'short_open_tag' => 'off'];
function record(int $type, string $body): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
}
function request(string $socket, string $script): string
{
    $fp = false;
    for ($i = 0; $i < 60 && ! $fp; $i++) {
        $fp = @stream_socket_client('unix://'.$socket, $errno, $error, .1);
        if (! $fp) {
            usleep(50000);
        }
    }
    if (! $fp) {
        throw new RuntimeException('FPM did not start');
    }
    stream_set_timeout($fp, 5);
    $params = '';
    foreach (['SCRIPT_FILENAME' => $script, 'SCRIPT_NAME' => '/read.php', 'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'GATEWAY_INTERFACE' => 'CGI/1.1'] as $key => $value) {
        $params .= chr(strlen($key)).chr(strlen($value)).$key.$value;
    }
    fwrite($fp, record(1, pack('nC', 1, 0).str_repeat("\0", 5)).record(4, $params).record(4, '').record(5, ''));
    $out = '';
    while (! feof($fp)) {
        $head = '';
        while (strlen($head) < 8 && ($part = fread($fp, 8 - strlen($head))) !== '') {
            $head .= $part;
        }
        if (strlen($head) !== 8) {
            break;
        }
        $h = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $head);
        $body = '';
        $length = $h['length'] + $h['padding'];
        while (strlen($body) < $length && ($part = fread($fp, $length - strlen($body))) !== '') {
            $body .= $part;
        }
        if ($h['type'] === 6) {
            $out .= substr($body, 0, $h['length']);
        }
        if ($h['type'] === 3) {
            break;
        }
    }
    fclose($fp);

    return $out;
}
try {
    foreach (['cgi', 'fpm'] as $mode) {
        foreach ([false, true] as $globallyDisabled) {
            foreach ([false, true] as $allow) {
                $ini = $root.'/php.ini';
                $custom = $changes + ['disable_functions' => '"exec,shell_exec'.($allow ? '' : ',opcache_get_status').'"'];
                file_put_contents($ini, ($globallyDisabled ? "opcache.enable=off\n" : '').$base);
                if ($globallyDisabled) {
                    $custom['opcache.enable'] = 'on';
                }
                $body = '';
                foreach ($custom as $key => $value) {
                    $body .= $key.'='.$value."\n";
                }
                if ($mode === 'cgi') {
                    file_put_contents($ini, $body, FILE_APPEND);
                    $process = proc_open(['php-cgi8.4', '-q', '-c', $ini, '-f', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    fclose($pipes[0]);
                    $out = stream_get_contents($pipes[1]);
                    $error = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $exit = proc_close($process);
                    if ($exit !== 0) {
                        throw new RuntimeException('CGI failed: '.$error);
                    }
                } else {
                    $pool = "[global]\nerror_log=$root/fpm.log\n[fixture]\nuser=www-data\ngroup=www-data\nlisten=$root/fpm.sock\npm=ondemand\npm.max_children=1\n";
                    foreach ($custom as $key => $value) {
                        $flag = in_array(strtolower($value), ['on', 'off', '0', '1', 'true', 'false', 'yes', 'no'], true);
                        $pool .= ($flag ? 'php_admin_flag' : 'php_admin_value').'['.$key.'] = '.$value."\n";
                    }
                    file_put_contents($root.'/fpm.conf', $pool);
                    $process = proc_open(['php-fpm8.4', '-F', '-c', $ini, '-y', $root.'/fpm.conf'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root.'/process.log', 'a'], 2 => ['file', $root.'/process.log', 'a']], $pipes);
                    try {
                        $out = request($root.'/fpm.sock', $script);
                    } finally {
                        proc_terminate($process);
                        proc_close($process);
                    }
                    $out = explode("\r\n\r\n", $out, 2)[1] ?? $out;
                }
                $data = json_decode(trim($out), true);
                if (! is_array($data)) {
                    throw new RuntimeException('Invalid output: '.$out);
                }
                foreach (['memory_limit' => '1024M', 'max_execution_time' => '30', 'max_input_time' => '30', 'post_max_size' => '512M', 'upload_max_filesize' => '512M', 'error_reporting' => '22519'] as $key => $value) {
                    if ($data[$key] !== $value) {
                        throw new RuntimeException($mode.' '.$key.' unexpected '.json_encode($data));
                    }
                }
                if ($data['exec'] || $data['shell_exec'] || $data['opcache_get_status'] !== $allow) {
                    throw new RuntimeException('Function restrictions changed incorrectly: '.json_encode($data));
                }
                foreach (['log_errors', 'allow_url_fopen', 'file_uploads', 'short_open_tag'] as $key) {
                    if (! in_array($data[$key], ['', '0', 'off'], true)) {
                        throw new RuntimeException('Boolean not applied: '.$key);
                    }
                }
                if (! in_array($data['display_errors'], ['1', 'on'], true)) {
                    throw new RuntimeException('display_errors not applied');
                }
                if (in_array($data['opcache.enable'], ['1', 'on'], true) !== ($globallyDisabled && $mode === 'cgi')) {
                    throw new RuntimeException('OPcache startup restriction not respected');
                }
                echo 'PASS '.($globallyDisabled ? 'globally disabled OPcache ' : '').$mode.' PHP values and function '.($allow ? 'enabled' : 'blocked')."\n";
            }
        }
    }
} finally {
    foreach (glob($root.'/*') as $file) {
        unlink($file);
    }rmdir($root);
}
