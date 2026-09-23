<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Direção de dependências da arquitetura V1 (verificada pelos "use" de cada arquivo):
 *  - Domain não depende de nenhuma outra camada;
 *  - Application depende só de Domain, Shared e das próprias portas (Application\Port);
 *  - Integration e Infrastructure implementam portas e não dependem uma da outra.
 * A composição concreta fica apenas nos entrypoints (Kernel, Cli, Web).
 */
final class LayerDependencyTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function rules(): iterable
    {
        yield 'Domain isolado' => ['Domain', ['Application', 'Integration', 'Infrastructure', 'Web', 'Cli', 'Kernel']];
        yield 'Application sem adaptadores' => ['Application', ['Integration', 'Infrastructure', 'Web', 'Cli', 'Kernel']];
        yield 'Integration sem Infrastructure' => ['Integration', ['Infrastructure', 'Web', 'Cli', 'Kernel']];
        yield 'Infrastructure sem Integration' => ['Infrastructure', ['Integration', 'Web', 'Cli', 'Kernel']];
        yield 'Shared sem camadas' => ['Shared', ['Domain', 'Application', 'Integration', 'Infrastructure', 'Web', 'Cli', 'Kernel']];
    }

    /** @param list<string> $forbidden */
    #[\PHPUnit\Framework\Attributes\DataProvider('rules')]
    public function testLayerDoesNotImportForbiddenLayers(string $layer, array $forbidden): void
    {
        $dir = dirname(__DIR__, 3) . '/src/' . $layer;
        self::assertDirectoryExists($dir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/^use\s+Sinergia\\\\([A-Za-z]+)[\\\\;]/m', (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $target) {
                if (in_array($target, $forbidden, true)) {
                    $violations[] = sprintf('%s importa Sinergia\\%s', $file->getFilename(), $target);
                }
            }
        }

        self::assertSame([], $violations, "Dependências proibidas:\n" . implode("\n", $violations));
    }
}
