<?php

namespace App\Http\Requests;

use App\Models\DnsRecord;
use App\Services\DnsRecordMetaService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Shared behavior for DNS record writes. Validation mirrors the legacy
 * per-type forms (source_code/interface/web/dns/form/dns_<type>.tform.php)
 * and dns_edit_base.php::onSubmit():
 *
 *  - `type` is upper-cased; the accepted set is the contract enum — the
 *    dns_rr.type DB enum plus the friendly types SPF/DKIM/DMARC that are
 *    stored as TXT rows;
 *  - a `name` of '@' is replaced with the zone origin and '*' with
 *    '*.<origin>' before validation (legacy onSubmit);
 *  - per-type name/data regexes are taken verbatim from the tform files;
 *  - structured types validate their top-level meta fields instead of
 *    `data` (aux/data are composed server-side by DnsRecordMetaService).
 */
abstract class DnsRecordRequest extends FormRequest
{
    /**
     * The contract type enum (DnsRecord.yaml): DB enum + friendly types.
     *
     * @var array<int, string>
     */
    public const API_TYPES = [
        'A', 'AAAA', 'ALIAS', 'CNAME', 'DNAME', 'CAA', 'DS', 'HINFO', 'LOC', 'MX', 'NAPTR',
        'NS', 'PTR', 'RP', 'SRV', 'SSHFP', 'TXT', 'TLSA', 'DNSKEY', 'SPF', 'DKIM', 'DMARC',
    ];

    /**
     * Legacy per-type `name` regexes (tform files, verbatim).
     *
     * @var array<string, string>
     */
    protected const NAME_PATTERNS = [
        'A' => '/^[a-zA-Z0-9\.\-\*]{0,64}$/',
        'AAAA' => '/^[a-zA-Z0-9\.\-\*]{0,64}$/',
        'ALIAS' => '/^[a-zA-Z0-9\.\-\_]{1,255}$/',
        'CNAME' => '/^[a-zA-Z0-9\.\-\*\_]{0,255}$/',
        'DNAME' => '/^[a-zA-Z0-9\.\-\*\_]{0,255}$/',
        'CAA' => '/^[a-zA-Z0-9\.\-\_\*]{0,255}$/',
        'DS' => '/^[a-zA-Z0-9\.\-\_]{0,255}$/',
        'HINFO' => '/^[a-zA-Z0-9\.\-]{1,64}$/',
        'LOC' => '/^[a-zA-Z0-9\.\-\_]{0,255}$/',
        'MX' => '/^[a-zA-Z0-9\.\-\*]{0,255}$/',
        'NAPTR' => '/^((\*|[a-zA-Z0-9\-_]{1,255})(\.[a-zA-Z0-9\-_]{1,255})*\.?)?$/',
        'NS' => '/^[_a-zA-Z0-9\.\-]{0,255}$/',
        'PTR' => '/^[a-zA-Z0-9\.\-]{1,256}$/',
        'RP' => '/^[a-zA-Z0-9\.\-]{0,255}$/',
        'SRV' => '/^[a-zA-Z0-9\.\-_]{0,255}$/',
        'SSHFP' => '/^(\*\.|[a-zA-Z0-9\.\-\_]){0,255}$/',
        'TXT' => '/^(\*\.|[a-zA-Z0-9\.\-\_]){0,255}$/',
        'TLSA' => '/^\_\d{1,5}\.\_(tcp|udp)\.[a-zA-Z0-9\.\-]{1,255}$/',
        'DNSKEY' => '/^[a-zA-Z0-9\.\-\_]{0,255}$/',
        'SPF' => '/^(\*\.|[a-zA-Z0-9\.\-\_]){0,255}$/',
        'DKIM' => '/^[a-zA-Z0-9\.\-\_]{0,255}$/',
        'DMARC' => '/^[a-zA-Z0-9\.\-\_]{0,255}$/',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation.
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->has('type') && is_string($this->input('type'))) {
            $input['type'] = strtoupper(trim($this->input('type')));
        }

        // Legacy onSubmit: '@' -> origin, '*' -> '*.<origin>'.
        if (in_array($this->input('name'), ['@', '*'], true)) {
            $origin = DB::table('dns_soa')->where('id', (int) $this->effectiveZoneId())->value('origin');

            if ($origin !== null) {
                $input['name'] = $this->input('name') === '@' ? $origin : '*.'.$origin;
            }
        }

        foreach (['active', 'allow_mx', 'allow_a'] as $flag) {
            if ($this->has($flag) && is_string($this->input($flag))) {
                $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value === null) {
                    $value = match (strtolower($this->input($flag))) {
                        'y' => true,
                        'n' => false,
                        default => $this->input($flag),
                    };
                }

                $input[$flag] = $value;
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * Validated data for composition/filling.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * The zone the record will belong to after this request (input value,
     * falling back to the bound record's stored zone on update).
     */
    protected function effectiveZoneId(): ?int
    {
        if ($this->filled('zone')) {
            return (int) $this->input('zone');
        }

        $record = $this->currentRecord();

        return $record === null ? null : (int) $record->getRawOriginal()['zone'];
    }

    /**
     * The route-bound record being updated (null on store).
     */
    protected function currentRecord(): ?DnsRecord
    {
        $record = $this->route('dnsRecord');

        return $record instanceof DnsRecord ? $record : null;
    }

    /**
     * The type validation applies for: the request's type, or on update the
     * stored record's effective (classified) type.
     */
    protected function effectiveType(): string
    {
        $type = $this->input('type');

        if (is_string($type) && $type !== '') {
            return strtoupper($type);
        }

        $record = $this->currentRecord();

        if ($record !== null) {
            $attributes = $record->getRawOriginal();

            return app(DnsRecordMetaService::class)->classify(
                (string) ($attributes['type'] ?? 'TXT'),
                (string) ($attributes['data'] ?? '')
            );
        }

        return '';
    }

    /**
     * The full rule set for one record type.
     *
     * @param  bool  $strict  required semantics (store / type change) vs
     *                        'sometimes' partial-update semantics
     * @return array<string, mixed>
     */
    protected function typeRules(string $type, bool $strict): array
    {
        $req = $strict ? 'required' : 'sometimes';

        $rules = match ($type) {
            'A' => ['data' => [$req, 'string', 'ipv4']],
            'AAAA' => ['data' => [$req, 'string', 'ipv6']],
            'ALIAS', 'NS', 'PTR' => ['data' => [$req, 'string', 'regex:/^[a-zA-Z0-9\.\-]{1,256}$/']],
            'CNAME', 'DNAME' => ['data' => [$req, 'string', 'regex:/^[a-zA-Z0-9\.\-\_]{1,255}$/']],
            'TXT' => ['data' => [$req, 'string', 'max:65535', $this->plainTxtRule()]],
            'LOC', 'DNSKEY', 'DKIM' => ['data' => [$req, 'string', 'max:65535']],
            'RP' => ['data' => [$req, 'string', 'regex:/^[\w\.\-\s]{1,128}$/']],
            'MX' => [
                'hostname' => [$req, 'string', 'max:255', 'regex:/^[a-zA-Z0-9\.\-]{1,255}$/'],
                'priority' => ['sometimes', 'integer', 'between:0,65535'],
            ],
            'SRV' => [
                'hostname' => [$req, 'string', 'max:255', 'regex:/^[\w\.\-]{1,64}$/'],
                'priority' => ['sometimes', 'integer', 'between:0,65535'],
                'weight' => [$req, 'integer', 'between:0,65535'],
                'port' => [$req, 'integer', 'between:0,65535'],
            ],
            'TLSA' => [
                'cert_usage' => [$req, 'integer', 'between:0,3'],
                'selector' => [$req, 'integer', 'between:0,1'],
                'matching_type' => [$req, 'integer', 'between:0,2'],
                'hash' => [$req, 'string', 'regex:/^[a-zA-Z0-9]+$/'],
            ],
            'SSHFP' => [
                'algorithm' => [$req, 'integer', 'between:0,4'],
                'hash_type' => [$req, 'integer', 'between:0,2'],
                'hash' => [$req, 'string', 'regex:/^[a-zA-Z0-9]+$/'],
            ],
            'CAA' => [
                'caa_flag' => ['sometimes', 'integer', 'between:0,255'],
                'caa_type' => [$req, 'string', 'in:issue,issuewild,iodef'],
                'ca_issuer' => [
                    $strict ? 'required_unless:caa_type,iodef' : 'sometimes',
                    'nullable', 'string', 'max:255',
                ],
                'additional' => [
                    $strict ? 'required_if:caa_type,iodef' : 'sometimes',
                    'nullable', 'string', 'max:255',
                ],
            ],
            'HINFO' => [
                'cpu' => [$req, 'string', 'max:255'],
                'os' => [$req, 'string', 'max:255'],
            ],
            'SPF' => [
                'allow_mx' => ['sometimes', 'boolean'],
                'allow_a' => ['sometimes', 'boolean'],
                'ipv4_address' => ['sometimes', 'nullable', 'string', $this->spfIpListRule(FILTER_FLAG_IPV4)],
                'ipv6_address' => ['sometimes', 'nullable', 'string', $this->spfIpListRule(FILTER_FLAG_IPV6)],
                'hostname' => ['sometimes', 'nullable', 'string', $this->tokenListRule('/^[a-zA-Z0-9\.\-\*]{1,64}$/', 'hostname')],
                'include' => ['sometimes', 'nullable', 'string', $this->tokenListRule('/^[_a-zA-Z0-9\.\-\*]{1,64}$/', 'domain')],
                'policy' => [$req, 'string', 'in:fail,softfail,neutral'],
            ],
            'DMARC' => [
                'policy' => [$req, 'string', 'in:none,quarantine,reject'],
                'pct' => ['sometimes', 'integer', 'between:0,100'],
                'rua' => ['sometimes', 'nullable', 'string', 'max:255', $this->mailtoListRule()],
                'ruf' => ['sometimes', 'nullable', 'string', 'max:255', $this->mailtoListRule()],
                'sp' => ['sometimes', 'nullable', 'string', 'in:none,quarantine,reject'],
                'adkim' => ['sometimes', 'string', 'in:r,s'],
                'aspf' => ['sometimes', 'string', 'in:r,s'],
            ],
            'NAPTR' => [
                'order' => ['sometimes', 'integer', 'between:0,65535'],
                'pref' => ['sometimes', 'integer', 'between:0,65535'],
                'naptr_flag' => ['sometimes', 'nullable', 'string', 'in:U,S,A,P'],
                'service' => ['sometimes', 'nullable', 'string', 'max:32'],
                'regexp' => [
                    $strict ? 'required_without:replacement' : 'sometimes',
                    'nullable', 'string', 'max:255',
                ],
                'replacement' => [
                    $strict ? 'required_without:regexp' : 'sometimes',
                    'nullable', 'string', 'max:255',
                ],
            ],
            'DS' => [
                'key_tag' => [$req, 'integer', 'between:0,65535'],
                'algorithm' => [$req, 'integer', 'between:0,255'],
                'digest_type' => [$req, 'integer', 'between:0,255'],
                'digest' => [$req, 'string', 'max:255', 'regex:/^[a-zA-Z0-9\s]+$/'],
            ],
            default => ['data' => [$req, 'string', 'max:65535']],
        };

        // Simple types may pass aux through; structured types derive it.
        if (! in_array($type, DnsRecordMetaService::STRUCTURED_TYPES, true)) {
            $rules['aux'] = ['sometimes', 'integer', 'min:0', 'max:4294967295'];
        }

        return $rules;
    }

    /**
     * The per-type name rule (legacy tform regex).
     *
     * @return array<int, mixed>
     */
    protected function nameRules(string $type, bool $required): array
    {
        $rules = $required ? ['required'] : ['sometimes'];
        $rules[] = 'string';
        $rules[] = 'max:255';

        if (isset(static::NAME_PATTERNS[$type])) {
            $rules[] = 'regex:'.static::NAME_PATTERNS[$type];
        }

        return $rules;
    }

    /**
     * Legacy dns_txt.tform.php: plain TXT data must not carry SPF/DKIM/DMARC
     * payloads — those go through their dedicated types.
     */
    protected function plainTxtRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            foreach (['v=DKIM' => 'DKIM', 'v=DMARC1; ' => 'DMARC', 'v=spf' => 'SPF'] as $needle => $friendly) {
                if (str_contains($value, $needle)) {
                    $fail("TXT records must not contain {$friendly} data — use the {$friendly} record type instead.");

                    return;
                }
            }
        };
    }

    /**
     * SPF ip list (legacy dns_spf_edit.php): space-separated addresses of
     * one family, optional /prefix.
     */
    protected function spfIpListRule(int $family): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($family): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            foreach (preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
                $ip = $entry;

                if (str_contains($ip, '/')) {
                    [$ip] = explode('/', $ip, 2);
                }

                if (filter_var($ip, FILTER_VALIDATE_IP, $family) === false) {
                    $fail("The :attribute contains an invalid IP address: {$entry}.");

                    return;
                }
            }
        };
    }

    /**
     * Space-separated token list validated per token (legacy SPF hostname /
     * include checks).
     */
    protected function tokenListRule(string $pattern, string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pattern, $label): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            foreach (preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (! preg_match($pattern, $token)) {
                    $fail("The :attribute contains an invalid {$label}: {$token}.");

                    return;
                }
            }
        };
    }

    /**
     * DMARC report addresses (legacy dns_dmarc_edit.php): space/comma
     * separated email addresses, each optionally mailto:-prefixed.
     */
    protected function mailtoListRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            foreach (preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
                $email = str_starts_with($entry, 'mailto:') ? substr($entry, 7) : $entry;

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $fail("The :attribute contains an invalid email address: {$entry}.");

                    return;
                }
            }
        };
    }
}
