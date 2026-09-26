<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\AI;

use GuzzleHttp\Psr7\HttpFactory;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Port\Copy\OfferCopyFailure;
use Sinergia\Application\Port\Copy\OfferCopyRequest;
use Sinergia\Application\Queue\CopyValidator;
use Sinergia\Application\Queue\MessageBuilder;
use Sinergia\Application\Queue\OfferMessageComposer;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Integration\AI\FixedOfferCopyGenerator;
use Sinergia\Integration\AI\OpenAI\OpenAIOfferCopyGenerator;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\OpenAIConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeOpenAIServer;

/**
 * Etapa 11B: composição v2 (copy validada + dados do BotML) e fallback v1 em QUALQUER falha da IA.
 * OpenAI FALSA: nenhuma chamada real, chave fictícia.
 */
final class OfferCopyTest extends TestCase
{
    private const string TITLE = 'Air Fryer Mondial 4L Preta';
    private const string LINK = 'https://meli.la/2sWvfQU?x=1&y=_z';
    private const string V1 = "Air Fryer Mondial 4L Preta\n\nDe R$ 249,90 por R$ 179,90 (28% OFF)\n\nhttps://meli.la/2sWvfQU?x=1&y=_z";

    private FakeOpenAIServer $openai;
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->openai = new FakeOpenAIServer();
        $this->logs = new TestHandler();
    }

    private function generator(?string $key = FakeOpenAIServer::API_KEY): OpenAIOfferCopyGenerator
    {
        return new OpenAIOfferCopyGenerator(
            $this->openai->client(),
            new HttpFactory(),
            new HttpFactory(),
            new OpenAIConfig('https://api.openai.com/v1', $key === null ? null : new SensitiveValue($key), 'gpt-4.1-mini', 10),
        );
    }

    private function composer(?OpenAIOfferCopyGenerator $generator = null, bool $fixed = false): OfferMessageComposer
    {
        return new OfferMessageComposer(
            $fixed ? new FixedOfferCopyGenerator() : ($generator ?? $this->generator()),
            new CopyValidator(),
            new MessageBuilder(),
            LoggerFactory::create('local', $this->logs),
        );
    }

    private function request(bool $freeShipping = true): OfferCopyRequest
    {
        return new OfferCopyRequest(self::TITLE, 'Casa e Cozinha', 'Air fryers', $freeShipping, false);
    }

    private function compose(OfferMessageComposer $composer, bool $freeShipping = true): \Sinergia\Application\Queue\ComposedMessage
    {
        return $composer->compose(new InstallationId(7), 42, $this->request($freeShipping), 17990, 24990, self::LINK);
    }

    public function testV2KeepsTitlePricesDiscountAndLinkFromTheBotAndOnlyAddsValidatedCopy(): void
    {
        $this->openai->copy('Batata crocante sem óleo 🍟', 'Toque no link e confira 👇');

        $message = $this->compose($this->composer());

        self::assertSame(
            "Batata crocante sem óleo 🍟\n\nAir Fryer Mondial 4L Preta\n\nDe R$ 249,90 por R$ 179,90 (28% OFF)\n\nToque no link e confira 👇\n\nhttps://meli.la/2sWvfQU?x=1&y=_z",
            $message->caption,
        );
        self::assertSame('v2', $message->format);
        self::assertSame(['provider' => 'openai', 'model' => 'gpt-4.1-mini', 'prompt_version' => 'copy-v1', 'fallback' => false], $message->copy);
        self::assertStringEndsWith("\n\n" . self::LINK, $message->caption);
        self::assertSame(1, substr_count($message->caption, self::LINK));
        self::assertTrue($this->logs->hasInfoThatContains('offer_copy.generated'));
    }

    public function testTheAiNeverReceivesPriceDiscountOrLinkAndTheKeyGoesOnlyInTheHeader(): void
    {
        $this->compose($this->composer());

        $request = $this->openai->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $request['uri']);
        self::assertSame('Bearer ' . FakeOpenAIServer::API_KEY, $request['authorization']);
        $body = (string) json_encode($request['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['179', '249', '17990', '24990', '28', 'meli.la', '2sWvfQU', 'R$', FakeOpenAIServer::API_KEY] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body, $forbidden);
        }
        self::assertStringContainsString(self::TITLE, $body);
        self::assertStringContainsString('Air fryers', $body);
        self::assertSame('gpt-4.1-mini', $request['body']['model']);
        self::assertSame('json_schema', $request['body']['response_format']['type']);
        self::assertTrue($request['body']['response_format']['json_schema']['strict']);
        self::assertSame(['gancho', 'chamada'], $request['body']['response_format']['json_schema']['schema']['required']);
        self::assertFalse($request['body']['response_format']['json_schema']['schema']['additionalProperties']);
        self::assertSame(OpenAIOfferCopyGenerator::MAX_COMPLETION_TOKENS, $request['body']['max_completion_tokens']);
        // Os dados do produto vão como JSON (delimitados), com os fatos booleanos confirmados.
        $user = (string) $request['body']['messages'][1]['content'];
        self::assertStringContainsString('"frete_gratis":true', $user);
        self::assertStringContainsString('"loja_oficial":false', $user);
    }

    public function testWithoutAiItIsV1AndNotAFallback(): void
    {
        $message = $this->compose($this->composer(fixed: true));

        self::assertSame(self::V1, $message->caption);
        self::assertSame('v1', $message->format);
        self::assertSame(['provider' => 'none', 'model' => null, 'prompt_version' => null, 'fallback' => false], $message->copy);
        self::assertSame([], $this->openai->requests);
    }

    /** @return iterable<string, array{int|string, string}> */
    public static function failures(): iterable
    {
        yield 'timeout' => ['timeout', OfferCopyFailure::TIMEOUT];
        yield 'rede' => ['network', OfferCopyFailure::NETWORK_ERROR];
        yield 'HTTP 401' => [401, OfferCopyFailure::HTTP_ERROR];
        yield 'HTTP 429' => [429, OfferCopyFailure::HTTP_ERROR];
        yield 'HTTP 500 (indisponível)' => [500, OfferCopyFailure::HTTP_ERROR];
        yield 'HTTP 503' => [503, OfferCopyFailure::HTTP_ERROR];
        yield 'corpo não JSON' => ['html', OfferCopyFailure::INVALID_JSON];
        yield 'recusa do modelo' => ['refusal', OfferCopyFailure::REFUSED];
        yield 'resposta cortada' => ['length', OfferCopyFailure::TRUNCATED];
        yield 'resposta vazia' => ['empty', OfferCopyFailure::EMPTY_RESPONSE];
        yield 'JSON inválido no conteúdo' => ['{"gancho": "abc"', OfferCopyFailure::INVALID_JSON];
        yield 'campo extra' => ['{"gancho":"Achado bom","chamada":"Confira","preco":"R$ 1"}', OfferCopyFailure::INVALID_JSON];
        yield 'campo faltando' => ['{"gancho":"Achado bom"}', OfferCopyFailure::INVALID_JSON];
        yield 'campo não texto' => ['{"gancho":1,"chamada":"Confira"}', OfferCopyFailure::INVALID_JSON];
        yield 'lista em vez de objeto' => ['["Achado","Confira"]', OfferCopyFailure::INVALID_JSON];
        yield 'validador: preço' => ['{"gancho":"Só 179 reais","chamada":"Confira"}', OfferCopyFailure::REJECTED];
        yield 'validador: link' => ['{"gancho":"Achado bom","chamada":"Veja https://outro.link/x"}', OfferCopyFailure::REJECTED];
    }

    #[DataProvider('failures')]
    public function testAnyFailureFallsBackToV1AndNeverBlocks(int|string $failure, string $reason): void
    {
        if (is_string($failure) && str_starts_with($failure, '{') || is_string($failure) && str_starts_with($failure, '[')) {
            $this->openai->content($failure);
        } else {
            $this->openai->fail($failure);
        }

        $message = $this->compose($this->composer());

        self::assertSame(self::V1, $message->caption);
        self::assertSame('v1', $message->format);
        self::assertSame(['provider' => 'openai', 'model' => 'gpt-4.1-mini', 'prompt_version' => 'copy-v1', 'fallback' => true], $message->copy);
        self::assertCount(1, $this->openai->requests, 'uma única tentativa, sem retry');
        self::assertTrue($this->logs->hasWarningThatContains('offer_copy.fallback_v1'));
        $record = $this->logs->getRecords()[count($this->logs->getRecords()) - 1];
        self::assertSame($reason, $record->context['reason']);
        $this->assertNothingSensitiveLogged();
    }

    public function testMissingKeyFallsBackWithoutCallingTheProvider(): void
    {
        $message = $this->compose($this->composer($this->generator(null)));

        self::assertSame(self::V1, $message->caption);
        self::assertTrue($message->copy['fallback']);
        self::assertSame([], $this->openai->requests);
        self::assertSame(OfferCopyFailure::NOT_CONFIGURED, $this->logs->getRecords()[0]->context['reason']);
    }

    public function testCopyClaimingFreeShippingIsRejectedWhenNotConfirmed(): void
    {
        $this->openai->copy('Chega com frete grátis', 'Confira');

        $message = $this->compose($this->composer(), freeShipping: false);

        self::assertSame('v1', $message->format);
        self::assertSame('forbidden_term:frete', $this->logs->getRecords()[0]->context['rule']);
    }

    public function testUnexpectedErrorAlsoFallsBack(): void
    {
        $broken = new class () implements \Sinergia\Application\Port\Copy\OfferCopyGenerator {
            public function provider(): string
            {
                return 'openai';
            }

            public function model(): ?string
            {
                return 'gpt-4.1-mini';
            }

            public function promptVersion(): ?string
            {
                return 'copy-v1';
            }

            public function generate(OfferCopyRequest $request): ?\Sinergia\Application\Port\Copy\OfferCopy
            {
                throw new \LogicException('bug interno com ' . FakeOpenAIServer::API_KEY);
            }
        };
        $composer = new OfferMessageComposer($broken, new CopyValidator(), new MessageBuilder(), LoggerFactory::create('local', $this->logs));

        $message = $composer->compose(new InstallationId(7), 42, $this->request(), 17990, 24990, self::LINK);

        self::assertSame(self::V1, $message->caption);
        self::assertTrue($message->copy['fallback']);
        self::assertSame('unexpected', $this->logs->getRecords()[0]->context['reason']);
        $this->assertNothingSensitiveLogged();
    }

    public function testConfigDefaultsAndValidation(): void
    {
        $none = Config::fromArray(['APP_ENV' => 'test']);
        self::assertSame('none', $none->aiCopyProvider());
        self::assertNull($none->openAi()->apiKey);
        self::assertSame('gpt-4.1-mini', $none->openAi()->model);
        self::assertSame(10, $none->openAi()->timeoutSeconds);
        self::assertSame('https://api.openai.com/v1', $none->openAi()->baseUrl);

        $set = Config::fromArray(['APP_ENV' => 'test', 'AI_COPY_PROVIDER' => 'openai', 'OPENAI_API_KEY' => FakeOpenAIServer::API_KEY, 'OPENAI_MODEL' => 'gpt-4o-mini', 'OPENAI_TIMEOUT_SECONDS' => '5']);
        self::assertSame('openai', $set->aiCopyProvider());
        self::assertSame(FakeOpenAIServer::API_KEY, $set->openAi()->apiKey?->reveal());
        self::assertSame('gpt-4o-mini', $set->openAi()->model);
        self::assertSame(5, $set->openAi()->timeoutSeconds);

        foreach ([['AI_COPY_PROVIDER' => 'gemini'], ['OPENAI_MODEL' => 'gpt 4'], ['OPENAI_TIMEOUT_SECONDS' => '60'], ['OPENAI_API_KEY' => 'SEGREDO-MARCADOR com espaco']] as $bad) {
            try {
                $config = Config::fromArray(['APP_ENV' => 'test'] + $bad);
                $config->aiCopyProvider();
                $config->openAi();
                self::fail(json_encode($bad) . ' deveria ser recusado.');
            } catch (\Sinergia\Shared\Config\ConfigException $e) {
                self::assertStringContainsString((string) array_key_first($bad), $e->getMessage());
                self::assertStringNotContainsString('SEGREDO-MARCADOR', $e->getMessage());
            }
        }
    }

    public function testKernelPicksTheGeneratorAndInvalidConfigNeverBlocks(): void
    {
        $root = dirname(__DIR__, 3);
        $resolve = static function (array $env) use ($root): \Sinergia\Application\Port\Copy\OfferCopyGenerator {
            $container = \Sinergia\Kernel::container(Config::fromArray(['APP_ENV' => 'test'] + $env), $root);
            if ($container instanceof \DI\Container) {
                $container->set(\Psr\Log\LoggerInterface::class, LoggerFactory::create('local', new TestHandler()));
            }

            return $container->get(\Sinergia\Application\Port\Copy\OfferCopyGenerator::class);
        };

        self::assertInstanceOf(FixedOfferCopyGenerator::class, $resolve([]));
        self::assertInstanceOf(OpenAIOfferCopyGenerator::class, $resolve(['AI_COPY_PROVIDER' => 'openai', 'OPENAI_API_KEY' => FakeOpenAIServer::API_KEY]));
        // Sem chave: continua OpenAI, mas cada envio cai na v1 (not_configured).
        self::assertInstanceOf(OpenAIOfferCopyGenerator::class, $resolve(['AI_COPY_PROVIDER' => 'openai']));
        // Configuração inválida: sem IA (v1), nunca um erro que pare o worker.
        self::assertInstanceOf(FixedOfferCopyGenerator::class, $resolve(['AI_COPY_PROVIDER' => 'gemini']));
        self::assertInstanceOf(FixedOfferCopyGenerator::class, $resolve(['AI_COPY_PROVIDER' => 'openai', 'OPENAI_MODEL' => 'modelo inválido']));
    }

    public function testNoOpenAiKeyVariableIsReadOutsideTheConfig(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', (string) $file);
            if (str_ends_with($path, 'src/Shared/Config/Config.php')) {
                continue;
            }
            self::assertStringNotContainsString('OPENAI_API_KEY', (string) file_get_contents((string) $file), $path);
        }
    }

    private function assertNothingSensitiveLogged(): void
    {
        $logged = (string) json_encode(array_map(static fn ($r): array => [$r->message, $r->context], $this->logs->getRecords()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([FakeOpenAIServer::API_KEY, FakeOpenAIServer::RAW_PROVIDER_MESSAGE, 'Dados do produto', self::TITLE, 'meli.la', '179,90', 'reais', 'bug interno'] as $secret) {
            self::assertStringNotContainsString($secret, $logged, $secret);
        }
    }
}
