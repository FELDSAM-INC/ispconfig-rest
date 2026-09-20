<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['import', 'export', 'copy'])],
            'database_name' => ['required_if:action,copy', 'prohibited_unless:action,copy', 'string', 'regex:/^[a-zA-Z0-9_]{2,64}$/'],
            'dump_base64' => ['required_if:action,import', 'prohibited_unless:action,import', 'string', 'max:11184812'],
            'confirm' => ['required_if:action,import', 'prohibited_unless:action,import', 'accepted_if:action,import'],
        ];
    }
}
