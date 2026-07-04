<?php

namespace App\Http\Requests;

/**
 * POST /servers/{id}/php-versions (api/modules/server/php-versions.yaml).
 *
 * Mirrors legacy server_php.tform.php: name NOTEMPTY (+ STRIPTAGS/STRIPNL
 * save filters), php_cli_binary NOTEMPTY + /^\/[a-zA-Z0-9\/\-\_\.\s]*$/
 * (absolute path), php_jk_section NOTEMPTY + /^[a-zA-Z0-9\-\_]*$/, path
 * fields optional (max 255, tags/newlines stripped). The non-mirrored
 * web-server precondition (422) is enforced in ServerPhpController.
 */
class StoreServerPhpRequest extends ServerModuleRequest
{
    /**
     * Fields carrying the legacy STRIPTAGS/STRIPNL save filters (shared
     * with UpdateServerPhpRequest).
     */
    public const STRIPPED_FIELDS = [
        'name',
        'php_fastcgi_binary',
        'php_fastcgi_ini_dir',
        'php_fpm_init_script',
        'php_fpm_ini_dir',
        'php_fpm_pool_dir',
        'php_fpm_socket_dir',
    ];

    protected function prepareForValidation(): void
    {
        $this->normalizeYesNoFlags(['active']);
        $this->stripTagsAndNewlines(static::STRIPPED_FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'filled', 'max:255'],
            'php_fastcgi_binary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fastcgi_ini_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_init_script' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_ini_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_pool_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_fpm_socket_dir' => ['sometimes', 'nullable', 'string', 'max:255'],
            'php_cli_binary' => ['required', 'string', 'filled', 'max:255', 'regex:/^\/[a-zA-Z0-9\/\-\_\.\s]*$/'],
            'php_jk_section' => ['required', 'string', 'filled', 'max:255', 'regex:/^[a-zA-Z0-9\-\_]*$/'],
            'active' => ['sometimes', 'boolean'],
            'sortprio' => ['sometimes', 'integer'],
            'client_id' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
