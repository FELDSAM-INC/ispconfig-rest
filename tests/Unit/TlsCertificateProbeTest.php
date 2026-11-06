<?php

namespace Tests\Unit;

use App\Services\TlsCertificateProbe;
use PHPUnit\Framework\TestCase;

/** The probe against a real local TLS server: SNI reaches the server and its certificate comes back. */
class TlsCertificateProbeTest extends TestCase
{
    public function test_reads_the_certificate_a_server_presents_for_the_sni_name(): void
    {
        $dir = sys_get_temp_dir().'/tls-probe-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'shop.example.com'], $key), null, $key, 30);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $private);
        file_put_contents($dir.'/server.pem', $pem.$private);

        // One TLS connection, then exit; prints its port and the SNI name the client sent.
        $server = '$ctx = stream_context_create(["ssl" => ["local_cert" => $argv[1], "SNI_server_certs" => ["shop.example.com" => $argv[1]]]]);
            $s = stream_socket_server("tcp://127.0.0.1:0", $no, $err, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
            echo parse_url("tcp://".stream_socket_get_name($s, false), PHP_URL_PORT), "\n"; flush();
            $c = stream_socket_accept($s, 10);
            echo @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER) ? "handshake\n" : "failed\n";
            fclose($c);';
        $process = proc_open([PHP_BINARY, '-r', $server, $dir.'/server.pem'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);

        $served = (new TlsCertificateProbe)->fetch('127.0.0.1', 'shop.example.com', $port, 5.0);
        $this->assertSame("handshake\n", fgets($pipes[1]));
        proc_close($process);
        $this->assertSame(openssl_x509_fingerprint($pem, 'sha256'), openssl_x509_fingerprint((string) $served, 'sha256'));

        // Nothing listens: null within the timeout.
        $started = microtime(true);
        $this->assertNull((new TlsCertificateProbe)->fetch('127.0.0.1', 'shop.example.com', $port, 1.0));
        $this->assertLessThan(5, microtime(true) - $started);
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}
