<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Queue\ManageQueue;
use Sinergia\Application\Queue\QueueRejected;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/** POST da Fila: Aprovar / Pular (chave aleatória do item, só na própria conta) e Pausar / Ativar bot. */
final class QueueAction
{
    public const string APPROVE = 'approve';
    public const string SKIP = 'skip';
    public const string PAUSE = 'pause';
    public const string ACTIVATE = 'activate';

    public function __construct(private readonly ContainerInterface $container, private readonly string $operation)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var TenantContext $tenant */
        $tenant = $request->getAttribute(TenantContext::class);
        $token = (string) $request->getAttribute(RequireAuthMiddleware::SESSION_TOKEN, '');
        $body = $request->getParsedBody();
        if (!$this->container->get(Csrf::class)->isValid('sess|' . $token, is_array($body) ? ($body['_csrf'] ?? null) : null)) {
            return $response->withStatus(400);
        }
        $manager = $this->container->get(ManageQueue::class);
        $key = (string) ($args['item'] ?? '');
        try {
            $ok = match ($this->operation) {
                self::APPROVE => (function () use ($manager, $tenant, $key): string { $manager->approve($tenant, $key); return 'aprovado'; })(),
                self::SKIP => (function () use ($manager, $tenant, $key): string { $manager->skip($tenant, $key); return 'pulado'; })(),
                self::PAUSE => (function () use ($manager, $tenant): string { $manager->setBot($tenant, false); return 'bot_pausado'; })(),
                default => (function () use ($manager, $tenant): string { $manager->setBot($tenant, true); return 'bot_ativado'; })(),
            };
        } catch (QueueRejected $e) {
            return $response->withStatus(302)->withHeader('Location', '/fila?erro=' . $e->reason);
        }

        return $response->withStatus(302)->withHeader('Location', '/fila?ok=' . $ok);
    }
}
