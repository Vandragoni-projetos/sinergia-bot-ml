<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Garante, a cada execução da suíte, que o projeto continua independente:
 *  - nenhuma referência a produtos/fornecedores anteriores nem a mecanismos de licença deles;
 *  - nenhum uso de mecanismos proibidos (gerador de links interno, CSRF de sessão, páginas raspadas);
 *  - nenhuma URL fora dos hosts oficiais da API;
 *  - nenhum campo de "vendas" fabricado.
 *
 * Termos de terceiros NÃO são publicados neste repositório: o teste guarda apenas o SHA-256
 * de cada termo normalizado (minúsculas, somente [a-z0-9_]) e procura, em cada arquivo
 * normalizado, qualquer trecho com o mesmo tamanho e o mesmo hash. Assim a detecção continua
 * valendo mesmo com espaços, pontos, hífens ou maiúsculas no meio do termo.
 */
final class IndependenceTest extends TestCase
{
    /** Tamanho do termo normalizado => lista de SHA-256 (termos de terceiros, não publicados). */
    private const array THIRD_PARTY_TERM_HASHES = [
        10 => ['dacff8c80cd586eb66a4f89b0e90796c3df5e97836e12849ccad6d85a65e9755'],
        12 => [
            'fa1846851cbe69578b6ce7c6cf755f3bb2525c4adb3dd91cfd0238443a8a7ba1',
            'a58f6396db69c8cecf18cd7e15806c0c9b30b5be5471b720e58a8d9937a66748',
        ],
        13 => ['a01bebd764681844ddbb4fcabca19ca7549ff896ecf4b973a18b9d2f4a340919'],
        14 => [
            '8018f67330736207db79da7063f228805239c5b720da1038ef514e2e084bdbc2',
            '5ab4df6b52f6cdd85c00891f9e8055f75b666f1e53b7650672169cefd471926f',
            '759ce14ae43e7c4a74d370b507677f091776ba64a96d7ce8c7c0bde846989f1f',
        ],
    ];

    /** Mecanismos proibidos pela especificação (termos técnicos neutros, podem ser legíveis). */
    private const array FORBIDDEN_MECHANISMS = [
        'createlink',                  // endpoint interno do gerador de links
        'linkbuilder',
        'x-csrf-token',                // CSRF de sessão do navegador
        'affiliate-program/api',
        'mercadolivre.com.br/ofertas', // página pública (proibido raspar)
        'lista.mercadolivre',          // página pública de busca (proibido raspar)
        'sold_quantity',               // só disponível ao dono do anúncio
        'estimated_sales',
        'vendas_estimadas',
    ];

    /** Onde um termo técnico pode aparecer só para ser BLOQUEADO/MASCARADO, nunca usado. */
    private const array DEFENSIVE_ALLOWLIST = [
        'src/Shared/Security/Redactor.php' => ['x-csrf-token'],
    ];

    private const array ALL_DIRS = ['src', 'public', 'bin', 'config', 'database', 'docs', 'docker', 'tests', '.github'];
    private const array CODE_DIRS = ['src', 'public', 'bin', 'config', 'database'];
    private const array ROOT_FILES = ['README.md', 'composer.json', '.env.example', 'Dockerfile', 'docker-compose.yml', '.gitignore', '.dockerignore'];

    public function testNoThirdPartyProductOrVendorReference(): void
    {
        $hits = [];
        foreach ($this->files(self::ALL_DIRS, withRootFiles: true) as $file) {
            foreach (self::matchedTermIndexes((string) file_get_contents($file), self::THIRD_PARTY_TERM_HASHES) as $index) {
                $hits[] = $this->relative($file) . ' contém o termo proibido #' . $index;
            }
        }

        self::assertSame([], $hits, "Referências a terceiros encontradas:\n" . implode("\n", $hits));
    }

    public function testHashMatcherDetectsObfuscatedVariants(): void
    {
        // Autoteste do mecanismo com um termo sintético neutro (não é nome de terceiro).
        $hashes = [15 => [hash('sha256', 'marcador_neutro')]];

        self::assertSame([1], self::matchedTermIndexes('x = "Marcador_Neutro";', $hashes));
        self::assertSame([1], self::matchedTermIndexes('https://sub.marcador_neutro.example', $hashes));
        self::assertSame([1], self::matchedTermIndexes('MARCADOR_NEUTRO', $hashes));
        self::assertSame([1], self::matchedTermIndexes('prefixo_marcador_neutro_sufixo', $hashes));
        self::assertSame([], self::matchedTermIndexes('marcador neutral', $hashes));
    }

    public function testNoForbiddenMechanismsInCode(): void
    {
        $hits = [];
        foreach ($this->files(self::CODE_DIRS) as $file) {
            $relative = str_replace('\\', '/', $this->relative($file));
            $content = strtolower((string) file_get_contents($file));
            foreach (self::FORBIDDEN_MECHANISMS as $term) {
                if (in_array($term, self::DEFENSIVE_ALLOWLIST[$relative] ?? [], true)) {
                    continue;
                }
                if (str_contains($content, $term)) {
                    $hits[] = $relative . ' contém "' . $term . '"';
                }
            }
        }

        self::assertSame([], $hits, "Mecanismos proibidos encontrados:\n" . implode("\n", $hits));
    }

    public function testOnlyOfficialApiHostsInProductionCode(): void
    {
        $allowed = ['api.mercadolibre.com', 'auth.mercadolivre.com.br', 'example.invalid'];
        $hits = [];
        foreach ($this->files(self::CODE_DIRS) as $file) {
            preg_match_all('#https?://([A-Za-z0-9.\-]+)#', (string) file_get_contents($file), $m);
            foreach (array_unique($m[1]) as $host) {
                if (!in_array(strtolower($host), $allowed, true)) {
                    $hits[] = $this->relative($file) . ' -> ' . $host;
                }
            }
        }

        self::assertSame([], $hits, "Hosts não permitidos:\n" . implode("\n", $hits));
    }

    /**
     * @param array<int, list<string>> $hashesByLength
     * @return list<int> índices (1-based, na ordem de declaração) dos termos encontrados
     */
    private static function matchedTermIndexes(string $content, array $hashesByLength): array
    {
        $normalized = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($content));
        $index = 0;
        $lookup = [];
        foreach ($hashesByLength as $length => $hashes) {
            foreach ($hashes as $hash) {
                $lookup[$length][$hash] = ++$index;
            }
        }

        $found = [];
        $size = strlen($normalized);
        foreach ($lookup as $length => $byHash) {
            for ($i = 0; $i + $length <= $size; $i++) {
                $hash = hash('sha256', substr($normalized, $i, $length));
                if (isset($byHash[$hash])) {
                    $found[$byHash[$hash]] = true;
                }
            }
        }
        $result = array_keys($found);
        sort($result);

        return $result;
    }

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
    private function files(array $dirs, bool $withRootFiles = false): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach ($dirs as $dir) {
            $path = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }
        if ($withRootFiles) {
            foreach (self::ROOT_FILES as $name) {
                if (is_file($root . DIRECTORY_SEPARATOR . $name)) {
                    $files[] = $root . DIRECTORY_SEPARATOR . $name;
                }
            }
        }

        return $files;
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(dirname(__DIR__, 3), '', $file), '/\\');
    }
}
