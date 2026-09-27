<?php

namespace App\Http\Requests;

use App\Models\WebDomain;
use App\Support\IspContext;
use App\Support\WordPressPolicy;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreWordPressJobRequest extends SitesRequest
{
    public function authorize(): bool
    {
        $site = $this->route('webDomain');

        return $site instanceof WebDomain && app(IspContext::class)->authScope()->allows($site->getAttributes(), 'u');
    }

    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['rescan', 'check', 'secure', 'revert'])],
            'installation' => ['required_unless:action,rescan', 'string', 'regex:/\A[a-f0-9]{32}\z/D'],
            'measures' => ['required_if:action,secure,revert', 'array', 'min:1', 'max:19'],
            'measures.*' => ['string', 'distinct', Rule::in([...WordPressPolicy::SERVER, ...WordPressPolicy::LOCAL])],
            'confirmed' => ['sometimes', 'boolean'], 'backup' => ['sometimes', 'boolean'],
            'admin_login' => ['sometimes', 'string', 'regex:/\A[A-Za-z][A-Za-z0-9_.-]{2,59}\z/D']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (array_diff(array_keys($this->all()), ['action', 'installation', 'measures', 'confirmed', 'backup', 'admin_login'])) {
                $v->errors()->add('action', 'Unknown WordPress parameters.');
            }
            $measures = $this->input('measures');
            if (! is_array($measures)) {
                return;
            }
            $oneWay = array_intersect(array_filter($measures, 'is_string'), WordPressPolicy::ONE_WAY);
            if ($this->input('action') === 'revert' && $oneWay) {
                $v->errors()->add('measures', 'These measures cannot be reverted.');
            }
            if ($this->input('action') !== 'secure') {
                return;
            }
            if ($oneWay && ! $this->boolean('confirmed')) {
                $v->errors()->add('confirmed', 'Explicit confirmation is required.');
            }
            if (array_intersect($oneWay, ['prefix', 'admin_login']) && ! $this->boolean('backup')) {
                $v->errors()->add('backup', 'A database backup is required.');
            }
            if (in_array('admin_login', $measures, true) && (! $this->filled('admin_login') || strtolower((string) $this->input('admin_login')) === 'admin')) {
                $v->errors()->add('admin_login', 'Choose a new administrator login.');
            }
        });
    }
}
