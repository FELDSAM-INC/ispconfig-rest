<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDnsRdata;
use App\Models\DnsRecord;
use App\Services\DnsRecordMetaService;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Shared behavior for DNS record writes. Validation mirrors the legacy
 * per-type forms (source_code/interface/web/dns/form/dns_<type>.tform.php)
 * and dns_edit_base.php::onSubmit():
 *
 *  - `type` is upper-cased; the accepted set is the contract enum — the
 *    dns_rr.type DB enum plus the friendly types SPF/DKIM/DMARC that are
 *    stored as TXT rows;
 *  - a `name` of '@' is replaced with the zone origin and '*' with
 *    '*.<origin>' before validation (legacy onSubmit); CNAME `data` of '@'
 *    is replaced with the origin (legacy dns_cname_edit.php:67-70);
 *  - per-type name/data regexes are taken verbatim from the tform files;
 *  - structured types validate their top-level meta fields instead of
 *    `data` (aux/data are composed server-side by DnsRecordMetaService);
 *  - the P1 BIND-safety rules (spec 013 — DS/TLSA/SSHFP hex lengths, DNSKEY
 *    structure, NAPTR regexp grammar, quote/CR-LF bans, LOC grammar) come
 *    from the ValidatesDnsRdata trait and attach per submitted field, so
 *    partial updates never re-validate untouched stored values (FR-012);
 *  - zone-level checks (CNAME conflict/apex/target, A/AAAA/ALIAS/CAA
 *    duplicates, DMARC prerequisites — legacy dns_edit_base.php
 *    checkDuplicate and per-type overrides) live in zoneLevelChecks() and
 *    are wired through after() by the Store/Update subclasses.
 */
abstract class DnsRecordRequest extends FormRequest
{
    use ValidatesDnsRdata;

    /**
     * Cached decomposed meta of the route-bound record (partial-update
     * sibling resolution: submitted value, else stored decomposed meta).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $storedMeta = null;

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

        // Legacy dns_cname_edit.php:67-70: CNAME data '@' -> zone origin.
        if ($this->input('data') === '@' && $this->effectiveType() === 'CNAME') {
            $origin = DB::table('dns_soa')->where('id', (int) $this->effectiveZoneId())->value('origin');

            if ($origin !== null) {
                $input['data'] = $origin;
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
            // FR-008: the BIND template wraps TXT data in literal quotes
            // (bind_pri.domain.master:63) — validate the post-strip value
            // (compose strips one accidental wrapping pair, legacy parity).
            'TXT' => ['data' => [$req, 'string', 'max:65535', $this->plainTxtRule(),
                $this->noZoneBreakingCharsRule(normalize: fn (string $value): string => app(DnsRecordMetaService::class)->stripAccidentalQuotes($value)),
            ]],
            // FR-008: DKIM data is stored verbatim (compose() does not
            // strip), so quotes are rejected outright.
            'DKIM' => ['data' => [$req, 'string', 'max:65535', $this->noZoneBreakingCharsRule()]],
            // FR-010: BIND loc_29.c refuses malformed LOC (legacy validator
            // is commented out — dns_loc.tform.php:113-118).
            'LOC' => ['data' => [$req, 'string', 'max:255', $this->locRule()]],
            // FR-005: no legacy form exists for DNSKEY; structure + base64
            // per RFC 4034 §2.
            'DNSKEY' => ['data' => [$req, 'string', 'max:65535', $this->dnskeyRule()]],
            'RP' => ['data' => [$req, 'string', 'regex:/^[\w\.\-\s]{1,128}$/']],
            'MX' => [
                'hostname' => [$req, 'string', 'max:255', 'regex:/^[a-zA-Z0-9\.\-]{1,255}$/'],
                'priority' => ['sometimes', 'integer', 'between:0,65535'],
            ],
            'SRV' => [
                // FR-018: legacy target regex (dns_srv_edit.php:88) allows
                // up to 255 chars — the earlier 64-char cap was our bug.
                'hostname' => [$req, 'string', 'max:255', 'regex:/^[a-zA-Z0-9\.\-\_]{1,255}$/'],
                'priority' => ['sometimes', 'integer', 'between:0,65535'],
                'weight' => [$req, 'integer', 'between:0,65535'],
                'port' => [$req, 'integer', 'between:0,65535'],
            ],
            // FR-003: hash must be hex; matching_type 1 => 64, 2 => 128
            // chars (RFC 6698 §2.1.3), 0 (exact match) => even-length hex.
            // Legacy /^\d \d \d [a-zA-Z0-9]*$/ accepted 'somehashstring'
            // (incident 2026-07-05).
            'TLSA' => [
                'cert_usage' => [$req, 'integer', 'between:0,3'],
                'selector' => [$req, 'integer', 'between:0,1'],
                'matching_type' => [$req, 'integer', 'between:0,2',
                    $this->siblingHashLengthRule('hash', [1 => 64, 2 => 128], 'RFC 6698 §2.1.3'),
                ],
                'hash' => [$req, 'string', $this->hexRule(
                    fn (): ?int => [1 => 64, 2 => 128][(int) $this->effectiveMetaValue('matching_type')] ?? null
                )],
            ],
            // FR-004: hash must be hex; hash_type 1 => 40, 2 => 64 chars
            // (RFC 4255 §3.1.2, RFC 6594 §4). hash_type restricted to 1/2
            // (owner decision NC-1 — type 0 has no deterministic length).
            // Legacy is NOTEMPTY-only ('fingerprinthash' incident).
            'SSHFP' => [
                'algorithm' => [$req, 'integer', 'between:0,4'],
                'hash_type' => [$req, 'integer', 'in:1,2',
                    $this->siblingHashLengthRule('hash', [1 => 40, 2 => 64], 'RFC 4255 §3.1.2 / RFC 6594 §4'),
                ],
                'hash' => [$req, 'string', $this->hexRule(
                    fn (): ?int => [1 => 40, 2 => 64][(int) $this->effectiveMetaValue('hash_type')] ?? null
                )],
            ],
            // FR-009: CAA values are quoted verbatim by quote() and emitted
            // raw by the zone template — quotes/backslashes/newlines break
            // the zone; issuer/iodef shapes per RFC 8659 §4.5-4.7.
            'CAA' => [
                'caa_flag' => ['sometimes', 'integer', 'between:0,255'],
                'caa_type' => [$req, 'string', 'in:issue,issuewild,iodef'],
                'ca_issuer' => [
                    $strict ? 'required_unless:caa_type,iodef' : 'sometimes',
                    'nullable', 'string', 'max:255',
                    $this->noZoneBreakingCharsRule(banBackslash: true),
                    $this->caaIssuerShapeRule(),
                ],
                'additional' => [
                    $strict ? 'required_if:caa_type,iodef' : 'sometimes',
                    'nullable', 'string', 'max:255',
                    $this->noZoneBreakingCharsRule(banBackslash: true),
                    $this->caaIodefUrlRule(),
                ],
            ],
            // FR-009: HINFO cpu/os share the verbatim-quoting hazard.
            'HINFO' => [
                'cpu' => [$req, 'string', 'max:255', $this->noZoneBreakingCharsRule(banBackslash: true)],
                'os' => [$req, 'string', 'max:255', $this->noZoneBreakingCharsRule(banBackslash: true)],
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
            // FR-006/FR-007: regexp must be a BIND-valid delimited
            // substitution expression ('sip:info@example.com' incident);
            // regexp XOR replacement, flag-dependent forms (RFC 3403 §4.1);
            // service restricted to the RFC 3403 grammar charset.
            'NAPTR' => [
                'order' => ['sometimes', 'integer', 'between:0,65535'],
                'pref' => ['sometimes', 'integer', 'between:0,65535'],
                'naptr_flag' => ['sometimes', 'nullable', 'string', 'in:U,S,A,P', $this->naptrSemanticsRule()],
                'service' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-zA-Z0-9+\-.:]*$/'],
                'regexp' => [
                    $strict ? 'required_without:replacement' : 'sometimes',
                    'nullable', 'string', 'max:255',
                    $this->naptrRegexpRule(),
                    $this->naptrSemanticsRule(),
                ],
                'replacement' => [
                    $strict ? 'required_without:regexp' : 'sometimes',
                    'nullable', 'string', 'max:255',
                    $this->naptrSemanticsRule(),
                ],
            ],
            // FR-002: digest must be hex; digest_type 1 => 40, 2 => 64,
            // 4 => 96 chars (RFC 4034 §5.1.4, RFC 4509, RFC 6605).
            // digest_type restricted to 1/2/4 (owner decision NC-1; BIND
            // >= 9.16 rejects GOST). Legacy regex accepted any tail
            // (dns_ds.tform.php:105 TODO — base64-digest incident).
            'DS' => [
                'key_tag' => [$req, 'integer', 'between:0,65535'],
                'algorithm' => [$req, 'integer', 'between:0,255'],
                'digest_type' => [$req, 'integer', 'in:1,2,4',
                    $this->siblingHashLengthRule('digest', [1 => 40, 2 => 64, 4 => 96], 'RFC 4034 §5.1.4 / RFC 4509 / RFC 6605'),
                ],
                'digest' => [$req, 'string', 'max:255', $this->hexRule(
                    fn (): ?int => [1 => 40, 2 => 64, 4 => 96][(int) $this->effectiveMetaValue('digest_type')] ?? null
                )],
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
     * The effective value of a type-specific meta field for cross-field
     * rules on partial updates: the submitted value when present, else the
     * stored record's decomposed meta (submitting only `digest_type=2`
     * against a stored 40-char digest must 422 — spec 013 plan).
     */
    protected function effectiveMetaValue(string $field): mixed
    {
        if ($this->has($field)) {
            return $this->input($field);
        }

        if ($this->storedMeta === null) {
            $record = $this->currentRecord();

            $this->storedMeta = $record === null
                ? []
                : app(DnsRecordMetaService::class)->meta($record->getRawOriginal());
        }

        return $this->storedMeta[$field] ?? null;
    }

    /**
     * Cross-field companion to hexRule(): submitting only the *type* field
     * (digest_type/matching_type/hash_type) must still be checked against
     * the effective hash length. Skipped when the hash field is submitted
     * too — its own hexRule() then reports the mismatch.
     *
     * @param  array<int, int>  $lengthByType
     */
    protected function siblingHashLengthRule(string $hashField, array $lengthByType, string $citation): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hashField, $lengthByType, $citation): void {
            $expected = $lengthByType[(int) $value] ?? null;

            if ($expected === null || $this->has($hashField)) {
                return;
            }

            $hash = $this->effectiveMetaValue($hashField);

            if (! is_string($hash) || $hash === '') {
                return;
            }

            if (strlen($hash) !== $expected) {
                $fail("The {$attribute} value {$value} requires the {$hashField} to be exactly {$expected} hexadecimal characters ({$citation}).");
            }
        };
    }

    /**
     * NAPTR cross-field semantics (RFC 3403 §4.1, RFC 2915 §2): regexp and
     * replacement are mutually exclusive; flag U requires a regexp (and no
     * replacement), flags S/A require a replacement (and no regexp); flag P
     * or none allows either form. Attached to each involved field so the
     * error always names a submitted field (FR-012 tolerance: the closure
     * only runs for fields present in the request).
     */
    protected function naptrSemanticsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $flag = strtoupper((string) $this->effectiveMetaValue('naptr_flag'));
            $regexp = (string) $this->effectiveMetaValue('regexp');
            $replacement = (string) $this->effectiveMetaValue('replacement');

            if ($regexp !== '' && $replacement !== '') {
                $fail("The {$attribute} is invalid: a NAPTR record must not carry both a regexp and a replacement (RFC 3403 §4.1).");

                return;
            }

            if ($flag === 'U' && $regexp === '') {
                $fail("The {$attribute} is inconsistent: NAPTR flag U requires a non-empty regexp and an empty replacement (RFC 3403 §4.1).");

                return;
            }

            if (($flag === 'S' || $flag === 'A') && ($regexp !== '' || $replacement === '')) {
                $fail("The {$attribute} is inconsistent: NAPTR flag {$flag} requires an empty regexp and a non-empty replacement (RFC 3403 §4.1).");
            }
        };
    }

    /**
     * CAA iodef contact: a mailto:, http:// or https:// URL (RFC 8659
     * §4.7). Only enforced when the effective caa_type is iodef — for
     * issue/issuewild the `additional` value is not composed into data.
     */
    protected function caaIodefUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if ($this->effectiveMetaValue('caa_type') !== 'iodef') {
                return;
            }

            if (str_starts_with($value, 'mailto:')) {
                if (filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) === false) {
                    $fail("The {$attribute} must be a valid mailto: address for a CAA iodef record (RFC 8659 §4.7).");
                }

                return;
            }

            if (! preg_match('#^https?://#', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                $fail("The {$attribute} must be a mailto:, http:// or https:// URL for a CAA iodef record (RFC 8659 §4.7).");
            }
        };
    }

    // ------------------------------------------------------------------
    // Zone-level checks (spec 013 US3 — legacy dns_edit_base.php
    // checkDuplicate() and the per-type overrides, ported verbatim:
    // zone-scoped, self-excluded, NO active predicate)
    // ------------------------------------------------------------------

    /**
     * Dispatch the per-type zone-level checks. Wired through after() by
     * StoreDnsRecordRequest (always) and UpdateDnsRecordRequest (only when
     * name/type/zone/data-affecting fields are submitted — FR-012).
     */
    protected function zoneLevelChecks(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $type = $this->effectiveType();

        if (! in_array($type, self::API_TYPES, true)) {
            return;
        }

        $zoneId = $this->effectiveZoneId();

        if ($zoneId === null) {
            return;
        }

        $origin = DB::table('dns_soa')->where('id', $zoneId)->value('origin');

        if ($origin === null) {
            return;
        }

        $origin = (string) $origin;
        $name = $this->effectiveName();
        $excludeId = (int) ($this->currentRecord()?->getKey() ?? 0);

        match ($type) {
            'CNAME' => $this->checkCname($validator, $zoneId, $origin, $name, $excludeId),
            // dns_a_edit.php:48-53 / dns_aaaa_edit.php:48 override the base
            // check: identical record (name+data) or CNAME/ALIAS at the
            // exact name.
            'A', 'AAAA' => $this->checkAddressDuplicate($validator, $type, $zoneId, $name, $excludeId),
            // dns_alias_edit.php:47 / dns_dname_edit.php:48 overrides.
            'ALIAS' => $this->checkNameDuplicate($validator, ['A', 'AAAA', 'CNAME', 'DNAME', 'ALIAS'], $zoneId, $name, $excludeId),
            'DNAME' => $this->checkNameDuplicate($validator, ['CNAME', 'DNAME', 'ALIAS'], $zoneId, $name, $excludeId),
            'CAA' => $this->checkCaa($validator, $zoneId, $origin, $name, $excludeId),
            'DMARC' => $this->checkDmarc($validator, $zoneId, $origin, $excludeId),
            default => $this->checkCnameConflict($validator, $zoneId, $origin, $name, $excludeId),
        };
    }

    /**
     * The effective post-normalization record name (submitted, else stored).
     */
    protected function effectiveName(): string
    {
        if ($this->has('name')) {
            return (string) $this->input('name');
        }

        $record = $this->currentRecord();

        return $record === null ? '' : (string) ($record->getRawOriginal()['name'] ?? '');
    }

    /**
     * The effective `data` value (submitted, else stored).
     */
    protected function effectiveData(): string
    {
        if ($this->has('data')) {
            return (string) $this->input('data');
        }

        $record = $this->currentRecord();

        return $record === null ? '' : (string) ($record->getRawOriginal()['data'] ?? '');
    }

    /**
     * dns_rr rows at the same node using legacy's three name spellings
     * (dns_edit_base.php:46: as-sent, '.<origin>' stripped, '.<origin>'
     * appended), zone-scoped, excluding the record itself, ignoring
     * `active` (an inactive CNAME still blocks — legacy parity).
     */
    protected function recordsAtName(int $zoneId, string $origin, string $name, int $excludeId): Builder
    {
        $spellings = array_unique([
            $name,
            str_replace('.'.$origin, '', $name),
            $name.'.'.$origin,
        ]);

        return DB::table('dns_rr')
            ->where('zone', $zoneId)
            ->whereIn('name', $spellings)
            ->where('id', '!=', $excludeId);
    }

    /**
     * FR-014 (base direction, dns_edit_base.php:43-49): a CNAME at the same
     * node blocks every other record type (RFC 1034).
     */
    protected function checkCnameConflict(Validator $validator, int $zoneId, string $origin, string $name, int $excludeId): void
    {
        $conflict = $this->recordsAtName($zoneId, $origin, $name, $excludeId)
            ->where('type', 'CNAME')
            ->exists();

        if ($conflict) {
            $validator->errors()->add('name', 'A CNAME record already exists for this name in the zone — no other data is allowed at a CNAME node (RFC 1034).');
        }
    }

    /**
     * FR-014..FR-016 (dns_cname_edit.php): apex prohibition (:61-65, RFC
     * 1912), no coexistence with any record (:48-54), relative target must
     * exist in the zone (:72-84).
     */
    protected function checkCname(Validator $validator, int $zoneId, string $origin, string $name, int $excludeId): void
    {
        // Apex check runs on the post-normalization name ('@' was already
        // rewritten to the origin in prepareForValidation).
        $hostname = trim($name);

        if ($hostname === '' || $hostname === '@' || $hostname === $origin || $hostname === rtrim($origin, '.')) {
            $validator->errors()->add('name', 'A CNAME record cannot be placed at the zone apex (RFC 1912) — use an A/AAAA or ALIAS record instead.');
        }

        if ($this->recordsAtName($zoneId, $origin, $name, $excludeId)->exists()) {
            $validator->errors()->add('name', 'Another record already exists for this name in the zone — a CNAME cannot coexist with any other record (RFC 1034).');
        }

        // Relative targets (no trailing dot) must name an existing record.
        $target = $this->effectiveData();

        if ($target !== '' && ! str_ends_with($target, '.')) {
            $exists = DB::table('dns_rr')
                ->where('zone', $zoneId)
                ->where(fn ($query) => $query->where('name', $target)->orWhere('name', $target.'.'.$origin))
                ->exists();

            if (! $exists) {
                $validator->errors()->add('data', 'The CNAME target does not exist in this zone — point to an existing record or use a fully qualified name ending with a dot.');
            }
        }
    }

    /**
     * FR-017 (dns_a_edit.php:48-53 / dns_aaaa_edit.php:48): an identical
     * record (same zone+name+data) or a CNAME/ALIAS at the exact name.
     */
    protected function checkAddressDuplicate(Validator $validator, string $type, int $zoneId, string $name, int $excludeId): void
    {
        $data = $this->effectiveData();

        $duplicate = DB::table('dns_rr')
            ->where('zone', $zoneId)
            ->where('id', '!=', $excludeId)
            ->where(function ($query) use ($type, $name, $data): void {
                $query->where(fn ($q) => $q->where('type', $type)->where('name', $name)->where('data', $data))
                    ->orWhere(fn ($q) => $q->where('type', 'CNAME')->where('name', $name))
                    ->orWhere(fn ($q) => $q->where('type', 'ALIAS')->where('name', $name));
            })
            ->exists();

        if ($duplicate) {
            $validator->errors()->add('name', "An identical {$type} record or a CNAME/ALIAS record already exists for this name in the zone.");
        }
    }

    /**
     * FR-017 (dns_alias_edit.php:47 / dns_dname_edit.php:48): any record of
     * the conflicting types at the exact name.
     *
     * @param  array<int, string>  $types
     */
    protected function checkNameDuplicate(Validator $validator, array $types, int $zoneId, string $name, int $excludeId): void
    {
        $duplicate = DB::table('dns_rr')
            ->where('zone', $zoneId)
            ->where('id', '!=', $excludeId)
            ->whereIn('type', $types)
            ->where('name', $name)
            ->exists();

        if ($duplicate) {
            $validator->errors()->add('name', 'A conflicting record ('.implode('/', $types).') already exists for this name in the zone.');
        }
    }

    /**
     * CAA: the base CNAME check plus the legacy duplicate check
     * (dns_caa_edit.php:176-177, adopted per owner decision NC-2): an
     * identical CAA (same name + composed data). Ported zone-scoped and
     * self-excluded — the API allows free-form CAA names (spec 002
     * deviation), so a global name match could cross zones; legacy's
     * `active` comparison is dropped (it compares against an undefined
     * variable and never matches — an evident bug, not behavior to mirror).
     */
    protected function checkCaa(Validator $validator, int $zoneId, string $origin, string $name, int $excludeId): void
    {
        $this->checkCnameConflict($validator, $zoneId, $origin, $name, $excludeId);

        $service = app(DnsRecordMetaService::class);
        $record = $this->currentRecord();

        $input = array_merge(
            $record === null ? [] : $service->meta($record->getRawOriginal()),
            array_intersect_key($this->all(), array_flip(DnsRecordMetaService::metaFieldsFor('CAA')))
        );

        $data = $service->compose($input, 'CAA', ['origin' => $origin])['data'];

        $duplicate = DB::table('dns_rr')
            ->where('zone', $zoneId)
            ->where('id', '!=', $excludeId)
            ->where('type', 'CAA')
            ->where('name', $name)
            ->where('data', $data)
            ->exists();

        if ($duplicate) {
            $validator->errors()->add('name', 'An identical CAA record (same name and data) already exists in this zone.');
        }
    }

    /**
     * FR-020 (dns_dmarc_edit.php:229-251): DMARC requires at least one
     * active DKIM record (TXT v=DKIM% or a CNAME at %._domainkey%) and
     * exactly one active SPF TXT in the zone. The base CNAME check runs
     * against the forced record name (legacy forces name to
     * '_dmarc.<origin>' before the base onSubmit).
     */
    protected function checkDmarc(Validator $validator, int $zoneId, string $origin, int $excludeId): void
    {
        $this->checkCnameConflict($validator, $zoneId, $origin, '_dmarc.'.$origin, $excludeId);

        $hasDkim = DB::table('dns_rr')
            ->where('zone', $zoneId)
            ->where('name', 'like', '%._domainkey%')
            ->where(function ($query): void {
                $query->where(fn ($q) => $q->where('type', 'TXT')->where('data', 'like', 'v=DKIM%'))
                    ->orWhere('type', 'CNAME');
            })
            ->where('active', 'Y')
            ->exists();

        if (! $hasDkim) {
            $validator->errors()->add('zone', 'A DMARC record requires at least one active DKIM record in the zone (a TXT record with v=DKIM… data or a CNAME at <selector>._domainkey).');
        }

        $spfCount = DB::table('dns_rr')
            ->where('zone', $zoneId)
            ->where(fn ($query) => $query->where('name', $origin)->orWhere('name', ''))
            ->where('type', 'TXT')
            ->where('data', 'like', 'v=spf1%')
            ->where('active', 'Y')
            ->count();

        if ($spfCount === 0) {
            $validator->errors()->add('zone', 'A DMARC record requires an active SPF record in the zone.');
        } elseif ($spfCount > 1) {
            $validator->errors()->add('zone', 'The zone has more than one active SPF record — remove the duplicates before creating a DMARC record.');
        }
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
