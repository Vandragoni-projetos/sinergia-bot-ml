<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\OAuth\StartMercadoLivreConnection;
use Sinergia\Shared\Config\ConfigException;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/** POST /conexoes/mercadolivre/conectar — inicia o OAuth para a conta AUTENTICADA e redireciona ao ML. */
final class MercadoLivreConnectAction
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

        if (!$this->container->get(Csrf::class)->isValid('sess|' . $token, is_array($body) ? ($body['_csrf'] ?? null) : null)) {
            return $response->withStatus(400);
        }

        try {
            $url = $this->container->get(StartMercadoLivreConnection::class)->start($tenant);
        } catch (\Throwable $e) {
            // Sem ML_CLIENT_ID/SECRET/REDIRECT_URI válidos a integração fica indisponível (o container pode embrulhar o erro).
            for ($cause = $e; $cause !== null && !$cause instanceof ConfigException; $cause = $cause->getPrevious());
            if ($cause === null) {
                throw $e;
            }
            $this->container->get(LoggerInterface::class)->error('oauth.panel_start_unavailable', ['installation_id' => $tenant->installationId->value]);

            return $response->withStatus(302)->withHeader('Location', '/conexoes?ml=erro&motivo=unavailable');
        }

        return $response->withStatus(302)->withHeader('Location', $url);
    }
}
