<?php

namespace App\Http\Requests;

/**
 * PUT /servers/{id}/php-versions/{php_version_id}
 * (api/modules/server/php-versions.yaml).
 *
 * Partial updates with the same rules as create; server_id is taken from
 * the path and cannot be changed (422 on a differing body value).
 */
class UpdateServerPhpRequest extends ServerModuleRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeYesNoFlags(['active']);
        $this->stripTagsAndNewlines(StoreServerPhpRequest::STRIPPED_FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['sometimes', 'integer', $this->serverIdMatchesPathRule()],
            'name' => ['sometimes', 'string', 'filled', 'max:255'],
            'php_fastcgi_binary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fastcgi_ini_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_init_script' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_ini_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_pool_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_socket_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_cli_binary' => ['sometimes', 'string', 'filled', 'max:255', 'regex:/^\/[a-zA-Z0-9\/\-\_\.\s]*$/'],
            'php_jk_section' => ['sometimes', 'string', 'filled', 'max:255', 'regex:/^[a-zA-Z0-9\-\_]*$/'],
            'active' => ['sometimes', 'boolean'],
            'sortprio' => ['sometimes', 'integer'],
            'client_id' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
