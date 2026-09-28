<?php

namespace App\Services;

use App\Models\Client;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Administrator-owned product policy; client writes never supply pool settings or raw INI. */
final class ClientWebPhpPolicyService
{
    public const FPM_FIELDS = ['php_fpm_use_socket', 'php_fpm_chroot', 'pm', 'pm_max_children', 'pm_start_servers',
        'pm_min_spare_servers', 'pm_max_spare_servers', 'pm_process_idle_timeout', 'pm_max_requests'];

    public const INI_FIELDS = ['memory_limit', 'max_execution_time', 'max_input_time', 'post_max_size', 'upload_max_filesize'];

    private const BEGIN = '; BEGIN ISPCONFIG REST PRODUCT PHP';

    private const END = '; END ISPCONFIG REST PRODUCT PHP';

    public static function validate(array $policy): array
    {
        $rules = [
            'policy' => ['required', 'array:force_fpm,ini,'.implode(',', self::FPM_FIELDS)],
            'policy.force_fpm' => ['required', 'boolean'],
            'policy.php_fpm_use_socket' => ['required', 'boolean'],
            'policy.php_fpm_chroot' => ['required', 'boolean'],
            'policy.pm' => ['required', Rule::in(['ondemand', 'dynamic'])],
            'policy.ini' => ['required', 'array:'.implode(',', self::INI_FIELDS)],
            'policy.ini.memory_limit' => ['required', 'string', 'regex:/\A(?:-1|[1-9][0-9]{0,8}[KMG]?)\z/iD'],
            'policy.ini.post_max_size' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,8}[KMG]?)\z/iD'],
            'policy.ini.upload_max_filesize' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,8}[KMG]?)\z/iD'],
            'policy.ini.max_execution_time' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'policy.ini.max_input_time' => ['required', 'integer', 'min:-1', 'max:2147483647'],
        ];
        foreach (array_slice(self::FPM_FIELDS, 3) as $key) {
            $rules['policy.'.$key] = ['required', 'integer', 'min:'.($key === 'pm_max_requests' ? 0 : 1), 'max:2147483647'];
        }
        Validator::make(['policy' => $policy], $rules)->validate();
        if ($policy['pm'] === 'dynamic' && ! ($policy['pm_max_children'] >= $policy['pm_max_spare_servers']
            && $policy['pm_max_spare_servers'] >= $policy['pm_start_servers']
            && $policy['pm_start_servers'] >= $policy['pm_min_spare_servers'])) {
            throw ValidationException::withMessages(['web_php_policy' => 'Dynamic pools require max_children >= max_spare_servers >= start_servers >= min_spare_servers.']);
        }
        if (self::bytes($policy['ini']['post_max_size']) > 0
            && (self::bytes($policy['ini']['upload_max_filesize']) === 0
                || self::bytes($policy['ini']['upload_max_filesize']) > self::bytes($policy['ini']['post_max_size']))) {
            throw ValidationException::withMessages(['web_php_policy' => 'upload_max_filesize must not exceed post_max_size.']);
        }
        foreach (['force_fpm', 'php_fpm_use_socket', 'php_fpm_chroot'] as $key) {
            $policy[$key] = (bool) $policy[$key];
        }
        foreach (array_slice(self::FPM_FIELDS, 3) as $key) {
            $policy[$key] = (int) $policy[$key];
        }
        foreach (self::INI_FIELDS as $key) {
            $policy['ini'][$key] = strtoupper((string) $policy['ini'][$key]);
        }

        return $policy;
    }

    private static function bytes(string $value): int
    {
        return (int) $value * (match (strtoupper(substr($value, -1))) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1
        });
    }

    public function policy(int $clientId, bool $lock = false): ?array
    {
        if ($clientId < 1 || ! Schema::hasTable('api_client_web_php_policies')) {
            return null;
        }
        $query = DB::table('api_client_web_php_policies')->where('client_id', $clientId);
        $json = ($lock ? $query->sharedLock() : $query)->value('settings');

        return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Called in the client transaction, before templates and website changes. */
    public function store(Client $client, ?array $policy): void
    {
        if (! Schema::hasTable('api_client_web_php_policies')) {
            if ($policy === null) {
                return;
            }
            throw ValidationException::withMessages(['web_php_policy' => 'Run the product PHP policy migration first.']);
        }
        $clientId = (int) $client->getKey();
        $old = DB::table('api_client_web_php_policies')->where('client_id', $clientId)->lockForUpdate()->first();
        $wasForced = $old !== null && (json_decode($old->settings, true, 512, JSON_THROW_ON_ERROR)['force_fpm'] ?? false);
        if ($policy === null) {
            DB::table('api_client_web_php_policies')->where('client_id', $clientId)->delete();
            if ($wasForced) {
                $client->forceFill(['web_php_options' => $old->original_php_modes])->save();
            }

            return;
        }
        $policy = self::validate($policy);
        if ($policy['force_fpm']) {
            $system = app(SitesConfigService::class)->globalConfig('sites')['web_php_options'] ?? '';
            if (trim($system) !== '' && ! in_array('php-fpm', array_map('trim', explode(',', $system)), true)) {
                throw ValidationException::withMessages(['web_php_policy' => 'PHP-FPM is disabled in the ISPConfig system settings.']);
            }
        }
        DB::table('api_client_web_php_policies')->updateOrInsert(['client_id' => $clientId], [
            'settings' => json_encode($policy, JSON_THROW_ON_ERROR),
            'original_php_modes' => $wasForced ? $old->original_php_modes : $client->web_php_options,
        ]);
        if (! $policy['force_fpm'] && $wasForced) {
            $client->forceFill(['web_php_options' => $old->original_php_modes])->save();
        }
    }

    /** Keep the native panel's PHP choices restricted as well as the REST UI. */
    public function clientAttributes(int $clientId, array $attributes): array
    {
        if (($this->policy($clientId)['force_fpm'] ?? false) !== true) {
            return $attributes;
        }
        if (array_key_exists('web_php_options', $attributes)) {
            DB::table('api_client_web_php_policies')->where('client_id', $clientId)
                ->update(['original_php_modes' => $attributes['web_php_options']]);
        }
        $attributes['web_php_options'] = 'php-fpm';

        return $attributes;
    }

    /** Apply to all owned primary sites and independent vhosts, never simple aliases. */
    public function syncSites(int $clientId): void
    {
        if (! Schema::hasTable('api_client_web_php_policies') || ! Schema::hasTable('web_domain')) {
            return;
        }
        if ($this->policy($clientId) === null && ! DB::table('api_web_php_policy_sites')->where('client_id', $clientId)->exists()) {
            return;
        }
        $groups = DB::table('sys_group')->where('client_id', $clientId)->pluck('groupid');
        WebDomain::withoutGlobalScopes()->whereIn('sys_groupid', $groups)
            ->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias'])->orderBy('domain_id')->lockForUpdate()
            ->get()->each(fn (WebDomain $site) => app(WebDomainService::class)->update($site, []));
    }

    /** Also called before the website's datalog entry, using raw ISPConfig values. */
    public function apply(WebDomain $site): void
    {
        if (! Schema::hasTable('api_client_web_php_policies')) {
            return;
        }
        $clientId = (int) DB::table('sys_group')->where('groupid', $site->sys_groupid)->value('client_id');
        // Locking reads see the latest policy even in a repeatable-read
        // transaction, and hold it until the website write commits.
        $policy = $this->policy($clientId, true);
        $snapshot = $site->getKey() ? DB::table('api_web_php_policy_sites')->where('domain_id', $site->getKey())->first() : null;
        if ($snapshot !== null && (int) $snapshot->client_id !== $clientId) {
            // A transfer cannot carry the former account's product policy.
            $site->setRawAttributes(array_merge($site->getAttributes(), json_decode($snapshot->original_fields, true, 512, JSON_THROW_ON_ERROR)));
            $site->setAttribute('custom_php_ini', self::withoutBlock((string) $site->custom_php_ini));
            $this->forgetSite((int) $site->getKey());
            $snapshot = null;
        }
        if ($policy === null && ($snapshot === null || (int) $snapshot->client_id !== $clientId)) {
            return;
        }
        $without = self::withoutBlock((string) $site->custom_php_ini);
        if ($policy === null) {
            $site->setRawAttributes(array_merge($site->getAttributes(), json_decode($snapshot->original_fields, true, 512, JSON_THROW_ON_ERROR)));
            $site->setAttribute('custom_php_ini', $without);
            DB::table('api_web_php_policy_sites')->where('domain_id', $site->getKey())->delete();

            return;
        }
        if (preg_match('/^\s*\[/m', $without)) {
            throw ValidationException::withMessages(['web_php_policy' => 'Remove sectioned custom PHP configuration before applying a product policy.']);
        }
        if ($site->getKey()) {
            $this->snapshot($site, $clientId, $policy['force_fpm']);
        }
        foreach (self::FPM_FIELDS as $key) {
            $site->setAttribute($key, $policy[$key]);
        }
        if ($policy['force_fpm']) {
            $site->setAttribute('php', 'php-fpm');
            $versions = app(PhpVersionService::class);
            $usable = $versions->usable((int) $site->server_id, [$clientId], 'php-fpm');
            if ($site->server_php_id && ! $usable->contains(fn ($row) => (int) $row->server_php_id === (int) $site->server_php_id)) {
                $site->setAttribute('server_php_id', 0);
            }
            if (! $site->server_php_id && $versions->defaultHidden((int) $site->server_id)) {
                if ($usable->isEmpty()) {
                    throw ValidationException::withMessages(['web_php_policy' => 'No usable PHP-FPM version is available for this website.']);
                }
                $site->setAttribute('server_php_id', (int) $usable->first()->server_php_id);
            }
        }
        $snippets = app(WebPhpSettingsService::class)->snippetValues($site);
        foreach ($policy['ini'] as $key => $value) {
            if (isset($snippets[$key]) && strcasecmp($snippets[$key], $value) !== 0) {
                throw ValidationException::withMessages(['web_php_policy' => 'A required PHP directive snippet conflicts with the product limits for '.$site->domain.'.']);
            }
        }
        $lines = [];
        foreach ($policy['ini'] as $key => $value) {
            // ISPConfig mistakes literal 0/1 for boolean directives in FPM pools.
            // Quoting avoids its loose switch comparison and keeps php_admin_value.
            $lines[] = $key.' = '.(in_array($value, ['0', '1'], true) ? '"'.$value.'"' : $value);
        }
        $site->setAttribute('custom_php_ini', $without.($without !== '' && ! str_ends_with($without, "\n") ? "\n" : '')
            .self::BEGIN."\n".implode("\n", $lines)."\n".self::END."\n");
    }

    public function snapshot(WebDomain $site, int $clientId, bool $forceFpm): void
    {
        $fields = array_intersect_key($site->getAttributes(), array_flip(array_merge($forceFpm ? ['php', 'server_php_id'] : [], self::FPM_FIELDS)));
        $stored = DB::table('api_web_php_policy_sites')->where('domain_id', $site->getKey())->value('original_fields');
        $original = $stored === null ? [] : json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        DB::table('api_web_php_policy_sites')->updateOrInsert(['domain_id' => $site->getKey()], [
            'client_id' => $clientId, 'original_fields' => json_encode($original + $fields, JSON_THROW_ON_ERROR),
        ]);
    }

    public function forgetSite(int $domainId): void
    {
        if (Schema::hasTable('api_web_php_policy_sites')) {
            DB::table('api_web_php_policy_sites')->where('domain_id', $domainId)->delete();
        }
    }

    public static function withoutBlock(string $ini): string
    {
        $ini = str_replace(["\r\n", "\r"], "\n", $ini);
        $without = preg_replace('/^'.preg_quote(self::BEGIN, '/').'\n.*?^'.preg_quote(self::END, '/').'(?:\n|$)/ms', '', $ini, -1, $count);
        if ($count > 1 || str_contains($without, self::BEGIN) || str_contains($without, self::END)) {
            throw ValidationException::withMessages(['web_php_policy' => 'The managed product PHP block was changed outside the API.']);
        }

        return $without;
    }

    public function forgetClient(int $clientId): void
    {
        if (Schema::hasTable('api_client_web_php_policies')) {
            DB::table('api_client_web_php_policies')->where('client_id', $clientId)->delete();
            DB::table('api_web_php_policy_sites')->where('client_id', $clientId)->delete();
        }
    }
}
