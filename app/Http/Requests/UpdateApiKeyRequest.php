<?php

namespace App\Http\Requests;

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /system/api-keys/{id} (api/modules/system/api-keys.yaml, spec 014).
 *
 * Only the label and the active flag change. The key's binding is
 * immutable: client_id is accepted only when it equals the current binding
 * (null for admin and unbound keys). The same identity and secret fields as
 * on create are rejected with 422.
 */
class UpdateApiKeyRequest extends FormRequest
{
    /**
     * Authentication and the admin gate happen in middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => ['sometimes', 'filled', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
        ], array_fill_keys(StoreApiKeyRequest::PROHIBITED, ['prohibited']));
    }

    /**
     * A sent client_id must equal the key's current binding.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $apiKey = $this->route('apiKey');

                if (! $this->exists('client_id') || $validator->errors()->has('client_id') || ! $apiKey instanceof ApiKey) {
                    return;
                }

                $sent = $this->input('client_id');
                $current = app(ApiKeyService::class)->present([$apiKey])[0]['client_id'];

                if (($sent === null ? null : (int) $sent) !== $current) {
                    $validator->errors()->add('client_id', 'The key binding cannot be changed after creation.');
                }
            },
        ];
    }
}
