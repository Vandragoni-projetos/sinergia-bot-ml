<?php

declare(strict_types=1);

namespace Sinergia\Web\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Shared\Config\Config;
use Sinergia\Web\HttpApp;

/**
 * Health check mínimo: não toca banco nem integrações e não expõe configuração,
 * segredos, caminhos ou stack trace. Só diz que o processo web está de pé.
 */
final class HealthAction
{
    public function __construct(private readonly Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return HttpApp::jsonResponse($response, [
            'status' => 'ok',
            'service' => 'sinergia-bot-ml',
            'version' => $this->config->appVersion(),
        ]);
    }
}
