<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\RequestInterface;
use Sinergia\Integration\MercadoLivre\Http\AccessTokenProvider;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;

/**
 * Cliente do Mercado Livre com HTTP falso (Guzzle MockHandler).
 * Nenhum teste que use esta classe abre conexão de rede.
 */
final class FakeMercadoLivre
{
    public const string TOKEN = 'APP_USR-1234567890-000000-abcdefTESTTOKENabcdef-99999';

    public MockHandler $mock;
    /** @var list<array{request: RequestInterface}> */
    public array $history = [];
    public TestHandler $logs;
    public MercadoLivreClient $client;

    public function __construct(bool $withToken = true)
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]);
        $this->logs = new TestHandler();
        $factory = new HttpFactory();

        $tokens = $withToken ? new class implements AccessTokenProvider {
            public function accessToken(): SensitiveValue
            {
                return new SensitiveValue(FakeMercadoLivre::TOKEN);
            }
        } : null;

        $this->client = new MercadoLivreClient(
            $http,
            $factory,
            $factory,
            new MercadoLivreConfig('MLB', 'https://api.mercadolibre.com', 'https://auth.mercadolivre.com.br', 5, 'SinergiaBotML/test'),
            new FrozenClock('2026-09-22T12:00:00Z'),
            LoggerFactory::create('local', $this->logs),
            $tokens,
        );
    }

    /** @param array<string, string> $headers */
    public function queueJson(int $status, mixed $body, array $headers = []): void
    {
        $this->mock->append(new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @param array<string, string> $headers */
    public function queueRaw(int $status, string $body, array $headers = []): void
    {
        $this->mock->append(new Response($status, $headers, $body));
    }

    public function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function logText(): string
    {
        $out = '';
        foreach ($this->logs->getRecords() as $record) {
            $out .= json_encode($record->toArray(), JSON_UNESCAPED_SLASHES) . "\n";
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    public static function fixture(string $name): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/mercadolivre/' . $name);

        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }
}
