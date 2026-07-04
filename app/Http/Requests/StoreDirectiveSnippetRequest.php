<?php

namespace App\Http\Requests;

use App\Models\DirectiveSnippet;
use Illuminate\Validation\Rule;

/**
 * POST /system/directive-snippets (api/modules/system/directive-snippets.yaml).
 */
class StoreDirectiveSnippetRequest extends DirectiveSnippetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(DirectiveSnippet::TYPES)],
            'snippet' => ['sometimes', 'nullable', 'string'],
            'customer_viewable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'update_sites' => ['sometimes', 'boolean'],
            'required_php_snippets' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\s*\d+\s*(,\s*\d+\s*)*$|^$/', $this->requiredPhpSnippetsRule()],
        ];
    }
}
