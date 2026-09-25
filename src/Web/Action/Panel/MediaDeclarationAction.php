<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Affiliate\MediaDeclaration;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Infrastructure\Persistence\MediaDeclarationRepository;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/** POST /conexoes/afiliado/declaracao — registra ou remove a declaração de Mídias da conta autenticada. */
final class MediaDeclarationAction
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var TenantContext $tenant */
        $tenant = $request->getAttribute(TenantContext::class);
        $token = (string) $request->getAttribute(RequireAuthMiddleware::SESSION_TOKEN, '');
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if (!$this->container->get(Csrf::class)->isValid('sess|' . $token, $body['_csrf'] ?? null)) {
            return $response->withStatus(400);
        }

        $store = $this->container->get(MediaDeclarationRepository::class);
        $now = $this->container->get(Clock::class)->now();
        $action = is_string($body['acao'] ?? null) ? $body['acao'] : '';

        $feedback = match (true) {
            $action === 'declarar' && ($body['confirmo'] ?? null) === '1' => (function () use ($store, $tenant, $now): string {
                $store->declare($tenant->installationId, $tenant->userId, MediaDeclaration::VERSION, $now);

                return 'declarado';
            })(),
            $action === 'declarar' => 'confirmacao_necessaria',
            $action === 'remover' => (function () use ($store, $tenant, $now): string {
                $store->revoke($tenant->installationId, $tenant->userId, $now);

                return 'removido';
            })(),
            default => 'acao_invalida',
        };

        return $response->withStatus(302)->withHeader('Location', '/conexoes?afiliado=' . $feedback);
    }
}
