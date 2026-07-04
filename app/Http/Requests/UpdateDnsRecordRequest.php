<?php

namespace App\Http\Requests;

use App\Services\DnsRecordMetaService;
use Illuminate\Validation\Rule;

/**
 * PUT /dns/records/{id} (api/modules/dns/records.yaml).
 *
 * Partial updates: fields not sent keep their stored values (structured
 * types are re-composed from the stored record's decomposed meta merged
 * with the request). When the request CHANGES the record type, the new
 * type's full required field set applies — otherwise all per-type rules
 * relax to 'sometimes'.
 */
class UpdateDnsRecordRequest extends DnsRecordRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->effectiveType();

        $rules = [
            'zone' => ['sometimes', 'integer', Rule::exists('dns_soa', 'id')],
            'type' => ['sometimes', 'string', Rule::in(self::API_TYPES)],
            'name' => $this->nameRules($type, required: false),
            'ttl' => ['sometimes', 'integer', 'min:60', 'max:4294967295'],
            'active' => ['sometimes', 'boolean'],
            'sys_groupid' => ['sometimes', 'integer', 'min:1'],
        ];

        if (in_array($type, self::API_TYPES, true)) {
            $rules = array_merge($rules, $this->typeRules($type, strict: $this->isTypeChange()));
        }

        return $rules;
    }

    /**
     * Whether the request switches the record to a different (effective)
     * type — in that case the new type's required fields apply in full.
     */
    protected function isTypeChange(): bool
    {
        $requested = $this->input('type');
        $record = $this->currentRecord();

        if (! is_string($requested) || $requested === '' || $record === null) {
            return false;
        }

        $attributes = $record->getRawOriginal();

        $storedType = app(DnsRecordMetaService::class)->classify(
            (string) ($attributes['type'] ?? 'TXT'),
            (string) ($attributes['data'] ?? '')
        );

        return strtoupper($requested) !== $storedType;
    }
}
