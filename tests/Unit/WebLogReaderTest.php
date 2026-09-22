<?php

namespace Tests\Unit;

use App\Support\WebLogReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebLogReaderTest extends TestCase
{
    private string $root;

    private WebLogReader $reader;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/web-logs-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/example.test', 0700, true);
        $this->reader = new WebLogReader($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/example.test/*') as $path) {
            unlink($path);
        }
        rmdir($this->root.'/example.test');
        foreach (glob($this->root.'/*') as $path) {
            is_link($path) || is_file($path) ? unlink($path) : rmdir($path);
        }
        rmdir($this->root);
    }

    private function file(string $file, string $text): void
    {
        file_put_contents($this->root.'/example.test/'.$file, $text);
    }

    public function test_pages_have_no_gaps_and_traverse_gzip_archive(): void
    {
        $this->file('error.log', "one\ntwo\nthree\nfour\nfive\n");
        $this->file('error.log.1.gz', gzencode("old-one\nold-two\n"));
        $one = $this->reader->read('example.test', 'error', 2);
        self::assertSame("four\nfive\n", $one['text']);
        $two = $this->reader->read('example.test', 'error', 2, $one['before']);
        self::assertSame("two\nthree\n", $two['text']);
        $three = $this->reader->read('example.test', 'error', 2, $two['before']);
        self::assertSame("one\n", $three['text']);
        $old = $this->reader->read('example.test', 'error', 2, $three['before']);
        self::assertSame("old-one\nold-two\n", $old['text']);
        self::assertNull($old['before']);
    }

    public function test_apache_access_symlink_is_not_duplicated(): void
    {
        $this->file('20260922-access.log', "today\n");
        $this->file('20260921-access.log.gz', gzencode("yesterday\n"));
        symlink('20260922-access.log', $this->root.'/example.test/access.log');
        $first = $this->reader->read('example.test', 'access');
        self::assertSame("today\n", $first['text']);
        self::assertSame("yesterday\n", $this->reader->read('example.test', 'access', 200, $first['before'])['text']);
    }

    public function test_cross_domain_symlink_is_never_read(): void
    {
        mkdir($this->root.'/foreign.test');
        file_put_contents($this->root.'/outside', 'SECRET');
        symlink($this->root.'/outside', $this->root.'/example.test/error.log');
        self::assertSame('', $this->reader->read('example.test', 'error')['text']);
        symlink('example.test', $this->root.'/alias.test');
        self::assertFalse($this->reader->available('alias.test'));
        self::assertFalse($this->reader->available('../example.test'));
    }

    public function test_copytruncate_with_regrowth_resets_cursor(): void
    {
        $this->file('error.log', "one\ntwo\nthree\n");
        $first = $this->reader->read('example.test', 'error', 1);
        $this->file('error.log', str_repeat("new\n", 100));
        clearstatcache();
        $new = $this->reader->read('example.test', 'error', 1, $first['before']);
        self::assertTrue($new['rotated']);
        self::assertSame("new\n", $new['text']);
    }

    public function test_huge_line_and_binary_content_are_bounded(): void
    {
        $this->file('access.log', str_repeat('x', 1048576)."\xff");
        $result = $this->reader->read('example.test', 'access', 1000);
        self::assertTrue($result['truncated']);
        self::assertLessThanOrEqual(WebLogReader::MAX_BYTES + 4, strlen($result['text']));
        self::assertNotFalse(json_encode($result));
    }

    public function test_large_plain_log_is_seeked_without_loading_it_into_memory(): void
    {
        $file = fopen($this->root.'/example.test/access.log', 'wb');
        ftruncate($file, 2147483648);
        fseek($file, 2147483600);
        fwrite($file, "\none\ntwo\nthree\n");
        fclose($file);
        // Ignore the sparse tail after the last newline by truncating to what was written.
        $file = fopen($this->root.'/example.test/access.log', 'c');
        ftruncate($file, 2147483600 + strlen("\none\ntwo\nthree\n"));
        fclose($file);
        self::assertSame("two\nthree\n", $this->reader->read('example.test', 'access', 2)['text']);
    }

    public function test_empty_and_non_log_files(): void
    {
        $this->file('passwords.txt', 'SECRET');
        $this->file('error.log', '');
        self::assertSame('', $this->reader->read('example.test', 'error')['text']);
        self::assertNull($this->reader->read('example.test', 'error')['before']);
    }

    public function test_access_and_error_selectors_only(): void
    {
        $this->expectException(RuntimeException::class);
        $this->reader->read('example.test', '../error');
    }
}
