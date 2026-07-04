<?php

namespace App\Http\Requests;

use App\Models\WebFolder;

/**
 * PUT /sites/web-folders/{id} (api/modules/sites/web-folders.yaml): only
 * `active` is updatable — `path` and `parent_domain_id` are immutable
 * after creation (contract restriction, stricter than legacy). Sending
 * the current values is accepted (idempotent PUTs).
 */
class UpdateWebFolderRequest extends SitesRequest
{
    protected function booleanFields(): array
    {
        return ['active'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'boolean'],
            'path' => [
                'sometimes',
                'string',
                $this->immutableRule(fn () => $this->routeFolderAttribute('path'), 'folder path'),
            ],
            'parent_domain_id' => [
                'sometimes',
                'integer',
                $this->immutableRule(fn () => $this->routeFolderAttribute('parent_domain_id', true), 'parent domain'),
            ],
        ];
    }

    /**
     * Only `active` reaches the model (contract restriction).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        return array_intersect_key($data, array_flip(['active']));
    }

    protected function routeFolderAttribute(string $attribute, bool $asInt = false): mixed
    {
        $folder = $this->route('webFolder');

        if (! $folder instanceof WebFolder) {
            return null;
        }

        $value = $folder->getAttributes()[$attribute] ?? null;

        return $asInt ? (int) $value : $value;
    }
}
