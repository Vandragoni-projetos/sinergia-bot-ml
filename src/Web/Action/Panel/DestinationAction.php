<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Destination\DestinationRejected;
use Sinergia\Application\Destination\InvalidDestinationSettings;
use Sinergia\Application\Destination\ManageDestinations;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure;
use Sinergia\Application\WhatsApp\WhatsAppUnavailable;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/**
 * POSTs da tela Destinos, sempre para a conta AUTENTICADA. A chave na URL ({chave}) é aleatória e só é
 * procurada dentro da própria conta; nenhum provider_ref/installation_id/token vindo do navegador é usado.
 */
final class DestinationAction
{
    public const string SYNC = 'sync';
    public const string ADD = 'add';
    public const string CONFIGURE = 'configure';
    public const string PAUSE = 'pause';
    public const string ACTIVATE = 'activate';
    public const string DECLARE = 'declare';
    public const string REMOVE = 'remove';
    public const string TEST = 'test';

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
        $body = is_array($body) ? $body : [];
        if (!$this->container->get(Csrf::class)->isValid('sess|' . $token, $body['_csrf'] ?? null)) {
            return $response->withStatus(400);
        }

        $manager = $this->container->get(ManageDestinations::class);
        $key = (string) ($args['chave'] ?? '');
        try {
            $ok = match ($this->operation) {
                self::SYNC => (function () use ($manager, $tenant): string {
                    $manager->sync($tenant);

                    return 'sincronizado';
                })(),
                self::ADD => (function () use ($manager, $tenant, $body): string {
                    $manager->add($tenant, is_string($body['escolha'] ?? null) ? $body['escolha'] : '');

                    return 'adicionado';
                })(),
                self::CONFIGURE => (function () use ($manager, $tenant, $key, $body): string {
                    $manager->configure($tenant, $key, $body);

                    return 'salvo';
                })(),
                self::PAUSE, self::ACTIVATE => (function () use ($manager, $tenant, $key): string {
                    $manager->setPaused($tenant, $key, $this->operation === self::PAUSE);

                    return $this->operation === self::PAUSE ? 'pausado' : 'ativado';
                })(),
                self::DECLARE => (function () use ($manager, $tenant, $key, $body): string {
                    if (($body['valor'] ?? '') === '1' && ($body['confirmo'] ?? '') !== '1') {
                        throw new DestinationRejected(DestinationRejected::CONFIRMATION_REQUIRED);
                    }
                    $manager->declare($tenant, $key, is_string($body['tipo'] ?? null) ? $body['tipo'] : '', ($body['valor'] ?? '') === '1');

                    return 'declarado';
                })(),
                self::REMOVE => (function () use ($manager, $tenant, $key): string {
                    $manager->remove($tenant, $key);

                    return 'removido';
                })(),
                default => (function () use ($manager, $tenant, $key): string {
                    $manager->sendTest($tenant, $key);

                    return 'teste_enviado';
                })(),
            };
        } catch (InvalidDestinationSettings $e) {
            return (new DestinationsPageAction($this->container))->render($request, $response, [
                'error' => 'Corrija os campos destacados. Nada foi salvo.',
                'failed' => ['key' => $key, 'form' => $body, 'errors' => $e->errors],
            ], 422);
        } catch (DestinationRejected $e) {
            return self::redirect($response, 'erro=' . $e->reason);
        } catch (WhatsAppUnavailable) {
            return self::redirect($response, 'erro=whatsapp_not_connected');
        } catch (WhatsAppProviderFailure $e) {
            return self::redirect($response, 'erro=' . ($e->errorCode === WhatsAppProviderFailure::NOT_FOUND ? 'not_found_provider' : $e->errorCode));
        }

        return self::redirect($response, 'ok=' . $ok);
    }

    private static function redirect(ResponseInterface $response, string $query): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', '/destinos?' . $query);
    }
}
