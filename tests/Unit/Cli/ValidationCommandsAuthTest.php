<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sinergia\Application\Validation\EvidenceWriter;
use Sinergia\Cli\Command\CategoryCheckCommand;
use Sinergia\Cli\Command\HighlightsCheckCommand;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Infrastructure\Persistence\DiscoveryRunRepository;
use Sinergia\Infrastructure\Persistence\MlCategoryRepository;
use Sinergia\Integration\MercadoLivre\Category\CategoryService;
use Sinergia\Integration\MercadoLivre\Highlight\HighlightService;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Shared\Config\Config;
use Sinergia\Tests\Support\ArrayContainer;
use Sinergia\Tests\Support\FakeMercadoLivre;
use Sinergia\Tests\Support\InMemoryCategoryCache;
use Sinergia\Tests\Support\InMemoryDiscoveryRunLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * MVP 0: a validação real começa SEM token. Os comandos usam `--auth=none` por padrão e só
 * usam OAuth quando pedido explicitamente. Tudo aqui roda com HTTP falso (Guzzle MockHandler),
 * sem banco, sem credencial OAuth configurada e sem nenhuma chamada de rede real.
 */
final class ValidationCommandsAuthTest extends TestCase
{
    private string $evidenceDir;
    private FakeMercadoLivre $public;
    private FakeMercadoLivre $oauth;
    private InMemoryDiscoveryRunLog $runs;

    protected function setUp(): void
    {
        $this->evidenceDir = sys_get_temp_dir() . '/sinergia-cli-' . bin2hex(random_bytes(4));
        $this->public = new FakeMercadoLivre(withToken: false); // cliente sem token (modo none)
        $this->oauth = new FakeMercadoLivre(withToken: true);   // cliente com token (modo oauth)
        $this->runs = new InMemoryDiscoveryRunLog();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->evidenceDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->evidenceDir)) {
            rmdir($this->evidenceDir);
        }
    }

    public function testDefaultAuthOptionIsNoneOnBothValidationCommands(): void
    {
        $container = $this->container(withOAuthServices: false);

        self::assertSame('none', (new HighlightsCheckCommand($container))->getDefinition()->getOption('auth')->getDefault());
        self::assertSame('none', (new CategoryCheckCommand($container))->getDefinition()->getOption('auth')->getDefault());
    }

    public function testHighlightsWithoutAuthOptionRunsWithoutTokenAndWithoutOAuthCredentials(): void
    {
        // Sem serviços OAuth no container: se o comando tentasse OAuth, o container lançaria.
        $this->public->queueJson(200, FakeMercadoLivre::fixture('highlights_category.json'));
        $tester = new CommandTester(new HighlightsCheckCommand($this->container(withOAuthServices: false)));

        $exit = $tester->execute(['category_id' => 'MLB432825']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertCount(1, $this->public->history);
        self::assertFalse($this->public->lastRequest()->hasHeader('Authorization'), 'Modo none não envia token');
        self::assertSame([], $this->oauth->history, 'Cliente OAuth não pode ser usado');
        self::assertSame('none', $this->runs->runs[1]['auth_mode']);
        self::assertSame('succeeded', $this->runs->runs[1]['status']);
        self::assertStringContainsString('auth=none', $tester->getDisplay());
    }

    public function testHighlightsExplicitNoneRunsWithoutToken(): void
    {
        $this->public->queueJson(200, FakeMercadoLivre::fixture('highlights_category.json'));
        $tester = new CommandTester(new HighlightsCheckCommand($this->container(withOAuthServices: false)));

        $exit = $tester->execute(['category_id' => 'MLB432825', '--auth' => 'none']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertFalse($this->public->lastRequest()->hasHeader('Authorization'));
        self::assertSame('none', $this->runs->runs[1]['auth_mode']);
    }

    public function testHighlightsOfficial401WithoutTokenIsRecordedNotBypassed(): void
    {
        // Resposta oficial documentada para chamada sem token: deve ser registrada, sem tentar OAuth.
        $this->public->queueJson(401, ['message' => 'unspecified_token', 'error' => 'unspecified_token', 'status' => 401]);
        $tester = new CommandTester(new HighlightsCheckCommand($this->container(withOAuthServices: false)));

        $exit = $tester->execute(['category_id' => 'MLB432825']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertCount(1, $this->public->history, 'Uma única chamada, sem retentativa com token');
        self::assertSame([], $this->oauth->history);
        self::assertSame('failed', $this->runs->runs[1]['status']);
        self::assertSame(401, $this->runs->runs[1]['http_status']);
        self::assertSame('unspecified_token', $this->runs->runs[1]['error_code']);
    }

    public function testHighlightsExplicitOauthUsesTheOAuthClient(): void
    {
        $this->oauth->queueJson(200, FakeMercadoLivre::fixture('highlights_category.json'));
        $tester = new CommandTester(new HighlightsCheckCommand($this->container(withOAuthServices: true)));

        $exit = $tester->execute(['category_id' => 'MLB432825', '--auth' => 'oauth']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame([], $this->public->history, 'Cliente sem token não é usado em modo oauth');
        self::assertSame('Bearer ' . FakeMercadoLivre::TOKEN, $this->oauth->lastRequest()->getHeaderLine('Authorization'));
        self::assertSame('oauth', $this->runs->runs[1]['auth_mode']);
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $tester->getDisplay(), 'Token nunca aparece na saída');
    }

    public function testCategoryWithoutAuthOptionRunsWithoutToken(): void
    {
        $this->public->queueJson(200, FakeMercadoLivre::fixture('category_leaf.json'));
        $tester = new CommandTester(new CategoryCheckCommand($this->container(withOAuthServices: false)));

        $exit = $tester->execute(['category_id' => 'MLB900002']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertFalse($this->public->lastRequest()->hasHeader('Authorization'));
        self::assertSame([], $this->oauth->history);
        self::assertSame('none', $this->runs->runs[1]['auth_mode']);
    }

    public function testCategoryExplicitOauthUsesTheOAuthClient(): void
    {
        $this->oauth->queueJson(200, FakeMercadoLivre::fixture('category_leaf.json'));
        $tester = new CommandTester(new CategoryCheckCommand($this->container(withOAuthServices: true)));

        $exit = $tester->execute(['category_id' => 'MLB900002', '--auth' => 'oauth']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame([], $this->public->history);
        self::assertTrue($this->oauth->lastRequest()->hasHeader('Authorization'));
        self::assertSame('oauth', $this->runs->runs[1]['auth_mode']);
    }

    public function testInvalidAuthValueIsRejectedBeforeAnyRequest(): void
    {
        $tester = new CommandTester(new HighlightsCheckCommand($this->container(withOAuthServices: false)));

        $exit = $tester->execute(['category_id' => 'MLB432825', '--auth' => 'cookie']);

        self::assertSame(Command::INVALID, $exit);
        self::assertSame([], $this->public->history);
        self::assertSame([], $this->oauth->history);
        self::assertSame([], $this->runs->runs);
    }

    private function container(bool $withOAuthServices): ArrayContainer
    {
        // Config sem ML_CLIENT_*, sem APP_KEY e sem DB_*: modo none não pode exigir nada disso.
        $entries = [
            Config::class => Config::fromArray(['APP_ENV' => 'test']),
            Installation::class => new Installation(new InstallationId(1), 'default', 'Teste', 'active', 'MLB', 'UTC'),
            Clock::class => new FrozenClock('2026-09-23T12:00:00Z'),
            LoggerInterface::class => new NullLogger(),
            EvidenceWriter::class => new EvidenceWriter($this->evidenceDir),
            DiscoveryRunRepository::class => $this->runs,
            MlCategoryRepository::class => new InMemoryCategoryCache(),
            'ml.highlights.public' => new HighlightService($this->public->client),
            'ml.categories.public' => new CategoryService($this->public->client),
        ];
        if ($withOAuthServices) {
            $entries[HighlightService::class] = new HighlightService($this->oauth->client);
            $entries[CategoryService::class] = new CategoryService($this->oauth->client);
        }

        return new ArrayContainer($entries);
    }
}
