<?php

namespace App\Providers;

use App\Extensions\Hooks;
use App\Models\Client;
use App\Models\CronJob;
use App\Models\WebDatabase;
use App\Models\WebDatabaseUser;
use App\Models\WebDomain;
use App\Services\ClientResourceLimitsService;
use App\Services\ClientWebPhpPolicyService;
use App\Services\DatabaseOperationService;
use App\Services\WebLogService;
use App\Services\WebPhpSettingsService;
use App\Services\WebRuntimeService;
use App\Services\WebWafService;
use App\Services\WordPressCronService;
use App\Services\WordPressService;
use App\Support\CronOutputLog;
use App\Support\IspContext;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use WeakMap;

/**
 * The hosting features (spec 058): database operations, website logs, runtime and PHP settings, WordPress tools,
 * WAF, product PHP policies and PHP-FPM limits, attached to core resources through App\Extensions\Hooks.
 */
class HostingServiceProvider extends ServiceProvider
{
    /** Website fields the runtime directory check depends on. */
    private const RUNTIME_IDENTITY = ['server_id', 'sys_groupid', 'document_root', 'web_folder', 'type', 'domain'];

    /** @var array<int, array<string, mixed>> website id => identity when its runtime directory was checked */
    private array $runtimeChecked = [];

    /** @var WeakMap<WebDomain, WebDomain> new website => its fields before the product PHP policy */
    private WeakMap $beforePolicy;

    public function register(): void
    {
        $this->beforePolicy = new WeakMap;

        foreach ([DatabaseOperationService::class, WebLogService::class, WebWafService::class, WordPressService::class] as $service) {
            $this->app->scoped($service);
            $this->app->rebinding('request', fn ($app) => $app->forgetInstance($service));
        }
    }

    public function boot(Hooks $hooks): void
    {
        // Environment values are kept exactly as sent.
        TrimStrings::except(['runtime_settings.environment.*']);
        $this->loadRoutesFrom(base_path('routes/hosting.php'));

        $this->websites($hooks);
        $this->scheduledTasks($hooks);
        $this->databases($hooks);
        $this->clients($hooks);
    }

    private function websites(Hooks $hooks): void
    {
        $hooks->attributes(WebDomain::class, fn (WebDomain $site): array => [
            'logs_available' => app(WebLogService::class)->available($site),
            'waf_available' => app(WebWafService::class)->available($site),
            'waf_mode' => app(WebWafService::class)->mode($site),
            'wordpress' => app(WordPressService::class)->summary($site),
            'runtime_capabilities' => app(WebRuntimeService::class)->capabilities($site),
        ]);
        $hooks->attribute(WebDomain::class, 'public_document_root', fn (?string $root, WebDomain $site): ?string => app(WebRuntimeService::class)->publicRoot($site));
        $hooks->detail(WebDomain::class, fn (WebDomain $site): array => [
            'runtime_settings' => app(WebRuntimeService::class)->view($site),
            'php_settings' => app(WebPhpSettingsService::class)->view($site),
        ]);

        $hooks->fields(WebDomain::class, fn (Request $request, string $action): array => $action !== 'update' ? [] : [
            'php_settings' => ['sometimes', 'array', 'prohibits:custom_php_ini', function ($attribute, $value, $fail): void {
                if (! WebPhpSettingsService::valid($value)) {
                    $fail('Only the supported editable PHP settings and values may be submitted.');
                }
            }],
            'runtime_settings' => ['sometimes', 'array:document_root_subdir,environment'],
            'runtime_settings.document_root_subdir' => ['present_with:runtime_settings', 'nullable', 'string', 'max:200'],
            'runtime_settings.environment' => ['present_with:runtime_settings', 'array', 'max:100'],
            'runtime_settings.environment.*' => ['nullable', 'string', 'max:4096'],
        ]);

        // The directory check runs before the write transaction (the worker must see the committed read request);
        // the guard inside the transaction refuses the write when the website changed meanwhile.
        $hooks->preflight(WebDomain::class, function (?WebDomain $site, array $fields): void {
            if ($site === null || ! array_key_exists('runtime_settings', $fields)) {
                return;
            }
            app(WebRuntimeService::class)->preflight($site, $fields);
            $this->runtimeChecked[(int) $site->getKey()] = Arr::only($site->getAttributes(), self::RUNTIME_IDENTITY);
        });
        $hooks->guard(WebDomain::class, function (WebDomain $site): void {
            app(WordPressService::class)->guardSite((int) $site->getKey());
            $checked = $this->runtimeChecked[(int) $site->getKey()] ?? null;
            if ($checked !== null && Arr::only($site->getAttributes(), self::RUNTIME_IDENTITY) !== $checked) {
                throw new ConflictHttpException('The website changed during the directory check. Reload and try again.');
            }
        });

        $hooks->saving(WebDomain::class, function (WebDomain $site, array $fields, string $action): void {
            app(WebWafService::class)->refreshIdentity($site);
            if ($action === 'create') {
                $this->beforePolicy[$site] = clone $site;
            }
            app(ClientWebPhpPolicyService::class)->apply($site);
            if ($action === 'update') {
                app(WebRuntimeService::class)->apply($site, $fields['runtime_settings'] ?? null);
                app(WebPhpSettingsService::class)->apply($site, $fields['php_settings'] ?? null);
            }
        });
        // A new website's fields before the product policy, so removing the policy restores them.
        $hooks->saved(WebDomain::class, function (WebDomain $site, array $fields, string $action): void {
            $before = $this->beforePolicy[$site] ?? null;
            if ($action !== 'create' || $before === null) {
                return;
            }
            $policy = app(ClientWebPhpPolicyService::class);
            $ownerClientId = (int) DB::table('sys_group')->where('groupid', $site->getAttributes()['sys_groupid'])->value('client_id');
            $stored = $policy->policy($ownerClientId);
            if ($stored !== null) {
                $before->setAttribute('domain_id', $site->getKey());
                $policy->snapshot($before, $ownerClientId, $stored['force_fpm']);
            }
        });
        $hooks->deleted(WebDomain::class, fn (int $id) => app(ClientWebPhpPolicyService::class)->forgetSite($id));
    }

    private function scheduledTasks(Hooks $hooks): void
    {
        $hooks->attributes(CronJob::class, fn (CronJob $job): array => [
            'wordpress' => app(WordPressCronService::class)->managed($job),
            'output_log' => CronOutputLog::parse((string) ($job->getAttributes()['command'] ?? '')) !== null,
        ]);
        // The task's own command; the output-log prefix exists only in the native row.
        $hooks->attribute(CronJob::class, 'command', fn ($value) => is_string($value) ? (CronOutputLog::parse($value)['command'] ?? $value) : $value);
        $hooks->fields(CronJob::class, fn (): array => ['output_log' => ['sometimes', 'boolean']], ['output_log']);
        $hooks->guard(CronJob::class, fn (CronJob $job) => app(WordPressCronService::class)->guard($job));

        // Own output log (spec 054): the native command gets the managed prefix and ISPConfig's shared log is turned
        // off, since it would take the output of the command's last part. URL tasks run through ISPConfig's wget line
        // and keep its options.
        $hooks->saving(CronJob::class, function (CronJob $job, array $fields, string $action): void {
            $current = $action === 'update' ? CronOutputLog::parse((string) $job->getRawOriginal('command')) : null;
            $enabled = array_key_exists('output_log', $fields) ? (bool) $fields['output_log'] : $current !== null;
            $attributes = $job->getAttributes();
            if (! $enabled || $attributes['type'] === 'url') {
                return;
            }
            $documentRoot = (string) DB::table('web_domain')->where('domain_id', $attributes['parent_domain_id'])->value('document_root');
            $job->forceFill([
                'command' => CronOutputLog::wrap((string) $attributes['command'], $current['token'] ?? CronOutputLog::token(), $attributes['type'], $documentRoot),
                'log' => false,
            ]);
        });
    }

    private function databases(Hooks $hooks): void
    {
        $hooks->attributes(WebDatabase::class, fn (WebDatabase $database): array => [
            'operations' => app(DatabaseOperationService::class)->capabilities($database),
        ]);
        $hooks->guard(WebDatabase::class, fn (WebDatabase $database) => app(WordPressService::class)->guardDatabase((int) $database->getKey()));
        $hooks->guard(WebDatabaseUser::class, fn (WebDatabaseUser $user) => app(WordPressService::class)->guardDatabaseUser((int) $user->getKey()));
    }

    private function clients(Hooks $hooks): void
    {
        $hooks->fields(Client::class, fn (): array => [
            'web_php_policy' => ['sometimes', 'nullable', 'array'],
            'web_resource_limits' => ['sometimes', 'nullable', 'array'],
        ]);
        $hooks->authorize(Client::class, fn (Request $request): bool => (! $request->exists('web_php_policy') && ! $request->exists('web_resource_limits'))
            || app(IspContext::class)->authScope()->isAdmin);
        $hooks->prepare(Client::class, function (array $fields): array {
            if (isset($fields['web_php_policy'])) {
                $fields['web_php_policy'] = ClientWebPhpPolicyService::validate($fields['web_php_policy']);
            }
            if (isset($fields['web_resource_limits'])) {
                // Shape only; the PHP policy cross-check runs with the stored policy after the client write.
                $fields['web_resource_limits'] = ClientResourceLimitsService::validate($fields['web_resource_limits']);
            }

            return $fields;
        });
        $hooks->detail(Client::class, fn (Client $client): array => [
            'web_php_policy' => app(ClientWebPhpPolicyService::class)->policy((int) $client->getKey()),
            'web_resource_limits' => app(ClientResourceLimitsService::class)->limits((int) $client->getKey()),
        ]);

        // In the client transaction, before the templates: the policy, then the administrator cgroup limits (spec
        // 053), checked against the effective policy so a pool can always start pm_max_children workers.
        $hooks->saved(Client::class, function (Client $client, array $fields, string $action): void {
            $clientId = (int) $client->getKey();
            $policySent = array_key_exists('web_php_policy', $fields);
            $policy = app(ClientWebPhpPolicyService::class);
            if ($policySent) {
                $policy->store($client, $fields['web_php_policy']);
            }
            $limits = app(ClientResourceLimitsService::class);
            if (array_key_exists('web_resource_limits', $fields)) {
                $limits->store($clientId, $fields['web_resource_limits'], $policy->policy($clientId));
            } elseif ($policySent) {
                $limits->assertCompatible($clientId, $policy->policy($clientId));
            }
            if ($policySent && $action === 'update') {
                $policy->syncSites($clientId);
            }
        });
        // Keep the native panel's PHP choices restricted as well.
        $hooks->filter('client.attributes', fn (array $attributes, int $clientId): array => app(ClientWebPhpPolicyService::class)->clientAttributes($clientId, $attributes));
        $hooks->deleted(Client::class, function (int $clientId): void {
            app(ClientWebPhpPolicyService::class)->forgetClient($clientId);
            app(ClientResourceLimitsService::class)->forgetClient($clientId);
        });
    }
}
