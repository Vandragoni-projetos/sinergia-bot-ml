<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * OpenAI FALSA (sem rede, sem chave real): responde POST /v1/chat/completions no formato do Chat Completions com
 * saída estruturada. Cada chamada consome a próxima resposta programada; sem programação devolve uma copy válida.
 * Respostas especiais: 'timeout', 'network', 'html' (corpo não JSON), 'refusal', 'length', 'empty', ou status HTTP.
 */
final class FakeOpenAIServer
{
    /** Chave fictícia: só existe para provar que ela vai no cabeçalho e nunca em log, HTML ou message_json. */
    public const string API_KEY = 'TESTE-openai-chave-ficticia-NAO-USAR';
    public const string RAW_PROVIDER_MESSAGE = 'MENSAGEM-BRUTA-DA-OPENAI-NAO-EXIBIR';

    /** @var list<array{method: string, uri: string, authorization: string, body: array<string, mixed>}> */
    public array $requests = [];
    /** @var list<int|string|array{content: string}> */
    private array $queue = [];

    public function client(): Client
    {
        return new Client([
            'http_errors' => false,
            'allow_redirects' => false,
            'handler' => fn (RequestInterface $request) => $this->handle($request),
        ]);
    }

    /** Próxima resposta: copy JSON com os dois campos. */
    public function copy(string $hook, string $callToAction): void
    {
        $this->queue[] = ['content' => (string) json_encode(['gancho' => $hook, 'chamada' => $callToAction], JSON_UNESCAPED_UNICODE)];
    }

    /** Próxima resposta: conteúdo bruto (JSON quebrado, campos extras etc.). */
    public function content(string $content): void
    {
        $this->queue[] = ['content' => $content];
    }

    public function fail(int|string $failure): void
    {
        $this->queue[] = $failure;
    }

    /** @return array<string, mixed> corpo da última chamada */
    public function lastBody(): array
    {
        return $this->requests[count($this->requests) - 1]['body'] ?? [];
    }

    private function handle(RequestInterface $request): mixed
    {
        $body = json_decode((string) $request->getBody(), true);
        $this->requests[] = [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'authorization' => $request->getHeaderLine('Authorization'),
            'body' => is_array($body) ? $body : [],
        ];
        if ($request->getMethod() !== 'POST' || (string) $request->getUri() !== 'https://api.openai.com/v1/chat/completions') {
            return $this->json(404, ['error' => ['message' => self::RAW_PROVIDER_MESSAGE]]);
        }
        if ($request->getHeaderLine('Authorization') !== 'Bearer ' . self::API_KEY) {
            return $this->json(401, ['error' => ['message' => self::RAW_PROVIDER_MESSAGE]]);
        }

        $next = array_shift($this->queue) ?? ['content' => '{"gancho":"Achado que vale a pena conferir ✨","chamada":"Garanta o seu pelo link 👇"}'];

        return match (true) {
            $next === 'timeout' => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 10001 milliseconds', $request)),
            $next === 'network' => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $request)),
            $next === 'html' => Create::promiseFor(new Response(200, ['Content-Type' => 'text/html'], '<html>gateway</html>')),
            $next === 'refusal' => $this->json(200, $this->completion(null, 'stop', 'Não posso ajudar com isso.')),
            $next === 'length' => $this->json(200, $this->completion('{"gancho":"Achado', 'length')),
            $next === 'empty' => $this->json(200, $this->completion('', 'stop')),
            is_int($next) => $this->json($next, ['error' => ['message' => self::RAW_PROVIDER_MESSAGE]]),
            is_array($next) => $this->json(200, $this->completion($next['content'], 'stop')),
            default => $this->json(500, ['error' => ['message' => self::RAW_PROVIDER_MESSAGE]]),
        };
    }

    /** @return array<string, mixed> */
    private function completion(?string $content, string $finish, ?string $refusal = null): array
    {
        return [
            'id' => 'chatcmpl-teste',
            'object' => 'chat.completion',
            'model' => 'gpt-4.1-mini',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content, 'refusal' => $refusal],
                'finish_reason' => $finish,
            ]],
        ];
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): mixed
    {
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
