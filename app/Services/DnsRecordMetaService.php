<?php

namespace App\Services;

/**
 * Composition/decomposition of type-specific DNS record fields
 * (contract: api/components/schemas/DnsRecord.yaml).
 *
 * The wire format of a record lives in `type` + `aux` + `data`. For
 * structured types the API accepts human-friendly meta fields at the top
 * level of the request; compose() assembles the stored values exactly like
 * the legacy ISPConfig forms (source_code/interface/web/dns/dns_*_edit.php):
 *
 *  - aux carries the MX/SRV priority and the NAPTR order;
 *  - NAPTR flags/service/regexp are double-quoted with zone-file escaping,
 *    the canonical preference field is `pref` end to end (spec 002 gap G03);
 *  - CAA values and HINFO cpu/os are double-quoted;
 *  - hostname targets are dot-terminated;
 *  - SPF (dns_spf_edit.php) and DMARC (dns_dmarc_edit.php) text is
 *    assembled from their meta fields, DMARC names are forced to
 *    `_dmarc.<origin>`;
 *  - the friendly types SPF, DKIM and DMARC are STORED as TXT rows
 *    (legacy parity, spec 002 gap G02) — the dns_rr.type enum has no such
 *    members.
 *
 * meta() reverses the process for read responses: stored TXT rows are
 * re-classified via data-prefix heuristics (v=spf1 / v=DMARC1 / v=DKIM1)
 * and every structured record gets its fields decomposed from aux/data.
 */
class DnsRecordMetaService
{
    /**
     * Types whose data/aux are composed from meta fields.
     *
     * @var array<int, string>
     */
    public const STRUCTURED_TYPES = ['MX', 'SRV', 'TLSA', 'SSHFP', 'CAA', 'HINFO', 'SPF', 'DMARC', 'NAPTR', 'DS'];

    /**
     * Friendly types persisted as TXT rows (dns_rr.type enum has no such
     * members; legacy dns_spf/dns_dkim/dns_dmarc forms store type TXT).
     *
     * @var array<int, string>
     */
    public const TXT_STORED_TYPES = ['SPF', 'DKIM', 'DMARC'];

    /**
     * The request meta fields compose() reads per structured type — the
     * single source for the FR-013 recompose guard (DnsRecordController)
     * and the update-request zone-check gating (spec 013 FR-012/FR-013).
     *
     * @var array<string, array<int, string>>
     */
    public const META_FIELDS = [
        'MX' => ['priority', 'hostname'],
        'SRV' => ['priority', 'weight', 'port', 'hostname'],
        'TLSA' => ['cert_usage', 'selector', 'matching_type', 'hash'],
        'SSHFP' => ['algorithm', 'hash_type', 'hash'],
        'CAA' => ['caa_flag', 'caa_type', 'ca_issuer', 'additional'],
        'HINFO' => ['cpu', 'os'],
        'SPF' => ['allow_mx', 'allow_a', 'ipv4_address', 'ipv6_address', 'hostname', 'include', 'policy'],
        'DMARC' => ['policy', 'pct', 'rua', 'ruf', 'sp', 'adkim', 'aspf'],
        'NAPTR' => ['order', 'pref', 'naptr_flag', 'service', 'regexp', 'replacement'],
        'DS' => ['key_tag', 'algorithm', 'digest_type', 'digest'],
    ];

    /**
     * The meta fields a structured type composes from (empty for simple
     * types, whose payload is `data`/`aux`).
     *
     * @return array<int, string>
     */
    public static function metaFieldsFor(string $type): array
    {
        return self::META_FIELDS[$type] ?? [];
    }

    /**
     * Compose the storable column values for a record write.
     *
     * @param  array<string, mixed>  $input  validated request fields (meta fields at top level)
     * @param  string  $type  the requested (friendly) record type, uppercase
     * @param  array<string, mixed>  $zone  the parent dns_soa row (raw attributes)
     * @return array{type: string, aux: int, data: string, name?: string}
     */
    public function compose(array $input, string $type, array $zone): array
    {
        return match ($type) {
            'MX' => [
                'type' => 'MX',
                'aux' => (int) ($input['priority'] ?? 10), // legacy dns_mx.tform aux default 10
                'data' => $this->dotTerminate((string) ($input['hostname'] ?? '')),
            ],
            'SRV' => [
                'type' => 'SRV',
                'aux' => (int) ($input['priority'] ?? 0),
                'data' => (int) ($input['weight'] ?? 0).' '.(int) ($input['port'] ?? 0).' '
                    .$this->dotTerminate((string) ($input['hostname'] ?? '')),
            ],
            'TLSA' => [
                'type' => 'TLSA',
                'aux' => 0,
                'data' => (int) ($input['cert_usage'] ?? 0).' '.(int) ($input['selector'] ?? 0).' '
                    .(int) ($input['matching_type'] ?? 0).' '.($input['hash'] ?? ''),
            ],
            'SSHFP' => [
                'type' => 'SSHFP',
                'aux' => 0,
                'data' => (int) ($input['algorithm'] ?? 0).' '.(int) ($input['hash_type'] ?? 0).' '.($input['hash'] ?? ''),
            ],
            'CAA' => [
                'type' => 'CAA',
                'aux' => 0,
                'data' => $this->composeCaa($input),
            ],
            'HINFO' => [
                'type' => 'HINFO',
                'aux' => 0,
                'data' => $this->quote((string) ($input['cpu'] ?? '')).' '.$this->quote((string) ($input['os'] ?? '')),
            ],
            'SPF' => [
                'type' => 'TXT',
                'aux' => 0,
                'data' => $this->composeSpf($input),
            ],
            'DKIM' => [
                'type' => 'TXT',
                'aux' => 0,
                'data' => (string) ($input['data'] ?? ''),
            ],
            'DMARC' => [
                'type' => 'TXT',
                'aux' => 0,
                'data' => $this->composeDmarc($input),
                // Legacy dns_dmarc_edit.php forces the record name.
                'name' => '_dmarc.'.($zone['origin'] ?? ''),
            ],
            'NAPTR' => [
                'type' => 'NAPTR',
                'aux' => (int) ($input['order'] ?? 0),
                'data' => $this->composeNaptr($input),
            ],
            'DS' => [
                'type' => 'DS',
                'aux' => 0,
                'data' => (int) ($input['key_tag'] ?? 0).' '.(int) ($input['algorithm'] ?? 0).' '
                    .(int) ($input['digest_type'] ?? 0).' '.($input['digest'] ?? ''),
            ],
            // Simple types: raw data, accidental wrapping quotes stripped
            // (legacy dns_edit_base.php::onSubmit).
            default => [
                'type' => $type,
                'aux' => (int) ($input['aux'] ?? 0),
                'data' => $this->stripAccidentalQuotes((string) ($input['data'] ?? '')),
            ],
        };
    }

    /**
     * The effective (friendly) type of a stored row: TXT rows are classified
     * by data prefix (legacy dns_spf_edit.php queries `data LIKE 'v=spf1%'`,
     * dns_dmarc_edit.php `v=DMARC1%`, dns_dkim_edit.php composes `v=DKIM1`).
     */
    public function classify(string $type, string $data): string
    {
        if (strtoupper($type) !== 'TXT') {
            return strtoupper($type);
        }

        return match (true) {
            str_starts_with($data, 'v=spf1') => 'SPF',
            str_starts_with($data, 'v=DMARC1') => 'DMARC',
            str_starts_with($data, 'v=DKIM1') => 'DKIM',
            default => 'TXT',
        };
    }

    /**
     * Computed read-only `meta` object for a stored record: the type-specific
     * fields decomposed from aux/data. Classified TXT rows additionally carry
     * the friendly type in `type`. Simple types yield an empty object.
     *
     * @param  array<string, mixed>  $attributes  raw dns_rr attributes (type, aux, data, name)
     * @return array<string, mixed>
     */
    public function meta(array $attributes): array
    {
        $storedType = strtoupper((string) ($attributes['type'] ?? ''));
        $data = (string) ($attributes['data'] ?? '');
        $aux = (int) ($attributes['aux'] ?? 0);
        $type = $this->classify($storedType, $data);

        $meta = match ($type) {
            'MX' => $this->parseMx($aux, $data),
            'SRV' => $this->parseSrv($aux, $data),
            'TLSA' => $this->parseTlsa($data),
            'SSHFP' => $this->parseSshfp($data),
            'CAA' => $this->parseCaa($data),
            'HINFO' => $this->parseHinfo($data),
            'SPF' => $this->parseSpf($data),
            'DMARC' => $this->parseDmarc($data),
            'DKIM' => $this->parseDkim((string) ($attributes['name'] ?? ''), $data),
            'NAPTR' => $this->parseNaptr($aux, $data),
            'DS' => $this->parseDs($data),
            default => [],
        };

        // Reads re-classify stored TXT rows back to their friendly type.
        if ($storedType === 'TXT' && $type !== 'TXT') {
            $meta = ['type' => $type] + $meta;
        }

        return $meta;
    }

    // ------------------------------------------------------------------
    // Composition helpers
    // ------------------------------------------------------------------

    /**
     * Legacy dns_caa_edit.php: `{flag} {tag} "{value}"` where the value is
     * the CA issuer (issue/issuewild) or the iodef contact (additional).
     */
    protected function composeCaa(array $input): string
    {
        $flag = (int) ($input['caa_flag'] ?? 0);
        $tag = (string) ($input['caa_type'] ?? 'issue');
        $value = $tag === 'iodef'
            ? (string) ($input['additional'] ?? '')
            : (string) ($input['ca_issuer'] ?? '');

        return $flag.' '.$tag.' '.$this->quote($value);
    }

    /**
     * Legacy dns_spf_edit.php::onSubmit — token order: mx, a, ip4:, ip6:,
     * a:<hostname>, include:<domain>, then the mechanism-prefixed `all`.
     */
    protected function composeSpf(array $input): string
    {
        $tokens = [];

        if (! empty($input['allow_mx'])) {
            $tokens[] = 'mx';
        }

        if (! empty($input['allow_a'])) {
            $tokens[] = 'a';
        }

        foreach ($this->splitList((string) ($input['ipv4_address'] ?? '')) as $ip) {
            $tokens[] = 'ip4:'.$ip;
        }

        foreach ($this->splitList((string) ($input['ipv6_address'] ?? '')) as $ip) {
            $tokens[] = 'ip6:'.$ip;
        }

        foreach ($this->splitList((string) ($input['hostname'] ?? '')) as $hostname) {
            $tokens[] = 'a:'.$hostname;
        }

        foreach ($this->splitList((string) ($input['include'] ?? '')) as $domain) {
            $tokens[] = 'include:'.$domain;
        }

        $mechanism = match ($input['policy'] ?? 'fail') {
            'softfail' => '~',
            'neutral' => '?',
            default => '-', // fail
        };

        $tokens[] = $mechanism.'all';

        return 'v=spf1 '.implode(' ', $tokens);
    }

    /**
     * Legacy dns_dmarc_edit.php::onSubmit — `v=DMARC1; ` + '; '-joined tags:
     * p= always first; rua/ruf as comma-joined mailto: addresses; adkim/aspf
     * only when strict (legacy omits the 'r' defaults); pct only when not
     * 100; sp when given (the contract has no legacy 'same' sentinel — an
     * omitted sp means "same as p", matching legacy's omission).
     */
    protected function composeDmarc(array $input): string
    {
        $tags = ['p='.($input['policy'] ?? 'none')];

        foreach (['rua', 'ruf'] as $reportTag) {
            $addresses = $this->splitList((string) ($input[$reportTag] ?? ''));

            if ($addresses !== []) {
                $mailtos = array_map(
                    fn (string $address): string => str_starts_with($address, 'mailto:') ? $address : 'mailto:'.$address,
                    $addresses
                );
                $tags[] = $reportTag.'='.implode(',', $mailtos);
            }
        }

        if (($input['adkim'] ?? 'r') !== 'r') {
            $tags[] = 'adkim='.$input['adkim'];
        }

        if (($input['aspf'] ?? 'r') !== 'r') {
            $tags[] = 'aspf='.$input['aspf'];
        }

        $pct = (int) ($input['pct'] ?? 100);
        if ($pct !== 100) {
            $tags[] = 'pct='.$pct;
        }

        if (! empty($input['sp'])) {
            $tags[] = 'sp='.$input['sp'];
        }

        return 'v=DMARC1; '.implode('; ', $tags);
    }

    /**
     * Legacy dns_naptr_edit.php::onSubmit —
     * `<pref> "<flags>" "<service>" "<regexp>" <replacement.>` with
     * zone-file escaping inside the quoted parts; aux carries the order.
     * The canonical preference field is `pref` (spec 002 gap G03).
     */
    protected function composeNaptr(array $input): string
    {
        $replacement = (string) ($input['replacement'] ?? '');
        $replacement .= str_ends_with($replacement, '.') ? '' : '.';

        return (int) ($input['pref'] ?? 0).' '
            .'"'.$this->zoneFileEscape((string) ($input['naptr_flag'] ?? '')).'" '
            .'"'.$this->zoneFileEscape((string) ($input['service'] ?? '')).'" '
            .'"'.$this->zoneFileEscape((string) ($input['regexp'] ?? '')).'" '
            .$replacement;
    }

    // ------------------------------------------------------------------
    // Decomposition helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function parseMx(int $aux, string $data): array
    {
        return [
            'priority' => $aux,
            'hostname' => rtrim($data, '.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseSrv(int $aux, string $data): array
    {
        $parts = preg_split('/\s+/', trim($data), 3);

        if (count($parts) < 3) {
            return [];
        }

        return [
            'priority' => $aux,
            'weight' => (int) $parts[0],
            'port' => (int) $parts[1],
            'hostname' => rtrim($parts[2], '.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseTlsa(string $data): array
    {
        $parts = preg_split('/\s+/', trim($data), 4);

        if (count($parts) < 4) {
            return [];
        }

        return [
            'cert_usage' => (int) $parts[0],
            'selector' => (int) $parts[1],
            'matching_type' => (int) $parts[2],
            'hash' => $parts[3],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseSshfp(string $data): array
    {
        $parts = preg_split('/\s+/', trim($data), 3);

        if (count($parts) < 3) {
            return [];
        }

        return [
            'algorithm' => (int) $parts[0],
            'hash_type' => (int) $parts[1],
            'hash' => $parts[2],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseCaa(string $data): array
    {
        $parts = preg_split('/\s+/', trim($data), 3);

        if (count($parts) < 3) {
            return [];
        }

        $meta = [
            'caa_flag' => (int) $parts[0],
            'caa_type' => $parts[1],
        ];

        $value = $this->unquote($parts[2]);
        $meta[$parts[1] === 'iodef' ? 'additional' : 'ca_issuer'] = $value;

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseHinfo(string $data): array
    {
        if (! preg_match('/^\s*("[^"]*"|\S+)\s+("[^"]*"|.+?)\s*$/', $data, $matches)) {
            return [];
        }

        return [
            'cpu' => $this->unquote($matches[1]),
            'os' => $this->unquote($matches[2]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseSpf(string $data): array
    {
        $meta = [
            'allow_mx' => false,
            'allow_a' => false,
            'ipv4_address' => '',
            'ipv6_address' => '',
            'hostname' => '',
            'include' => '',
            'policy' => 'fail',
        ];

        foreach (preg_split('/\s+/', trim($data)) as $token) {
            match (true) {
                $token === 'mx' => $meta['allow_mx'] = true,
                $token === 'a' => $meta['allow_a'] = true,
                str_starts_with($token, 'ip4:') => $meta['ipv4_address'] .= substr($token, 4).' ',
                str_starts_with($token, 'ip6:') => $meta['ipv6_address'] .= substr($token, 4).' ',
                str_starts_with($token, 'a:') => $meta['hostname'] .= substr($token, 2).' ',
                str_starts_with($token, 'include:') => $meta['include'] .= substr($token, 8).' ',
                str_ends_with($token, 'all') => $meta['policy'] = match ($token[0]) {
                    '~' => 'softfail',
                    '?' => 'neutral',
                    default => 'fail',
                },
                default => null,
            };
        }

        foreach (['ipv4_address', 'ipv6_address', 'hostname', 'include'] as $listField) {
            $meta[$listField] = trim($meta[$listField]);
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseDmarc(string $data): array
    {
        $meta = [
            'policy' => 'none',
            'pct' => 100,
            'rua' => '',
            'ruf' => '',
            'adkim' => 'r',
            'aspf' => 'r',
        ];

        foreach (explode(';', $this->unquote($data)) as $tag) {
            $tag = trim($tag);

            if ($tag === '' || ! str_contains($tag, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $tag, 2));

            match ($name) {
                'p' => $meta['policy'] = $value,
                'pct' => $meta['pct'] = (int) $value,
                'rua' => $meta['rua'] = $value,
                'ruf' => $meta['ruf'] = $value,
                'sp' => $meta['sp'] = $value,
                'adkim' => $meta['adkim'] = $value,
                'aspf' => $meta['aspf'] = $value,
                default => null,
            };
        }

        return $meta;
    }

    /**
     * DKIM TXT rows (`v=DKIM1; t=s; p=<key>`, name
     * `<selector>._domainkey.<domain>.` — legacy dns_dkim_edit.php).
     *
     * @return array<string, mixed>
     */
    protected function parseDkim(string $name, string $data): array
    {
        $meta = [];

        if (preg_match('/^(?<selector>[^.]+)\._domainkey\./', $name, $matches)) {
            $meta['selector'] = $matches['selector'];
        }

        if (preg_match('/(?:^|;)\s*p=(?<key>[^;]*)/', $data, $matches)) {
            $meta['public_key'] = trim($matches['key']);
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseNaptr(int $aux, string $data): array
    {
        // Stored form: <pref> "<flags>" "<service>" "<regexp>" <replacement.>
        if (! preg_match('/^\s*(\d+)\s+"([^"]*)"\s+"([^"]*)"\s+"(.*)"\s+(\S*)\s*$/', $data, $matches)) {
            return [];
        }

        return [
            'order' => $aux,
            'pref' => (int) $matches[1],
            'naptr_flag' => $this->zoneFileUnescape($matches[2]),
            'service' => $this->zoneFileUnescape($matches[3]),
            'regexp' => $this->zoneFileUnescape($matches[4]),
            'replacement' => $matches[5] === '.' ? '' : rtrim($matches[5], '.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseDs(string $data): array
    {
        $parts = preg_split('/\s+/', trim($data), 4);

        if (count($parts) < 4) {
            return [];
        }

        return [
            'key_tag' => (int) $parts[0],
            'algorithm' => (int) $parts[1],
            'digest_type' => (int) $parts[2],
            'digest' => $parts[3],
        ];
    }

    // ------------------------------------------------------------------
    // String utilities (legacy dns_edit_base.php helpers)
    // ------------------------------------------------------------------

    /**
     * Append a trailing dot to a non-empty hostname (legacy convention for
     * MX/SRV/NAPTR targets).
     */
    protected function dotTerminate(string $hostname): string
    {
        if ($hostname === '' || str_ends_with($hostname, '.')) {
            return $hostname;
        }

        return $hostname.'.';
    }

    /**
     * Wrap a value in double quotes unless it is already fully quoted.
     */
    protected function quote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return $value;
        }

        return '"'.$value.'"';
    }

    /**
     * Strip one pair of wrapping double quotes.
     */
    protected function unquote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Legacy dns_edit_base.php::onSubmit "Remove accidental quotes around a
     * record": strip the wrapping pair only when the data contains exactly
     * two double quotes. Public because the TXT no-quote/CR-LF rule
     * (spec 013 FR-008) validates the post-strip value.
     */
    public function stripAccidentalQuotes(string $data): string
    {
        if (substr_count($data, '"') === 2 && preg_match('/^"(.*)"$/', $data, $matches)) {
            return $matches[1];
        }

        return $data;
    }

    /**
     * Legacy dns_edit_base.php::zoneFileEscape.
     */
    protected function zoneFileEscape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Inverse of zoneFileEscape for read decomposition.
     */
    protected function zoneFileUnescape(string $value): string
    {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
    }

    /**
     * Split a space/comma separated list field into trimmed tokens.
     *
     * @return array<int, string>
     */
    protected function splitList(string $value): array
    {
        return preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
