<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Sinergia\Shared\Config\SensitiveValue;

final class SensitiveValueTest extends TestCase
{
    private const string SECRET = 'super-secret-value-123';

    public function testNeverLeaksThroughCommonOutputPaths(): void
    {
        $value = new SensitiveValue(self::SECRET);

        self::assertSame(self::SECRET, $value->reveal());
        self::assertStringNotContainsString(self::SECRET, (string) $value);
        self::assertStringNotContainsString(self::SECRET, print_r($value, true));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode(['v' => $value]));
        self::assertStringNotContainsString(self::SECRET, sprintf('%s', $value));

        ob_start();
        var_dump($value);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString(self::SECRET, $dump);
    }

    public function testCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new SensitiveValue(self::SECRET));
    }

    public function testConstantTimeEquality(): void
    {
        self::assertTrue((new SensitiveValue('a'))->equals(new SensitiveValue('a')));
        self::assertFalse((new SensitiveValue('a'))->equals(new SensitiveValue('b')));
    }
}
