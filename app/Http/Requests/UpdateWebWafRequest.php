<?php

namespace App\Http\Requests;

use App\Models\WebDomain;
use App\Support\IspContext;
use App\Support\WebWafPolicy;
use App\Support\WebWafProfiles;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class UpdateWebWafRequest extends SitesRequest
{
    public function authorize(): bool
    {
        $site = $this->route('webDomain');

        return $site instanceof WebDomain && app(IspContext::class)->authScope()->allows($site->getAttributes(), 'u');
    }

    public function rules(): array
    {
        return ['expected_revision' => ['sometimes', 'string', 'regex:/\A[a-f0-9]{64}\z/D'], 'enabled' => ['sometimes', 'boolean'], 'atomic' => ['sometimes', 'boolean'], 'mode' => ['sometimes', Rule::in(['detection', 'enforcing'])],
            'application_profile' => ['sometimes', 'string', Rule::in(['none', ...array_keys(WebWafProfiles::FILES)])],
            'exclusions' => ['sometimes', 'array', 'max:100'], 'ip_allowlist' => ['sometimes', 'array', 'max:100']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                WebWafPolicy::normalize($this->except('expected_revision'));
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('waf', $e->getMessage());
            }
        });
    }
}
