<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure;
use Sinergia\Application\WhatsApp\ManageWhatsAppConnection;
use Sinergia\Application\WhatsApp\WhatsAppBusy;
use Sinergia\Application\WhatsApp\WhatsAppUnavailable;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/**
 * POST /conexoes/whatsapp/conectar e /conexoes/whatsapp/desconectar — sempre para a conta AUTENTICADA.
 * Nenhum identificador de conta/instância é aceito do navegador.
 */
final class WhatsAppConnectionAction
{
    public const string CONNECT = 'connect';
    public const string DISCONNECT = 'disconnect';

    public function __construct(private readonly ContainerInterface $container, private readonly string $operation)
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

        $manager = $this->container->get(ManageWhatsAppConnection::class);
        try {
            if ($this->operation === self::DISCONNECT) {
                $manager->disconnect($tenant);
                $result = 'desconectado';
            } else {
                $phone = null;
                if (($body['modo'] ?? '') === 'codigo') {
                    $phone = preg_replace('/\D+/', '', is_string($body['telefone'] ?? null) ? $body['telefone'] : '') ?? '';
                    if (preg_match('/^\d{10,15}$/', $phone) !== 1) {
                        return self::redirect($response, 'erro&motivo=telefone_invalido');
                    }
                }
                $manager->connect($tenant, $phone);
                $result = 'conectando';
            }
        } catch (WhatsAppBusy) {
            $result = 'aguarde';
        } catch (WhatsAppUnavailable) {
            $result = 'erro&motivo=unavailable';
        } catch (WhatsAppProviderFailure $e) {
            $result = 'erro&motivo=' . rawurlencode($e->errorCode);
        }

        return self::redirect($response, $result);
    }

    private static function redirect(ResponseInterface $response, string $query): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', '/conexoes?wa=' . $query . '#whatsapp');
    }
}
