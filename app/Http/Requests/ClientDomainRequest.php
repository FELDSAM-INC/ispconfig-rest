<?php

namespace App\Http\Requests;

use App\Support\IspContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared behavior for domain-module writes (contract:
 * api/components/schemas/ClientDomain.yaml; legacy:
 * source_code/interface/web/client/form/domain.tform.php):
 *
 *  - the domain is IDN-encoded (IDNTOASCII) and lowercased (TOLOWER) before
 *    validation, then checked against the legacy regex — fixing spec 001
 *    gap G15 (the old implementation skipped all three);
 *  - duplicate domain names are answered with 409 in the controller (the
 *    contract reserves 409 for the real `domain` UNIQUE key).
 */
abstract class ClientDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain') && is_string($this->input('domain'))) {
            $domain = trim($this->input('domain'));

            // Legacy IDNTOASCII filter.
            if ($domain !== '' && function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($domain);

                if ($ascii !== false) {
                    $domain = $ascii;
                }
            }

            // Legacy TOLOWER filter (also applied in domain_edit.php).
            $this->merge(['domain' => strtolower($domain)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    protected function owningClientRule(): Exists
    {
        $rule = Rule::exists('client', 'client_id');
        $scope = app(IspContext::class)->authScope();

        if (! $scope->isAdmin) {
            $rule->where(fn ($query) => $query->whereIn('client_id', fn ($groups) => $groups
                ->select('client_id')->from('sys_group')->whereIn('groupid', $scope->groupIds)));
        }

        return $rule;
    }

    /**
     * Legacy domain regex from domain.tform.php.
     *
     * @return array<int, mixed>
     */
    protected function domainRules(): array
    {
        return [
            'string',
            'max:255',
            'regex:/^[\w\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}$/',
        ];
    }
}
