<?php

namespace Tests\Unit;

use App\Support\WebDomainAutoalias;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WordPressVerificationHostsTest extends TestCase
{
    public function test_worker_uses_the_website_owner_for_preview_placeholders(): void
    {
        require_once dirname(__DIR__, 2).'/file-manager-worker/WordPressWorker.php';
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE sys_group (groupid INTEGER, client_id INTEGER)');
        $db->exec('CREATE TABLE client (client_id INTEGER, username TEXT)');
        $db->exec('INSERT INTO sys_group VALUES (19,42),(20,43)');
        $db->exec("INSERT INTO client VALUES (42,'owner'),(43,'other')");
        $worker = new \WordPressWorker($db, $db, 1);
        $method = new ReflectionMethod($worker, 'verificationHosts');
        $site = ['domain_id' => 19, 'domain' => 'example.test', 'sys_groupid' => 19];
        foreach (['[website_domain].preview.test', '[client_username]-[client_id]-[website_id].preview.test'] as $pattern) {
            $alias = WebDomainAutoalias::resolve($pattern, $site, 42, 'owner');
            self::assertSame(['example.test', 'www.example.test', $alias], $method->invoke($worker, $site, $pattern));
        }
        foreach (['', '*.preview.test', '[unknown].preview.test', 'https://preview.test', 'preview.test other.test'] as $pattern) {
            self::assertSame(['example.test', 'www.example.test'], $method->invoke($worker, $site, $pattern));
        }
    }
}
