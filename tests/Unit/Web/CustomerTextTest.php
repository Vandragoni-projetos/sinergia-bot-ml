<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;

/** Texto visível ao cliente não fala de etapas/fases do desenvolvimento (comentários Twig não contam). */
final class CustomerTextTest extends TestCase
{
    private const array FORBIDDEN = [
        '/\betapas?\s+\d/iu',
        '/\bF\d\b/u',
        '/\bchega\s+na\b/iu',
        '/\bem\s+breve\b/iu',
        '/\b(TODO|FIXME)\b/u',
    ];

    public function testTemplatesHaveNoDevelopmentWording(): void
    {
        $files = array_merge((array) glob(dirname(__DIR__, 3) . '/templates/*.twig'), (array) glob(dirname(__DIR__, 3) . '/templates/*/*.twig'));
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $text = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents((string) $file));
            foreach (self::FORBIDDEN as $pattern) {
                self::assertDoesNotMatchRegularExpression($pattern, $text, basename((string) $file));
            }
        }
    }
}
