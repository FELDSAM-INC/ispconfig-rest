<?php

namespace App\Services;

use App\Models\WebDomain;
use App\Support\WebPhpDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class WebPhpSettingsService
{
    public const WRITABLE = ['opcache.enable', 'opcache_get_status', 'error_reporting', 'display_errors', 'log_errors', 'allow_url_fopen', 'file_uploads', 'short_open_tag'];

    public const ERROR_REPORTING = ['0', 'E_ALL', 'E_ALL & ~E_DEPRECATED & ~E_STRICT', 'E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED', 'E_ERROR | E_WARNING | E_PARSE'];

    public static function valid(mixed $settings): bool
    {
        if (! is_array($settings) || array_diff(array_keys($settings), self::WRITABLE) !== []) {
            return false;
        }
        foreach ($settings as $key => $value) {
            if ($key === 'error_reporting' ? ! in_array($value, self::ERROR_REPORTING, true) : ! is_bool($value)) {
                return false;
            }
        }

        return true;
    }

    private function defaults(WebDomain $site): ?array
    {
        if (! in_array($site->php, ['fast-cgi', 'php-fpm'], true) || ! Schema::hasTable('api_web_php_defaults')) {
            return null;
        }
        $mode = $site->php === 'php-fpm' || $site->web_server_type === 'nginx' ? 'fpm' : 'cgi';
        $raw = DB::table('api_web_php_defaults')->where('server_id', $site->server_id)->where('server_php_id', $site->server_php_id)
            ->where('mode', $mode)->where('measured_at', '>=', time() - 150)->value('settings');
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) && empty($data['unavailable']) && is_array($data['values'] ?? null) && is_array($data['scan'] ?? null)
            ? $data + ['mode' => $mode] : null;
    }

    /** ISPConfig appends required PHP snippets after the website's custom INI. */
    private function snippet(WebDomain $site): array
    {
        if (! $site->directive_snippets_id) {
            return [];
        }
        $parent = DB::table('directive_snippets')->where('directive_snippets_id', $site->directive_snippets_id)
            ->where('type', $site->web_server_type)->where('active', 'y')->where('customer_viewable', 'y')->first();
        $result = [];
        foreach (explode(',', (string) ($parent->required_php_snippets ?? '')) as $id) {
            if ((int) $id < 1) {
                continue;
            }
            $ini = DB::table('directive_snippets')->where('directive_snippets_id', (int) $id)->where('type', 'php')->where('active', 'y')->value('snippet');
            $result = array_replace($result, WebPhpDefaults::values((string) $ini));
        }

        return $result;
    }

    private static function functions(string $value): array
    {
        $functions = array_values(array_unique(array_filter(preg_split('/[\s,]+/', strtolower(trim($value))))));
        foreach ($functions as $function) {
            if (! preg_match('/\A[a-z_][a-z0-9_]*\z/D', $function)) {
                throw new RuntimeException('php_settings_unavailable');
            }
        }

        return $functions;
    }

    private function configuration(WebDomain $site): array
    {
        if (preg_match('/^\s*\[/m', (string) $site->custom_php_ini)) {
            throw new RuntimeException('php_settings_unavailable');
        }
        $defaults = $this->defaults($site);
        $custom = WebPhpDefaults::values((string) $site->custom_php_ini);
        $snippet = $this->snippet($site);
        $values = array_replace(array_fill_keys(WebPhpDefaults::KEYS, null), $defaults['values'] ?? [], $custom, $snippet);
        $locked = array_keys($snippet);
        if (($defaults['mode'] ?? '') === 'cgi') {
            $values = array_replace($values, $defaults['scan']);
            $locked = array_merge($locked, array_keys($defaults['scan']));
        } elseif (($defaults['mode'] ?? '') === 'fpm') {
            // FPM cannot enable OPcache in a pool when startup configuration disabled it.
            if (($defaults['values']['opcache.enable'] ?? null) === 'off') {
                $values['opcache.enable'] = 'off';
                $locked[] = 'opcache.enable';
            }
            $values['disable_functions'] = implode(',', array_unique(array_merge(
                self::functions((string) ($defaults['values']['disable_functions'] ?? '')),
                self::functions((string) ($values['disable_functions'] ?? ''))
            )));
        }
        $blockedGlobally = ($defaults['mode'] ?? '') === 'fpm'
            && in_array('opcache_get_status', self::functions((string) ($defaults['values']['disable_functions'] ?? '')), true);
        $statusLocked = $blockedGlobally || in_array('disable_functions', $locked, true);
        if ($statusLocked) {
            $locked[] = 'opcache_get_status';
        }
        if (empty($defaults['opcache'])) {
            $locked = array_merge($locked, ['opcache.enable', 'opcache_get_status']);
        }
        $values['opcache_get_status'] = $values['disable_functions'] === null ? null
            : (in_array('opcache_get_status', self::functions($values['disable_functions']), true) ? 'off' : 'on');

        return ['values' => $values, 'editable' => $defaults === null ? [] : array_values(array_diff(self::WRITABLE, $locked)),
            'available' => $defaults !== null, 'opcache_get_status_locked' => $statusLocked];
    }

    public function view(WebDomain $site): array
    {
        try {
            return $this->configuration($site);
        } catch (RuntimeException) {
            return ['values' => array_fill_keys(array_merge(WebPhpDefaults::KEYS, ['opcache_get_status']), null),
                'editable' => [], 'available' => false, 'opcache_get_status_locked' => false];
        }
    }

    /** Called under the website row lock, before BaseModel emits its datalog entry. */
    public function apply(WebDomain $site, ?array $changes): void
    {
        if ($changes === null || $changes === []) {
            return;
        }
        $view = $this->view($site);
        if (! self::valid($changes) || array_diff(array_keys($changes), $view['editable']) !== []) {
            throw ValidationException::withMessages(['php_settings' => 'These PHP settings are unavailable or enforced by the server. Reload and try again.']);
        }
        $ini = (string) $site->custom_php_ini;
        // ISPConfig's custom INI editor supports flat directives, not per-directory sections.
        if (preg_match('/^\s*\[/m', $ini)) {
            throw ValidationException::withMessages(['php_settings' => 'This custom PHP configuration requires administrator changes.']);
        }
        foreach ($changes as $key => $value) {
            $encoded = is_bool($value) ? ($value ? 'on' : 'off') : $value;
            if ($view['values'][$key] === $encoded) {
                continue;
            }
            if ($key === 'opcache_get_status') {
                $functions = self::functions((string) $view['values']['disable_functions']);
                $functions = array_values(array_diff($functions, ['opcache_get_status']));
                if (! $value) {
                    $functions[] = 'opcache_get_status';
                }
                // Keep the complete effective list, including inherited restrictions in CGI mode.
                $key = 'disable_functions';
                $encoded = '"'.implode(',', $functions).'"';
            }
            $lines = preg_split('/\r\n|\r|\n/', $ini);
            $lines = array_filter($lines, fn (string $line): bool => ! preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line));
            $ini = rtrim(implode("\n", $lines))."\n".$key.' = '.$encoded."\n";
        }
        $site->setAttribute('custom_php_ini', $ini);
    }
}
