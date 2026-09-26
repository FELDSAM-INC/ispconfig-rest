<?php

namespace App\Services;

use App\Models\WebDomain;
use App\Support\WebRuntimeDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Structured website settings compiled into a bounded, replaceable part of ISPConfig's native directives. */
class WebRuntimeService
{
    private const BEGIN = '# BEGIN ISPCP RUNTIME';

    private const END = '# END ISPCP RUNTIME';

    public function view(WebDomain $site): array
    {
        $settings = $this->settings($site);
        $engine = $site->web_server_type;
        $worker = $this->workerAvailable((int) $site->server_id);

        return $settings + [
            'document_root_available' => in_array($engine, ['apache', 'nginx'], true) && $worker,
            'environment_available' => $engine === 'apache' || ($engine === 'nginx' && $worker),
            'base_folder' => $site->type === 'vhost' ? 'web' : (string) $site->web_folder,
        ];
    }

    /** Actual serving directory for list/info views; no application secrets in this derived field. */
    public function publicRoot(WebDomain $site): ?string
    {
        try {
            $settings = $this->settings($site);
        } catch (ValidationException|ConflictHttpException) {
            return null;
        }
        $folder = $site->type === 'vhost' ? 'web' : (string) $site->web_folder;
        if (empty($site->document_root) || $folder === '' || ! WebRuntimeDirectory::valid($folder)) {
            return null;
        }

        return rtrim($site->document_root, '/').'/'.$folder.($settings['document_root_subdir'] === '' ? '' : '/'.$settings['document_root_subdir']);
    }

    public function workerAvailable(int $server): bool
    {
        return Schema::hasColumn('api_web_log_workers', 'runtime_version')
            && DB::table('api_web_log_workers')->where('server_id', $server)
                ->where('runtime_version', '>=', 1)->where('heartbeat', '>=', time() - 90)->exists();
    }

    /** Runs before the website-write transaction: the worker must see the committed read request. */
    public function preflight(WebDomain $site, array $payload): void
    {
        if (! array_key_exists('runtime_settings', $payload)) {
            return;
        }
        $settings = $this->normalize($payload['runtime_settings']);
        $old = $this->settings($site);
        $capabilities = $this->view($site);
        if (! in_array($site->web_server_type, ['apache', 'nginx'], true)) {
            throw ValidationException::withMessages(['runtime_settings' => 'The website server type is not supported.']);
        }
        if ($settings['environment'] !== $old['environment'] && ! $capabilities['environment_available']) {
            throw ValidationException::withMessages(['runtime_settings.environment' => 'Upgrade the web server reader before changing environment variables.']);
        }
        if ($settings['document_root_subdir'] !== $old['document_root_subdir']) {
            if (! $capabilities['document_root_available']) {
                throw ValidationException::withMessages(['runtime_settings.document_root_subdir' => 'The web server directory checker is unavailable.']);
            }
            $this->checkDirectory($site, $settings['document_root_subdir']);
        }
    }

    public function checkDirectory(WebDomain $site, string $path): void
    {
        $id = bin2hex(random_bytes(32));
        DB::table('api_web_log_reads')->insert([
            'id' => $id, 'server_id' => $site->server_id, 'website_id' => $site->getKey(), 'sys_groupid' => $site->sys_groupid,
            'domain' => $site->domain, 'request' => json_encode(['kind' => 'document_root', 'subdirectory' => $path]), 'created_at' => time(),
        ]);
        $deadline = microtime(true) + 12;
        try {
            do {
                $raw = DB::table('api_web_log_reads')->where('id', $id)->value('result');
                if (is_string($raw)) {
                    $result = json_decode($raw, true);
                    if (($result['directory_valid'] ?? false) === true) {
                        return;
                    }
                    throw ValidationException::withMessages(['runtime_settings.document_root_subdir' => 'Select an existing child directory without symbolic links.']);
                }
                usleep(200000);
            } while (microtime(true) < $deadline);
            throw ValidationException::withMessages(['runtime_settings.document_root_subdir' => 'The directory check timed out. Please try again.']);
        } finally {
            DB::table('api_web_log_reads')->where('id', $id)->delete();
        }
    }

    public function settings(WebDomain $site): array
    {
        $field = $site->web_server_type === 'nginx' ? 'nginx_directives' : 'apache_directives';
        $text = (string) $site->getAttribute($field);
        if (! str_contains($text, self::BEGIN)) {
            return ['document_root_subdir' => '', 'environment' => []];
        }
        if (! preg_match('/^'.preg_quote(self::BEGIN, '/').' ([A-Za-z0-9+\/=]+)$/m', $text, $match)) {
            throw new ConflictHttpException('The managed hosting settings were changed outside the API.');
        }
        $settings = json_decode((string) base64_decode($match[1], true), true);
        if (! is_array($settings)) {
            throw new ConflictHttpException('The managed hosting settings were changed outside the API.');
        }

        return $this->normalize($settings);
    }

    public function normalize(array $settings): array
    {
        $path = $settings['document_root_subdir'] ?? '';
        $env = $settings['environment'] ?? [];
        if (! is_string($path) || ! WebRuntimeDirectory::valid($path)) {
            throw ValidationException::withMessages(['runtime_settings.document_root_subdir' => 'Use a relative child folder, without dot segments or absolute paths.']);
        }
        if (! is_array($env) || count($env) > 100) {
            throw ValidationException::withMessages(['runtime_settings.environment' => 'Use at most 100 named environment variables.']);
        }
        $size = 0;
        foreach ($env as $name => &$value) {
            if (! is_string($name) || ! preg_match('/\A[A-Z_][A-Z0-9_]{0,63}\z/D', $name)
                || preg_match('/\A(?:PHP_|HTTP_|SERVER_|REMOTE_|REQUEST_|SCRIPT_|DOCUMENT_|CONTEXT_|FCGI_|REDIRECT_|LD_|DYLD_|ISPCP_)/', $name)
                || in_array($name, ['PATH', 'PATH_INFO', 'PATH_TRANSLATED', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'IFS', 'ENV', 'BASH_ENV', 'TZ', 'TMP', 'TMPDIR', 'TEMP', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'QUERY_STRING', 'GATEWAY_INTERFACE', 'HTTPS', 'AUTH_TYPE'], true)) {
                throw ValidationException::withMessages(['runtime_settings.environment' => 'Use uppercase application variable names. Server and PHP control names are reserved.']);
            }
            $value ??= ''; // JSON null produced by Laravel's empty-string middleware means an empty value.
            if (! is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw ValidationException::withMessages(['runtime_settings.environment' => 'Values must be single-line strings of at most 4096 bytes.']);
            }
            $size += strlen($name) + strlen($value);
        }
        unset($value);
        if ($size > 16384) {
            throw ValidationException::withMessages(['runtime_settings.environment' => 'Environment variables may total at most 16 KiB.']);
        }
        ksort($env);

        return ['document_root_subdir' => $path, 'environment' => $env];
    }

    public function apply(WebDomain $site, ?array $submitted): void
    {
        $old = $this->settings($site);
        $settings = $submitted === null ? $old : $this->normalize($submitted);
        if ($settings === $old && $old === ['document_root_subdir' => '', 'environment' => []]) {
            return;
        }
        if ($settings !== ['document_root_subdir' => '', 'environment' => []]
            && $site->isDirty(['server_id', 'sys_groupid', 'document_root', 'web_folder', 'type', 'parent_domain_id'])) {
            throw new ConflictHttpException('Reset custom hosting settings before changing the website identity.');
        }
        foreach (['apache', 'nginx'] as $engine) {
            $field = $engine.'_directives';
            $existing = (string) $site->getAttribute($field);
            $without = preg_replace('/^'.preg_quote(self::BEGIN, '/').' [A-Za-z0-9+\/=]+\R.*?^'.preg_quote(self::END, '/').'\R?/ms', '', $existing, -1, $count);
            if ($count > 1 || str_contains($without, self::BEGIN) || str_contains($without, self::END)) {
                throw new ConflictHttpException('The managed hosting settings were changed outside the API.');
            }
            if ($settings['document_root_subdir'] !== '' && preg_match($engine === 'apache' ? '/^\s*DocumentRoot\b/mi' : '/##subroot\b|^\s*root\s/mi', $without)) {
                throw new ConflictHttpException('The website has an administrator-defined document root.');
            }
            $block = $this->compile($site, $engine, $settings);
            if (strlen($without) + strlen($block) > 60000) {
                throw ValidationException::withMessages(['runtime_settings.environment' => 'The generated configuration is too large. Use fewer or shorter values.']);
            }
            $site->setAttribute($field, $without.($block === '' ? '' : ($without !== '' && ! str_ends_with($without, "\n") ? "\n" : '').$block));
        }
    }

    public function compile(WebDomain $site, string $engine, array $settings): string
    {
        $path = $settings['document_root_subdir'];
        $env = $settings['environment'];
        if ($path === '' && $env === []) {
            return '';
        }
        $lines = [self::BEGIN.' '.base64_encode(json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
        $folder = $site->type === 'vhost' ? 'web' : (string) $site->web_folder;
        if ($folder === '' || ! WebRuntimeDirectory::valid($folder)) {
            throw ValidationException::withMessages(['runtime_settings' => 'The website base folder is invalid.']);
        }
        if ($engine === 'apache') {
            if ($path !== '') {
                $lines[] = 'DocumentRoot "{DOCROOT_CLIENT}/'.$path.'"';
                if ($site->php_fpm_chroot) {
                    $lines[] = '<IfModule mod_proxy_fcgi.c>';
                    $lines[] = 'ProxyFCGISetEnvIf "true" DOCUMENT_ROOT "/'.$folder.'/'.$path.'"';
                    $lines[] = 'ProxyFCGISetEnvIf "true" CONTEXT_DOCUMENT_ROOT "%{reqenv:DOCUMENT_ROOT}"';
                    $lines[] = 'ProxyFCGISetEnvIf "true" HOME "%{reqenv:DOCUMENT_ROOT}"';
                    $lines[] = 'ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "%{reqenv:DOCUMENT_ROOT}%{reqenv:SCRIPT_NAME}"';
                    $lines[] = '</IfModule>';
                }
            }
            foreach ($env as $key => $value) {
                // Decode in the expression evaluator, after both ISPConfig and Apache parse their configuration.
                $lines[] = 'SetEnvIfExpr "unbase64(\''.base64_encode($value).'\') =~ /^(.*)$/" '.$key.'=$1';
            }
        } else {
            if ($path !== '') {
                $lines[] = '##subroot '.$path.'##';
            }
            $locations = ['@php'];
            if ($site->php === 'hhvm') {
                $locations[] = '@phpfallback';
            }
            if ($site->cgi) {
                $locations[] = '/cgi-bin/';
            }
            foreach ($locations as $location) {
                $lines[] = 'location '.$location.' { ##merge##';
                if ($location !== '/cgi-bin/' && $path !== '' && $site->php_fpm_chroot) {
                    $lines[] = 'fastcgi_param DOCUMENT_ROOT "/'.$folder.'/'.$path.'";';
                    $lines[] = 'fastcgi_param HOME "/'.$folder.'/'.$path.'";';
                    $lines[] = 'fastcgi_param SCRIPT_FILENAME "/'.$folder.'/'.$path.'$fastcgi_script_name";';
                }
                foreach ($env as $key => $value) {
                    // Literal chars come from root-owned geo constants; no request/header controls their value.
                    $quoted = strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '${ispcp_literal_dollar}', '{' => '${ispcp_literal_open}', '}' => '${ispcp_literal_close}', '<' => '${ispcp_literal_less}', '>' => '${ispcp_literal_greater}', '#' => '${ispcp_literal_hash}']);
                    $lines[] = 'fastcgi_param '.$key.' "'.$quoted.'";';
                }
                $lines[] = '}';
            }
        }
        $lines[] = self::END;

        return implode("\n", $lines)."\n";
    }
}
