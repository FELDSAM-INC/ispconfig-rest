<?php

namespace App\Services;

/**
 * Service for handling DNS record meta fields
 */
class DnsRecordMetaService
{
    /**
     * Convert meta fields to data field based on record type
     *
     * @param array $data The input data with meta fields
     * @param string $type The DNS record type
     * @return array The aux and formatted data field
     */
    public static function metaToData(array $data, string $type)
    {
        $type = strtoupper($type);

        switch ($type) {
            case 'MX':
                return self::formatMxData($data);
            case 'SRV':
                return self::formatSrvData($data);
            case 'TLSA':
                return self::formatTlsaData($data);
            case 'SSHFP':
                return self::formatSshfpData($data);
            case 'CAA':
                return self::formatCaaData($data);
            case 'HINFO':
                return self::formatHinfoData($data);
            case 'SPF':
                return self::formatSpfData($data);
            case 'DMARC':
                return self::formatDmarcData($data);
            case 'NAPTR':
                return self::formatNaptrData($data);
            case 'DS':
                return self::formatDsData($data);
            default:
                return [$data['aux'] ?? '', $data['data'] ?? ''];
        }
    }

    /**
     * Convert data field to meta fields based on record type
     *
     * @param array $data The attributes from the database
     * @param string $type The DNS record type
     * @return array The extracted meta fields
     */
    public static function dataToMeta(array $data, string $type)
    {
        $type = strtoupper($type);

        // If the type is TXT, we need to guess if it is SPF or DMARC
        if($type === 'TXT') {
            $type = self::guessType($data['data']);
        }

        switch ($type) {
            case 'MX':
                return self::parseMxData($data);
            case 'SRV':
                return self::parseSrvData($data);
            case 'TLSA':
                return self::parseTlsaData($data);
            case 'SSHFP':
                return self::parseSshfpData($data);
            case 'CAA':
                return self::parseCaaData($data);
            case 'HINFO':
                return self::parseHinfoData($data);
            case 'SPF':
                return self::parseSpfData($data);
            case 'DMARC':
                return self::parseDmarcData($data);
            case 'NAPTR':
                return self::parseNaptrData($data);
            case 'DS':
                return self::parseDsData($data);
            default:
                return [];
        }
    }

    /**
     * Guess the record type based on the data
     *
     * @param string $data The data field from the database
     * @return string The guessed record type
     */
    private static function guessType(string $data)
    {
        if (strpos($data, 'v=spf1') !== false) {
            return 'SPF';
        }
        if (strpos($data, 'v=DMARC1') !== false) {
            return 'DMARC';
        }

        return 'TXT';
    }

    /**
     * Format MX record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatMxData(array $data)
    {
        // MX records contain a priority and a hostname
        $priority = $data['priority'] ?? 10; // Default priority is 10
        $hostname = $data['hostname'] ?? '';

        // Ensure hostname ends with a dot if not empty
        if (!empty($hostname) && substr($hostname, -1) !== '.') {
            $hostname .= '.';
        }

        return [$priority, $hostname];
    }

    /**
     * Parse MX record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseMxData(array $data)
    {
        $priority = $data['aux'];
        $hostname = $data['data'];

        // Remove trailing dot if present
        if (!empty($hostname) && substr($hostname, -1) === '.') {
            $hostname = substr($hostname, 0, -1);
        }

        return [
            'priority' => $priority,
            'hostname' => $hostname
        ];
    }

    /**
     * Format SRV record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatSrvData(array $data)
    {
        // SRV format: priority weight port target
        $priority = $data['priority'] ?? 0;
        $weight = $data['weight'] ?? 0;
        $port = $data['port'] ?? 0;
        $hostname = $data['hostname'] ?? '';

        // Ensure hostname ends with a dot if not empty
        if (!empty($hostname) && substr($hostname, -1) !== '.') {
            $hostname .= '.';
        }

        return [$priority, $weight . ' ' . $port . ' ' . $hostname];
    }

    /**
     * Parse SRV record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseSrvData(array $data)
    {
        // Split the data into priority, weight, port, and target
        $parts = preg_split('/\s+/', $data['data'], 3);

        if (count($parts) < 3) {
            return [];
        }

        $hostname = $parts[2];

        // Remove trailing dot if present
        if (!empty($hostname) && substr($hostname, -1) === '.') {
            $hostname = substr($hostname, 0, -1);
        }

        return [
            'priority' => (int)$data['aux'],
            'weight' => (int)$parts[0],
            'port' => (int)$parts[1],
            'hostname' => $hostname
        ];
    }

    /**
     * Format TLSA record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatTlsaData(array $data)
    {
        // TLSA format: usage selector matching_type certificate
        $certUsage = $data['cert_usage'] ?? 0;
        $selector = $data['selector'] ?? 0;
        $matchingType = $data['matching_type'] ?? 0;
        $hash = $data['hash'] ?? '';

        return ['', $certUsage . ' ' . $selector . ' ' . $matchingType . ' ' . $hash];
    }

    /**
     * Parse TLSA record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseTlsaData(array $data)
    {
        // Split the data into usage, selector, matching_type, and certificate
        $parts = preg_split('/\s+/', $data['data'], 4);

        if (count($parts) < 4) {
            return [];
        }

        return [
            'cert_usage' => (int)$parts[0],
            'selector' => (int)$parts[1],
            'matching_type' => (int)$parts[2],
            'hash' => $parts[3]
        ];
    }

    /**
     * Format SSHFP record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatSshfpData(array $data)
    {
        // SSHFP format: algorithm hash_type hash
        $algorithm = $data['algorithm'] ?? 0;
        $hashType = $data['hash_type'] ?? 0;
        $hash = $data['hash'] ?? '';

        return ['', $algorithm . ' ' . $hashType . ' ' . $hash];
    }

    /**
     * Parse SSHFP record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseSshfpData(array $data)
    {
        // Split the data into algorithm, fingerprint_type, and fingerprint
        $parts = preg_split('/\s+/', $data['data'], 3);

        if (count($parts) < 3) {
            return [];
        }

        return [
            'algorithm' => (int)$parts[0],
            'hash_type' => (int)$parts[1],
            'hash' => $parts[2]
        ];
    }

    /**
     * Format CAA record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatCaaData(array $data)
    {
        // CAA format: flag tag value
        $flag = $data['caa_flag'] ?? 0;
        $tag = $data['caa_type'] ?? '';

        // Determine value based on tag type
        $value = '';
        if ($tag === 'iodef') {
            $value = $data['additional'] ?? '';
        } else {
            $value = $data['ca_issuer'] ?? '';
        }

        // Add quotes to value if needed
        if (!empty($value) && $value[0] !== '"' && substr($value, -1) !== '"') {
            $value = '"' . $value . '"';
        }

        return ['', $flag . ' ' . $tag . ' ' . $value];
    }

    /**
     * Parse CAA record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseCaaData(array $data)
    {
        // Split the data into flag, tag, and value
        $parts = preg_split('/\s+/', $data['data'], 3);

        if (count($parts) < 3) {
            return [];
        }

        $flag = (int)$parts[0];
        $tag = $parts[1];
        $value = $parts[2];

        // Remove quotes from value if present
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }

        $result = [
            'caa_flag' => $flag,
            'caa_type' => $tag
        ];

        // Add appropriate field based on tag type
        if ($tag === 'iodef') {
            $result['additional'] = $value;
        } else {
            $result['ca_issuer'] = $value;
        }

        return $result;
    }

    /**
     * Format HINFO record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatHinfoData(array $data)
    {
        // HINFO format: cpu os
        $cpu = $data['cpu'] ?? '';
        $os = $data['os'] ?? '';

        // Add quotes if needed
        if (!empty($cpu) && $cpu[0] !== '"' && substr($cpu, -1) !== '"') {
            $cpu = '"' . $cpu . '"';
        }

        if (!empty($os) && $os[0] !== '"' && substr($os, -1) !== '"') {
            $os = '"' . $os . '"';
        }

        return ['', $cpu . ' ' . $os];
    }

    /**
     * Parse HINFO record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseHinfoData(array $data)
    {
        // Split the data into CPU and OS
        $parts = preg_split('/\s+/', $data['data'], 2);

        if (count($parts) < 2) {
            return [];
        }

        $cpu = $parts[0];
        $os = $parts[1];

        // Remove quotes if present
        if (strlen($cpu) >= 2 && $cpu[0] === '"' && substr($cpu, -1) === '"') {
            $cpu = substr($cpu, 1, -1);
        }

        if (strlen($os) >= 2 && $os[0] === '"' && substr($os, -1) === '"') {
            $os = substr($os, 1, -1);
        }

        return [
            'cpu' => $cpu,
            'os' => $os
        ];
    }

    /**
     * Format SPF record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatSpfData(array $data)
    {
        $allowMx = $data['allow_mx'] ?? false;
        $allowA = $data['allow_a'] ?? false;
        $ipv4Addresses = preg_split('/\s+/', $data['ipv4_address'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $ipv6Addresses = preg_split('/\s+/', $data['ipv6_address'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $hostnames = preg_split('/\s+/', $data['hostname'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $includes = preg_split('/\s+/', $data['include'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $policy = $data['policy'] ?? 'fail';

        $spf = [];

        if ($allowMx) {
            $spf[] = 'mx';
        }

        if ($allowA) {
            $spf[] = 'a';
        }

        foreach ($ipv4Addresses as $ipv4Address) {
            $spf[] = 'ip4:' . $ipv4Address;
        }

        foreach ($ipv6Addresses as $ipv6Address) {
            $spf[] = 'ip6:' . $ipv6Address;
        }

        foreach ($hostnames as $hostname) {
            $spf[] = 'a:' . $hostname;
        }

        foreach ($includes as $include) {
            $spf[] = 'include:' . $include;
        }

        switch ($policy) {
            case 'softfail':
                $spf[] = '~all';
                break;
            case 'fail':
                $spf[] = '-all';
                break;
            case 'neutral':
                $spf[] = '?all';
                break;
        }

        return ['', 'v=spf1 ' . implode(' ', $spf)];
    }

    /**
     * Parse SPF record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseSpfData(array $data)
    {
        $result = [
            'allow_mx' => false,
            'allow_a' => false,
            'ipv4_address' => '',
            'ipv6_address' => '',
            'hostname' => '',
            'include' => '',
            'policy' => 'fail'
        ];

        foreach (preg_split('/\s+/', $data['data']) as $part) {
            switch (true) {
                case $part === 'mx':
                    $result['allow_mx'] = true;
                    break;
                case $part === 'a':
                    $result['allow_a'] = true;
                    break;
                case strpos($part, 'ip4:') === 0:
                    $result['ipv4_address'] .= substr($part, 4) . ' ';
                    break;
                case strpos($part, 'ip6:') === 0:
                    $result['ipv6_address'] .= substr($part, 4) . ' ';
                    break;
                case strpos($part, 'a:') === 0:
                    $result['hostname'] .= substr($part, 2) . ' ';
                    break;
                case strpos($part, 'include:') === 0:
                    $result['include'] .= substr($part, 8) . ' ';
                    break;
                case in_array(substr($part, 0, 1), ['~', '-', '?']):
                    switch (substr($part, 0, 1)) {
                        case '~':
                            $result['policy'] = 'softfail';
                            break;
                        case '-':
                            $result['policy'] = 'fail';
                            break;
                        case '?':
                            $result['policy'] = 'neutral';
                            break;
                    }
                    break;
            }
        }

        // Trim trailing spaces
        $result['ipv4_address'] = trim($result['ipv4_address']);
        $result['ipv6_address'] = trim($result['ipv6_address']);
        $result['hostname'] = trim($result['hostname']);
        $result['include'] = trim($result['include']);

        return $result;
    }

    /**
     * Format DMARC record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatDmarcData(array $data)
    {
        $policy = $data['policy'] ?? 'none';
        $pct = $data['pct'] ?? 100;
        $rua = $data['rua'] ?? '';
        $ruf = $data['ruf'] ?? '';
        $sp = $data['sp'] ?? 'none';
        $adkim = $data['adkim'] ?? 'r';
        $aspf = $data['aspf'] ?? 'r';

        $dmarc = [];

        if ($policy !== 'none') {
            $dmarc[] = "p={$policy}";
        }

        if ($pct !== 100) {
            $dmarc[] = "pct={$pct}";
        }

        if (!empty($rua)) {
            $dmarc[] = "rua={$rua}";
        }

        if (!empty($ruf)) {
            $dmarc[] = "ruf={$ruf}";
        }

        if ($sp !== 'none') {
            $dmarc[] = "sp={$sp}";
        }

        if ($adkim !== 'r') {
            $dmarc[] = "adkim={$adkim}";
        }

        if ($aspf !== 'r') {
            $dmarc[] = "aspf={$aspf}";
        }

        return ['', 'v=DMARC1; ' . implode(';', $dmarc)];
    }

    /**
     * Parse DMARC record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseDmarcData(array $data)
    {
        $data = $data['data'];

        // Remove surrounding quotes if present
        if (strlen($data) >= 2 && $data[0] === '"' && substr($data, -1) === '"') {
            $data = substr($data, 1, -1);
        }

        $result = [
            'policy' => 'none',
            'pct' => 100,
            'rua' => '',
            'ruf' => '',
            'sp' => 'none',
            'adkim' => 'r',
            'aspf' => 'r',
        ];

        // Parse DMARC tags
        $tags = explode(';', $data);
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (empty($tag)) continue;

            $parts = explode('=', $tag, 2);
            if (count($parts) < 2) continue;

            $tagName = trim($parts[0]);
            $tagValue = trim($parts[1]);

            switch ($tagName) {
                case 'p':
                    $result['policy'] = $tagValue;
                    break;
                case 'pct':
                    $result['pct'] = $tagValue;
                    break;
                case 'rua':
                    $result['rua'] = $tagValue;
                    break;
                case 'ruf':
                    $result['ruf'] = $tagValue;
                    break;
                case 'sp':
                    $result['sp'] = $tagValue;
                    break;
                case 'adkim':
                    $result['adkim'] = $tagValue;
                    break;
                case 'aspf':
                    $result['aspf'] = $tagValue;
                    break;
            }
        }

        return $result;
    }

    /**
     * Format NAPTR record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatNaptrData(array $data)
    {
        // NAPTR format: order preference flags service regexp replacement
        $order = $data['order'] ?? 0;
        $preference = $data['preference'] ?? 0;
        $flags = $data['naptr_flag'] ?? '';
        $service = $data['service'] ?? '';
        $regexp = $data['regexp'] ?? '';
        $replacement = $data['replacement'] ?? '';

        // Add quotes to service and regexp if needed
        if (!empty($service) && $service[0] !== '"' && substr($service, -1) !== '"') {
            $service = '"' . $service . '"';
        }

        if (!empty($regexp) && $regexp[0] !== '"' && substr($regexp, -1) !== '"') {
            $regexp = '"' . $regexp . '"';
        }

        // Ensure replacement ends with a dot if not empty
        if (!empty($replacement) && substr($replacement, -1) !== '.') {
            $replacement .= '.';
        }

        return [$order, $preference . ' ' . $flags . ' ' . $service . ' ' . $regexp . ' ' . $replacement];
    }

    /**
     * Parse NAPTR record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseNaptrData(array $data)
    {
        // Split the data into components
        // example data: 100 "s" "http+I2R" "" _http._tcp.foo.com.
        // example data: 100 "s" "http+I2R" "[a-zA-Z]+\\.cz." .
        $parts = preg_split('/\s+/', $data['data'], 5);

        if (count($parts) < 5) {
            return [];
        }

        // Remove quotes from service and regexp if present
        $service = $parts[2];
        if (strlen($service) >= 2 && $service[0] === '"' && substr($service, -1) === '"') {
            $service = substr($service, 1, -1);
        }

        $regexp = $parts[3];
        if (strlen($regexp) >= 2 && $regexp[0] === '"' && substr($regexp, -1) === '"') {
            $regexp = substr($regexp, 1, -1);
        }

        // Remove trailing dot from replacement if present
        $replacement = $parts[4];
        if (!empty($replacement) && substr($replacement, -1) === '.') {
            $replacement = substr($replacement, 0, -1);
        }

        return [
            'order' => (int)$data['aux'],
            'preference' => (int)$parts[0],
            'naptr_flag' => $parts[1],
            'service' => $service,
            'regexp' => $regexp,
            'replacement' => $replacement
        ];
    }

    /**
     * Format DS record data from meta fields
     *
     * @param array $data The input data with meta fields
     * @return string The formatted data
     */
    private static function formatDsData(array $data)
    {
        // DS format: key tag algorithm digest type digest
        $keyTag = $data['key_tag'] ?? 0;
        $algorithm = $data['algorithm'] ?? 0;
        $digestType = $data['digest_type'] ?? 0;
        $digest = $data['digest'] ?? '';

        return ['', $keyTag . ' ' . $algorithm . ' ' . $digestType . ' ' . $digest];
    }

    /**
     * Parse DS record data into meta fields
     *
     * @param array $data The attributes from the database
     * @return array The extracted meta fields
     */
    private static function parseDsData(array $data)
    {
        $parts = preg_split('/\s+/', $data['data'], 4);

        if (count($parts) < 4) {
            return [];
        }

        return [
            'key_tag' => (int)$parts[0],
            'algorithm' => (int)$parts[1],
            'digest_type' => (int)$parts[2],
            'digest' => $parts[3]
        ];
    }
}
