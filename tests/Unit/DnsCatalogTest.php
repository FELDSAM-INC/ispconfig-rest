<?php

namespace Tests\Unit;

use App\Support\DnsCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DnsCatalogTest extends TestCase
{
    /** `named-checkconf -p` of an ISPConfig named.conf.local (BIND 9.18) */
    private const LOCAL = <<<'CONF'
zone "Test.cz" {
	type master;
	file "/etc/bind/pri.test.cz";
};
zone "b.test" {
	type master;
	file "/etc/bind/pri.b.test";
	allow-transfer  {
		192.0.2.1/32;
	};
	also-notify {
		192.0.2.2;
	};
};
zone "c.test" IN {
	type primary;
	file "/etc/bind/pri.c.test";
};
zone "alias.test.sk" {
	type slave;
	file "/etc/bind/slave/sec.alias.test.sk";
	masters {
		203.0.113.1;
	};
};
zone "catalog.ns1.example.invalid" {
	type primary;
	file "/var/lib/bind/ispcp-catalog.db";
};
CONF;

    public function test_members_are_primary_zones_without_their_own_transfer_list(): void
    {
        $this->assertSame(['members' => ['c.test', 'test.cz'], 'excluded' => ['b.test']], DnsCatalog::zones(self::LOCAL, 'catalog.ns1.example.invalid.'));
        $this->assertSame([], DnsCatalog::zones('', 'x')['members']);
    }

    public function test_names_and_member_ids(): void
    {
        $this->assertSame('example.com', DnsCatalog::name('Example.COM.'));
        $this->assertSame('_dmarc.example.com', DnsCatalog::name('_dmarc.example.com'));
        foreach (['', '.', 'a..b', '-a.b', 'a b', 'ex"ample'] as $invalid) {
            $this->assertNull(DnsCatalog::name($invalid), $invalid);
        }
        $this->assertSame(sha1('example.com.'), DnsCatalog::memberId('example.com'));
    }

    public function test_rendered_catalog_reads_back_and_passes_schema_version_two(): void
    {
        $text = DnsCatalog::render('catalog.ns1.example.invalid', ['b.test', 'a.test'], 1790000000);
        $this->assertStringContainsString("\$ORIGIN catalog.ns1.example.invalid.\n", $text);
        $this->assertStringContainsString("version IN TXT \"2\"\n", $text);
        $this->assertStringContainsString(sha1('a.test.').'.zones IN PTR a.test.', $text);
        $this->assertSame(['serial' => 1790000000, 'members' => ['a.test', 'b.test']], DnsCatalog::current($text));
        $this->assertSame(['serial' => null, 'members' => []], DnsCatalog::current(''));
        $this->assertSame(1790000001, DnsCatalog::nextSerial(1790000000, 1789999999), 'never backwards');
        $this->assertSame(1790000500, DnsCatalog::nextSerial(1790000000, 1790000500));
        $this->assertSame(1790000500, DnsCatalog::nextSerial(null, 1790000500));
        $this->expectException(RuntimeException::class);
        DnsCatalog::nextSerial(4294967295, 0);
    }

    public function test_catalog_configuration_transfers_by_key_and_notifies_registered_secondaries(): void
    {
        $this->assertStringContainsString("\tallow-transfer { key \"ispcp-catalog.ns1.example\"; };\n\tnotify no;\n", DnsCatalog::configuration('catalog.ns1.example.invalid', 'ispcp-catalog.ns1.example', []));
        $this->assertStringContainsString("\tnotify explicit;\n\talso-notify { 192.0.2.53 key \"k\"; 2001:db8::53 key \"k\"; };\n", DnsCatalog::configuration('c.invalid', 'k', ['192.0.2.53', '2001:db8::53']));
    }

    public function test_global_transfer_list_gets_the_key_once(): void
    {
        $ispconfig = "options {\n\tdirectory \"/var/cache/bind\";\n\tversion \"unknown\";\n\n\tallow-transfer {none;};\n\n\tauth-nxdomain no;    # conform to RFC1035\n};\n";
        $edited = DnsCatalog::allowTransfer($ispconfig, 'k');
        $this->assertStringContainsString("\tallow-transfer { key \"k\"; };\n", $edited);
        $this->assertNull(DnsCatalog::allowTransfer($edited, 'k'));
        $this->assertStringContainsString('allow-transfer { key "k"; 192.0.2.1; 192.0.2.2; };', DnsCatalog::allowTransfer("options {\n\tallow-transfer { 192.0.2.1; 192.0.2.2; };\n};\n", 'k'));
        $this->assertStringContainsString("options {\n\tallow-transfer { key \"k\"; };\n\tdirectory", DnsCatalog::allowTransfer("options {\n\tdirectory \"/var/cache/bind\";\n};\n", 'k'));
        // A commented example is not the statement
        $commented = "options {\n\t// allow-transfer { 10.0.0.1; };\n\tallow-transfer { none; };\n};\n";
        $this->assertSame("options {\n\t// allow-transfer { 10.0.0.1; };\n\tallow-transfer { key \"k\"; };\n};\n", DnsCatalog::allowTransfer($commented, 'k'));
        $this->expectException(RuntimeException::class);
        DnsCatalog::allowTransfer('zone "x" { type master; };', 'k');
    }

    public function test_managed_also_notify_line_follows_the_registered_secondaries(): void
    {
        $options = "options {\n\tdirectory \"/var/cache/bind\";\n};\n";
        $added = DnsCatalog::alsoNotify($options, ['198.51.100.20', '2001:db8::20'], 'k');
        $this->assertSame("options {\n\talso-notify { 198.51.100.20 key \"k\"; 2001:db8::20 key \"k\"; }; // ispconfig-dns-catalog\n\tdirectory \"/var/cache/bind\";\n};\n", $added);
        $this->assertNull(DnsCatalog::alsoNotify($added, ['198.51.100.20', '2001:db8::20'], 'k'));
        $this->assertSame($options, DnsCatalog::alsoNotify($added, [], 'k'), 'the last secondary removed');
        $this->assertFalse(DnsCatalog::alsoNotify("options {\n\talso-notify { 10.0.0.1; };\n};\n", ['198.51.100.20'], 'k'), 'the administrator keeps an own list');
        $this->assertNull(DnsCatalog::alsoNotify("options {\n\t// also-notify { 10.0.0.1; };\n};\n", [], 'k'));
    }

    public function test_named_conf_includes_the_catalog_once(): void
    {
        $named = "include \"/etc/bind/named.conf.options\";\ninclude \"/etc/bind/named.conf.local\";\n";
        $with = DnsCatalog::withInclude($named);
        $this->assertStringEndsWith(DnsCatalog::INCLUDE."\n", $with);
        $this->assertNull(DnsCatalog::withInclude($with));
    }
}
