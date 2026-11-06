<?php

namespace App\Services;

/**
 * The certificate a web server presents for a host name (TLS with SNI), read without verifying it and without
 * sending a request. Used where ISPConfig keeps the certificate only on the web server (Let's Encrypt).
 */
class TlsCertificateProbe
{
    /** PEM of the certificate $host:$port serves for $serverName, or null when it cannot be read in time. */
    public function fetch(string $host, string $serverName, int $port = 443, float $timeout = 3.0): ?string
    {
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$host.']' : $host;
        $context = stream_context_create(['ssl' => [
            'peer_name' => $serverName,
            'SNI_enabled' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
        ]]);
        $stream = @stream_socket_client('ssl://'.$address.':'.$port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            return null;
        }

        $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        fclose($stream);

        return $certificate !== null && openssl_x509_export($certificate, $pem) ? $pem : null;
    }
}
