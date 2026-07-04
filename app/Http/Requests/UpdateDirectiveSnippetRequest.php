<?php

namespace App\Http\Requests;

use App\Models\DirectiveSnippet;
use Illuminate\Validation\Rule;

/**
 * PUT /system/directive-snippets/{id}
 * (api/modules/system/directive-snippets.yaml). Keys absent from the request
 * are left unchanged; the in-use 409 guards live in the controller/service.
 */
class UpdateDirectiveSnippetRequest extends DirectiveSnippetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'filled', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(DirectiveSnippet::TYPES)],
            'snippet' => ['sometimes', 'nullable', 'string'],
            'customer_viewable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'update_sites' => ['sometimes', 'boolean'],
            'required_php_snippets' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\s*\d+\s*(,\s*\d+\s*)*$|^$/', $this->requiredPhpSnippetsRule()],
        ];
    }
}
