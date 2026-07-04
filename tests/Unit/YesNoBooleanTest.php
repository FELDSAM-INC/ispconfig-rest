<?php

namespace Tests\Unit;

use App\Casts\YesNoBoolean;
use PHPUnit\Framework\TestCase;

class YesNoBooleanTest extends TestCase
{
    private function cast(string $case = 'lower'): YesNoBoolean
    {
        return new YesNoBoolean($case);
    }

    public function test_reads_stored_values_as_booleans(): void
    {
        $model = new \stdClass;

        $this->assertTrue($this->cast()->get($model, 'f', 'y', []));
        $this->assertTrue($this->cast()->get($model, 'f', 'Y', []));
        $this->assertFalse($this->cast()->get($model, 'f', 'n', []));
        $this->assertFalse($this->cast()->get($model, 'f', 'N', []));
        $this->assertFalse($this->cast()->get($model, 'f', null, []));
    }

    public function test_writes_lowercase_by_default(): void
    {
        $model = new \stdClass;

        $this->assertSame('y', $this->cast()->set($model, 'f', true, []));
        $this->assertSame('n', $this->cast()->set($model, 'f', false, []));
    }

    public function test_writes_uppercase_when_configured(): void
    {
        $model = new \stdClass;

        $this->assertSame('Y', $this->cast('upper')->set($model, 'f', true, []));
        $this->assertSame('N', $this->cast('upper')->set($model, 'f', false, []));
    }

    public function test_accepts_legacy_string_input(): void
    {
        $model = new \stdClass;

        $this->assertSame('y', $this->cast()->set($model, 'f', 'yes', []));
        $this->assertSame('n', $this->cast()->set($model, 'f', 'no', []));
        $this->assertNull($this->cast()->set($model, 'f', null, []));
    }
}
