<?php

namespace Tests\Unit;

use App\Support\WordPressPolicy;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WordPressWorkerResponsivenessTest extends TestCase
{
    private function worker(PDO $db, ?PDO $local = null): \WordPressWorker
    {
        require_once dirname(__DIR__, 2).'/file-manager-worker/WordPressWorker.php';

        return new \WordPressWorker($db, $local ?? $db, 1);
    }

    private function disconnected(int $errno = 2006): PDO
    {
        return new class($errno) extends PDO
        {
            public function __construct(private int $errno) {}

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $error = new PDOException('Test connection failure');
                $error->errorInfo = ['HY000', $this->errno, 'Test connection failure'];
                throw $error;
            }
        };
    }

    public function test_idle_local_connection_is_reopened_before_reading_current_site_identity(): void
    {
        $db = new PDO('sqlite::memory:');
        $this->worker($db);
        $db->exec('CREATE TABLE web_domain (domain_id INTEGER, server_id INTEGER, domain TEXT)');
        $db->exec("INSERT INTO web_domain VALUES (19,1,'current-owner.test')");
        foreach ([2006, 2013, 2055] as $errno) {
            $connects = 0;
            $worker = new \WordPressWorker($db, $this->disconnected($errno), 1, function () use ($db, &$connects) {
                $connects++;

                return $db;
            });
            $read = new ReflectionMethod($worker, 'localOne');
            $row = $read->invoke($worker, 'SELECT * FROM web_domain WHERE domain_id=? AND server_id=?', [19, 1]);
            self::assertSame('current-owner.test', $row['domain']);
            self::assertSame(1, $connects);
            self::assertNull($read->invoke($worker, 'SELECT * FROM web_domain WHERE domain_id=? AND server_id=?', [19, 2]));
            self::assertSame(1, $connects);
        }
    }

    public function test_local_read_retry_is_bounded_and_does_not_hide_permissions_or_sql_errors(): void
    {
        $db = new PDO('sqlite::memory:');
        $this->worker($db);
        foreach ([2006 => 1, 1142 => 0] as $errno => $expected) {
            $connects = 0;
            $worker = new \WordPressWorker($db, $this->disconnected($errno), 1, function () use (&$connects) {
                $connects++;

                return $this->disconnected();
            });
            try {
                (new ReflectionMethod($worker, 'localOne'))->invoke($worker, 'SELECT * FROM web_domain WHERE domain_id=?', [19]);
                self::fail('Connection failure must propagate');
            } catch (PDOException $e) {
                self::assertSame($expected, $connects);
            }
        }
    }

    public function test_apply_and_revert_wait_for_the_live_installation_block(): void
    {
        $worker = $this->worker(new PDO('sqlite::memory:'));
        $states = new ReflectionMethod($worker, 'serverStates');
        $block = WordPressPolicy::compile('blog', ['xmlrpc', 'indexes']);
        self::assertSame('pending', $states->invoke($worker, 'blog', ['xmlrpc', 'indexes'], '')['xmlrpc']['status']);
        self::assertSame('ok', $states->invoke($worker, 'blog', ['xmlrpc', 'indexes'], $block.$block)['xmlrpc']['status'], 'HTTP and HTTPS can contain identical blocks');
        self::assertSame('pending', $states->invoke($worker, 'blog', [], $block)['xmlrpc']['status'], 'Revert is not finished while the old rule is live');
        self::assertSame('warning', $states->invoke($worker, 'blog', [], '')['xmlrpc']['status']);
        self::assertSame('pending', $states->invoke($worker, 'other', ['xmlrpc', 'indexes'], $block)['xmlrpc']['status'], 'Another installation cannot satisfy the check');
        self::assertSame('warning', $states->invoke($worker, 'other', [], $block)['xmlrpc']['status']);
        $partial = $states->invoke($worker, 'blog', ['indexes'], $block);
        self::assertSame('pending', $partial['indexes']['status']);
        self::assertSame('pending', $partial['xmlrpc']['status']);
        $applied = $states->invoke($worker, 'blog', ['indexes'], WordPressPolicy::compile('blog', ['indexes']));
        self::assertSame('ok', $applied['indexes']['status']);
        self::assertSame('warning', $applied['xmlrpc']['status']);
    }

    public function test_interactive_jobs_precede_background_work_and_deferred_jobs_do_not_block_other_sites(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE api_wordpress_jobs (id TEXT, server_id INTEGER, status TEXT, action TEXT, created_at INTEGER)');
        $db->exec("INSERT INTO api_wordpress_jobs VALUES ('scan',1,'queued','rescan',1),('cron',1,'queued','cron_run',2),('check',1,'queued','check',3),('secure',1,'queued','secure',4),('remote',2,'running','secure',0)");
        $worker = $this->worker($db);
        $next = new ReflectionMethod($worker, 'nextJob');
        self::assertSame('check', $next->invoke($worker, [])['id']);
        self::assertSame('secure', $next->invoke($worker, ['check'])['id']);
        $db->exec("UPDATE api_wordpress_jobs SET status='running' WHERE id='scan'");
        self::assertSame('scan', $next->invoke($worker, [])['id'], 'Resume interrupted work before starting another operation');
        self::assertNull($next->invoke($worker, ['check', 'secure', 'scan', 'cron']));
    }

    public function test_background_refresh_preserves_private_state_and_skips_changed_identity_and_busy_sites(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE api_wordpress_jobs (id TEXT, website_id INTEGER, status TEXT)');
        $db->exec('CREATE TABLE api_wordpress_sites (website_id INTEGER, server_id INTEGER, identity TEXT, installations TEXT)');
        $db->exec('CREATE TABLE web_domain (domain_id INTEGER, server_id INTEGER, sys_groupid INTEGER, domain TEXT, system_user TEXT, system_group TEXT, document_root TEXT, web_folder TEXT, active TEXT, type TEXT, apache_directives TEXT)');
        $db->exec('CREATE TABLE sys_group (groupid INTEGER, client_id INTEGER)');
        $db->exec('CREATE TABLE client (client_id INTEGER, locked TEXT)');
        $db->exec('CREATE TABLE server (server_id INTEGER, config TEXT)');
        $db->exec("INSERT INTO sys_group VALUES (19,19); INSERT INTO client VALUES (19,'n'); INSERT INTO server VALUES (1,'[web]\nserver_type=apache');");
        $original = [['path' => 'blog', 'checked_at' => '2020-01-01T00:00:00Z', 'undo' => ['private' => 'keep'], 'security' => ['xmlrpc' => ['status' => 'pending'], 'prefix' => ['status' => 'warning']]]];
        for ($id = 1; $id <= 3; $id++) {
            $site = ['domain_id' => $id, 'server_id' => 1, 'sys_groupid' => 19, 'domain' => 'speed-test-'.$id.'.invalid', 'system_user' => 'web19', 'system_group' => 'client19', 'document_root' => '/var/www/example', 'web_folder' => '', 'active' => 'y', 'type' => 'vhost', 'apache_directives' => ''];
            $db->prepare('INSERT INTO web_domain VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($site));
            $db->prepare('INSERT INTO api_wordpress_sites VALUES (?,?,?,?)')->execute([$id, 1, WordPressPolicy::identity($site, '/var/www/example/web'), json_encode($original)]);
        }
        $db->exec("INSERT INTO api_wordpress_jobs VALUES ('busy',2,'queued'); UPDATE web_domain SET domain='changed.invalid' WHERE domain_id=3");
        (new ReflectionMethod($this->worker($db), 'refreshPending'))->invoke($this->worker($db));
        $rows = $db->query('SELECT installations FROM api_wordpress_sites ORDER BY website_id')->fetchAll(PDO::FETCH_COLUMN);
        $updated = json_decode($rows[0], true);
        self::assertSame('warning', $updated[0]['security']['xmlrpc']['status']);
        self::assertSame($original[0]['undo'], $updated[0]['undo']);
        self::assertSame($original[0]['security']['prefix'], $updated[0]['security']['prefix']);
        self::assertSame($original, json_decode($rows[1], true));
        self::assertSame($original, json_decode($rows[2], true));
    }
}
