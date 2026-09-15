<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared setup for the usage statistics feature tests (spec 017).
 *
 * Composes the resource schemas (sites, mail, DNS), monitor_data, the traffic
 * tables and the four-identity tenant matrix; freezes the clock at
 * 2026-09-15 10:00 UTC with the API timezone set to Europe/Prague so period and
 * timestamp logic never silently assumes UTC; seeds two servers.
 */
abstract class UsageApiTestCase extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected const NOW_UTC = '2026-09-15 10:00:00';

    protected const TIMEZONE = 'Europe/Prague';

    protected int $now;

    protected function setUp(): void
    {
        parent::setUp();

        // SitesSchema first: its server table carries db_server.
        SitesSchema::create();
        MailCompletionSchema::create();
        DnsSchema::create();
        MonitorCompletionSchema::create();
        UsageSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        config(['app.timezone' => self::TIMEZONE]);
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));
        $this->now = Carbon::now()->getTimestamp();

        foreach ([1, 2] as $serverId) {
            DB::table('server')->insert([
                'server_id' => $serverId,
                'server_name' => 'server'.$serverId,
                'web_server' => 1,
                'mail_server' => 1,
                'db_server' => 1,
                'mirror_server_id' => 0,
                'active' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function site(string $owner, string $domain, array $attrs = []): int
    {
        return (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1,
            'domain' => $domain,
            'type' => 'vhost',
            'parent_domain_id' => 0,
            'system_user' => null,
            'hd_quota' => 0,
            'traffic_quota' => -1,
            'active' => 'y',
        ], $attrs)), 'domain_id');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function mailbox(string $owner, string $email, int $quota = 0, array $attrs = []): int
    {
        return (int) DB::table('mail_user')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1,
            'email' => $email,
            'login' => $email,
            'quota' => $quota,
        ], $attrs)), 'mailuser_id');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function database(string $owner, string $name, ?int $quota = null, array $attrs = []): int
    {
        return (int) DB::table('web_database')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1,
            'parent_domain_id' => 0,
            'type' => 'mysql',
            'database_name' => $name,
            'database_quota' => $quota,
        ], $attrs)), 'database_id');
    }

    /**
     * Seed a collector blob `$age` seconds old (arrays are serialize()d like the collectors do).
     *
     * @param  array<mixed>|string  $data
     */
    protected function blob(int $serverId, string $type, array|string $data, int $age = 60): void
    {
        DB::table('monitor_data')->insert([
            'server_id' => $serverId,
            'type' => $type,
            'created' => $this->now - $age,
            'data' => is_array($data) ? serialize($data) : $data,
            'state' => 'ok',
        ]);
    }

    protected function webTraffic(string $hostname, string $date, int $bytes): void
    {
        DB::table('web_traffic')->insert(['hostname' => $hostname, 'traffic_date' => $date, 'traffic_bytes' => $bytes]);
    }

    protected function mailTraffic(int $mailuserId, string $month, int $bytes): void
    {
        DB::table('mail_traffic')->insert(['mailuser_id' => $mailuserId, 'month' => $month, 'traffic' => $bytes]);
    }

    protected function getAs(string $tenant, string $uri): TestResponse
    {
        return $this->getJson('/api/v1'.$uri, $this->tenantHeaders($tenant));
    }

    /**
     * The ISO 8601 timestamp (API timezone) of a blob seeded `$age` seconds ago.
     */
    protected function iso(int $age): string
    {
        return Carbon::createFromTimestamp($this->now - $age, self::TIMEZONE)->toIso8601String();
    }
}
