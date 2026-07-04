<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /dns/records (api/modules/dns/records.yaml).
 *
 * The referenced zone must exist (422 via the exists rule); type-specific
 * meta fields are required per the effective record type.
 */
class StoreDnsRecordRequest extends DnsRecordRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->effectiveType();

        $rules = [
            'zone' => ['required', 'integer', Rule::exists('dns_soa', 'id')],
            'type' => ['required', 'string', Rule::in(self::API_TYPES)],
            'name' => $this->nameRules($type, required: true),
            'ttl' => ['sometimes', 'integer', 'min:60', 'max:4294967295'],
            'active' => ['sometimes', 'boolean'],
            // Defaults to the parent zone's group when omitted (contract).
            'sys_groupid' => ['sometimes', 'integer', 'min:1'],
        ];

        if (in_array($type, self::API_TYPES, true)) {
            $rules = array_merge($rules, $this->typeRules($type, strict: true));
        }

        return $rules;
    }
}
