<?php

namespace App\Http\Requests;

use App\Models\DirectiveSnippet;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for directive-snippet writes, mirroring the legacy form
 * (source_code/interface/web/admin/form/directive_snippets.tform.php):
 *
 *  - `name` gets the legacy STRIPTAGS + STRIPNL SAVE filters and must be
 *    non-empty;
 *  - the y/n flags accept booleans as well as legacy 'y'/'n' strings
 *    (constitution: the API speaks booleans, YesNoBoolean stores y/n);
 *  - `required_php_snippets` is a CSV whose IDs must reference active
 *    snippets of type php (legacy CHECKBOXARRAY datasource).
 *
 * The application-level (name, type) uniqueness is a 409, enforced in the
 * controller (contract), not here.
 */
abstract class DirectiveSnippetRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation (legacy SAVE filters).
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->has('name') && is_string($this->input('name'))) {
            $name = strip_tags($this->input('name'));
            $name = str_replace(["\r", "\n"], '', $name);
            $input['name'] = trim($name);
        }

        foreach (['customer_viewable', 'active', 'update_sites'] as $flag) {
            if ($this->has($flag) && is_string($this->input($flag))) {
                $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value === null) {
                    $value = match (strtolower($this->input($flag))) {
                        'y' => true,
                        'n' => false,
                        default => $this->input($flag), // left invalid -> boolean rule fails
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
     * Validated data ready for DirectiveSnippet::fill(): nullable text
     * inputs mapped to the columns' '' defaults.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['snippet', 'required_php_snippets'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        return $data;
    }

    /**
     * Legacy CHECKBOXARRAY datasource: every listed ID must reference an
     * active snippet of type php.
     */
    protected function requiredPhpSnippetsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $ids = array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''))));

            $valid = DirectiveSnippet::query()
                ->whereIn('directive_snippets_id', $ids)
                ->where('type', 'php')
                ->where('active', 'y')
                ->count();

            if ($valid !== count($ids)) {
                $fail('The :attribute may only reference IDs of active snippets of type php.');
            }
        };
    }
}
