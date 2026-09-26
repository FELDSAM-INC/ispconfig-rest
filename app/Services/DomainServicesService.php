<?php

namespace App\Services;

use App\Exceptions\ProblemAuthorizationException;
use App\Http\Requests\StoreDnsSoaRequest;
use App\Http\Requests\StoreMailDomainRequest;
use App\Http\Requests\StoreWebChildDomainRequest;
use App\Http\Requests\StoreWebDomainRequest;
use App\Models\ClientDomain;
use App\Models\DnsRecord;
use App\Models\DnsSoa;
use App\Models\MailDomain;
use App\Models\WebChildDomain;
use App\Models\WebDomain;
use App\Support\IspContext;
use App\Support\ProblemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Atomic domain + service provisioning using ISPConfig models, limits and datalog. */
class DomainServicesService
{
    public function __construct(private IspContext $context) {}

    private function group(): int
    {
        // This account endpoint never accepts an arbitrary client/group/server identifier.
        abort_if($this->context->authScope()->clientId <= 0, 403, 'Use a client account key.');

        return $this->context->sysGroupId();
    }

    public function inventory(): array
    {
        $group = $this->group();
        $names = ClientDomain::query()->readable()->where('sys_groupid', $group)->pluck('domain')->all();
        $web = $this->context->authScope()->applyReadPredicate(DB::table('web_domain'), 'r')->where('sys_groupid', $group)->whereIn('type', ['vhost', 'alias', 'vhostalias', 'vhostsubdomain', 'subdomain'])->get()->keyBy('domain');
        $mail = MailDomain::query()->readable()->where('sys_groupid', $group)->get()->keyBy('domain');
        $dns = DnsSoa::query()->readable()->where('sys_groupid', $group)->get()->keyBy(fn ($row) => rtrim($row->origin, '.'));
        $names = array_unique(array_merge($names, $web->keys()->all(), $mail->keys()->all(), $dns->keys()->all()));
        sort($names, SORT_STRING);

        return array_map(function ($name) use ($web, $mail, $dns) {
            $alias = app(AliasServicesService::class)->mailDomainMetadata($name);

            return ['domain' => $name, 'hosting_type' => isset($web[$name]) ? (in_array($web[$name]->type, ['vhost', 'vhostsubdomain', 'vhostalias'], true) ? 'webhosting' : 'alias') : 'none',
                'dns_service' => isset($dns[$name]) && $dns[$name]->active, 'mail_service' => isset($mail[$name]) && $mail[$name]->active && ($alias['active'] ?? true)];
        }, $names);
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $group = $this->group();
            // Serialize creates for this account, including quota checks and activation retries.
            $client = DB::table('client')->where('client_id', $this->context->authScope()->clientId)->lockForUpdate()->first();
            if (($client->locked ?? 'n') === 'y') {
                throw new ProblemAuthorizationException(LockedClientGuard::MESSAGE, ProblemType::ACCOUNT_LOCKED);
            }
            $name = $input['domain'];
            $this->assertOwnership($name, $group);
            $registered = ClientDomain::query()->where('domain', $name)->first();
            if ($registered === null) {
                $registered = new ClientDomain(['domain' => $name]);
                $registered->setAttribute('sys_perm_group', 'ru');
                $registered->save();
            }
            $website = null;
            if ($input['hosting_type'] !== 'none') {
                if (DB::table('web_domain')->where('domain', $name)->exists()) {
                    throw new ConflictHttpException('This domain already has hosting.');
                }
                if ($input['hosting_type'] === 'webhosting') {
                    $fields = array_intersect_key($input, array_flip(['domain', 'hd_quota', 'traffic_quota', 'php', 'server_php_id']));
                    $fields += ['active' => true, 'subdomain' => 'www'];
                    $website = app(WebDomainService::class)->create($this->validate(StoreWebDomainRequest::class, $fields));
                } else {
                    $fields = $this->validate(StoreWebChildDomainRequest::class, ['domain' => $name, 'type' => 'alias', 'parent_domain_id' => $input['parent_domain_id']]);
                    $parent = WebDomain::query()->readable()->where('sys_groupid', $group)->where('type', 'vhost')->findOrFail($fields['parent_domain_id']);
                    if (! empty($input['redirect_301'])) {
                        $engine = app(SitesConfigService::class)->serverConfig((int) $parent->server_id, 'web')['server_type'] ?? '';
                        if (! in_array($engine, ['apache', 'nginx'], true)) {
                            throw ValidationException::withMessages(['redirect_301' => 'The web server type is unavailable.']);
                        }
                        $www = in_array($parent->seo_redirect, ['non_www_to_www', '*_domain_tld_to_www_domain_tld', '*_to_www_domain_tld'], true);
                        $fields += ['redirect_type' => $engine === 'nginx' ? 'permanent' : 'R=301,L',
                            'redirect_path' => ($parent->ssl ? 'https://' : 'http://').($www ? 'www.' : '').$parent->domain.'/', 'seo_redirect' => ''];
                    }
                    $website = new WebChildDomain($fields + ['active' => true, 'subdomain' => 'www',
                        'seo_redirect' => $parent->seo_redirect, 'ssl_letsencrypt_exclude' => ! $parent->ssl]);
                    app(SitesService::class)->deriveServerAndGroup($website, $parent);
                    $website->save();
                }
            }
            $this->services($name, (bool) $input['dns_service'], (bool) $input['mail_service'], $website);

            return ['domain' => $name, 'hosting_type' => $input['hosting_type'], 'website_id' => $website?->id];
        });
    }

    public function activate(string $name, string $service): void
    {
        DB::transaction(function () use ($name, $service) {
            $group = $this->group();
            $client = DB::table('client')->where('client_id', $this->context->authScope()->clientId)->lockForUpdate()->first();
            if (($client->locked ?? 'n') === 'y') {
                throw new ProblemAuthorizationException(LockedClientGuard::MESSAGE, ProblemType::ACCOUNT_LOCKED);
            }
            $this->assertOwnership($name, $group);
            $known = ClientDomain::query()->readable()->where('sys_groupid', $group)->where('domain', $name)->exists()
                || WebDomain::query()->readable()->where('sys_groupid', $group)->where('domain', $name)->exists()
                || WebChildDomain::query()->readable()->where('sys_groupid', $group)->where('domain', $name)->exists()
                || MailDomain::query()->readable()->where('sys_groupid', $group)->where('domain', $name)->exists()
                || DnsSoa::query()->readable()->where('sys_groupid', $group)->where('origin', $name.'.')->exists();
            abort_unless($known, 404);
            $website = WebDomain::query()->readable()->where('sys_groupid', $group)->where('domain', $name)->first()
                ?? WebChildDomain::query()->readable()->where('sys_groupid', $group)->where('domain', $name)->first();
            $this->services($name, $service === 'dns', $service === 'mail', $website);
        });
    }

    private function assertOwnership(string $name, int $group): void
    {
        foreach (['domain' => 'domain', 'web_domain' => 'domain', 'mail_domain' => 'domain', 'dns_soa' => 'origin'] as $table => $field) {
            if (DB::table($table)->where($field, $field === 'origin' ? $name.'.' : $name)->where('sys_groupid', '!=', $group)->exists()) {
                throw new ConflictHttpException('This domain is already in use.');
            }
        }
    }

    private function services(string $name, bool $dns, bool $mail, $website): void
    {
        if ($website !== null && in_array($website->type, ['alias', 'vhostalias'], true)) {
            $payload = ['dns_service' => $dns];
            if ($mail) {
                $payload['mail_service'] = true;
            }
            app(AliasServicesService::class)->configure($website, $payload);
            if ($dns) {
                $zone = DnsSoa::query()->readable()->where('origin', $name.'.')->firstOrFail();
                if (! $zone->active) {
                    $zone->active = true;
                    $zone->save();
                }
            }

            return;
        }
        if ($mail) {
            $domain = MailDomain::query()->readable()->where('domain', $name)->first();
            if ($domain === null) {
                $domain = new MailDomain($this->validate(StoreMailDomainRequest::class, ['domain' => $name, 'active' => true]));
                $domain->save();
                app(MailDomainService::class)->syncDnsAfterInsert($domain);
            } elseif (! $domain->active) {
                $domain->active = true;
                $domain->save();
            }
            $zone = DnsSoa::query()->readable()->where('origin', $name.'.')->first();
            if ($zone !== null && $zone->active) {
                $addresses = app(HostingAddressService::class)->addresses($this->context->authScope()->clientId);
                $this->mailRecord($zone, $domain, $addresses);
            }
        }
        if ($dns) {
            $this->dns($name, $website);
        }
    }

    private function dns(string $name, $website): void
    {
        $zone = DnsSoa::query()->readable()->where('origin', $name.'.')->first();
        if ($zone !== null) {
            if (! $zone->active) {
                $zone->active = true;
                $zone->save();
            }

            return;
        }
        $addresses = app(HostingAddressService::class)->addresses($this->context->authScope()->clientId);
        $server = collect($addresses['dns'])->firstWhere('is_default', true);
        if (empty($server['nameservers'])) {
            throw ValidationException::withMessages(['dns_service' => 'No DNS server and name servers are assigned to this account.']);
        }
        $zone = new DnsSoa($this->validate(StoreDnsSoaRequest::class, ['origin' => $name.'.', 'ns' => rtrim($server['nameservers'][0]['name'], '.').'.', 'mbox' => 'hostmaster.'.$name.'.', 'active' => true]));
        $zone->serial = app(DnsSerialService::class)->increaseSerial(null);
        $zone->save();
        foreach ($server['nameservers'] as $ns) {
            $this->record($zone, $zone->origin, 'NS', rtrim($ns['name'], '.').'.');
        }
        if ($website !== null) {
            $web = collect($addresses['web'])->firstWhere('server_id', (int) $website->server_id);
            foreach (['A' => ['ipv4', 'ip_address', FILTER_FLAG_IPV4], 'AAAA' => ['ipv6', 'ipv6_address', FILTER_FLAG_IPV6]] as $type => [$key, $field, $flag]) {
                $ip = $website->$field;
                $ips = filter_var($ip, FILTER_VALIDATE_IP, $flag) ? [$ip] : ($web[$key] ?? []);
                foreach ($ips as $value) {
                    foreach ([$zone->origin, 'www'] as $host) {
                        $this->record($zone, $host, $type, $value);
                    }
                }
            }
        }
        $mail = MailDomain::query()->readable()->where('domain', $name)->where('active', 'y')->first();
        if ($mail !== null) {
            $this->mailRecord($zone, $mail, $addresses);
            app(MailDomainService::class)->syncDnsAfterInsert($mail);
        }
    }

    private function mailRecord(DnsSoa $zone, MailDomain $mail, array $addresses): void
    {
        // Preserve deliberately configured mail routing; only fill in a missing apex MX.
        if (DnsRecord::query()->where('zone', $zone->id)->where('type', 'MX')->whereIn('name', [$zone->origin, '@', ''])->exists()) {
            return;
        }
        $server = collect($addresses['mail'])->firstWhere('server_id', (int) $mail->server_id);
        if (! empty($server['server_name']) && str_contains($server['server_name'], '.')) {
            $this->record($zone, $zone->origin, 'MX', rtrim($server['server_name'], '.').'.', 10);
        }
    }

    private function record(DnsSoa $zone, string $name, string $type, string $data, int $priority = 0): void
    {
        $record = new DnsRecord(['zone' => $zone->id, 'server_id' => $zone->server_id, 'name' => $name, 'type' => $type, 'data' => $data, 'aux' => $priority, 'ttl' => $zone->ttl, 'active' => true]);
        $record->save();
    }

    /** Reuse the same defaults, permissions and validation as the individual resource endpoints. */
    private function validate(string $class, array $fields): array
    {
        /** @var FormRequest $request */
        $request = $class::create('/api/v1/sites/domain-services', 'POST', $fields);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request->validated();
    }
}
