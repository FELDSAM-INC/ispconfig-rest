<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /clients/{client_id}/templates
 * (api/modules/client/template_assignments.yaml): the body carries only
 * client_template_id; an unknown template is 422 (exists rule), a duplicate
 * assignment 409 (service).
 */
class StoreClientTemplateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_template_id' => [
                'required',
                'integer',
                Rule::exists('client_template', 'template_id'),
            ],
        ];
    }
}
