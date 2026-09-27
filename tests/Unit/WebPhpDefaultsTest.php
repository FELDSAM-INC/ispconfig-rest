<?php

namespace Tests\Unit;

use App\Support\WebPhpDefaults;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebPhpDefaultsTest extends TestCase
{
    public function test_only_allowed_values_are_parsed_without_evaluating_expressions_or_environment(): void
    {
        $values = WebPhpDefaults::values("[PHP]\nmemory_limit=1024M\nerror_reporting=E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED\n"
            ."display_errors=Off\nlog_errors=On\ndisable_functions=exec,shell_exec\nDB_PASSWORD=secret\nauto_prepend_file=/private/secret\n");
        self::assertSame(['memory_limit' => '1024M', 'disable_functions' => 'exec,shell_exec',
            'error_reporting' => 'E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED', 'display_errors' => 'off', 'log_errors' => 'on'], $values);
        self::assertSame(['memory_limit' => '${HOME}'], WebPhpDefaults::values('memory_limit=${HOME}'));
        self::assertSame([], WebPhpDefaults::values('; no overrides'));
    }

    public function test_per_path_overrides_cannot_be_misrepresented_as_website_wide_settings(): void
    {
        $this->expectException(RuntimeException::class);
        WebPhpDefaults::values("memory_limit=128M\n[PATH=/private]\nmemory_limit=2G");
    }

    public function test_array_values_cannot_be_returned_as_setting_strings(): void
    {
        $this->expectException(RuntimeException::class);
        WebPhpDefaults::values('memory_limit[]=1');
    }

    public function test_missing_files_fail_closed(): void
    {
        $this->expectException(RuntimeException::class);
        WebPhpDefaults::collect('/nonexistent/ispconfig-php-fixture');
    }
}
