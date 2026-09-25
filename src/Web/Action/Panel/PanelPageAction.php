<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\View\Views;

/**
 * As quatro áreas do painel. Na etapa 1 são só a estrutura (layout + placeholder);
 * o conteúdo de cada uma chega na etapa indicada do plano F1 v2.1.
 */
final class PanelPageAction
{
    /** Ordem = fluxo aprovado: conectar → o que vender → onde publicar → o bot trabalha. */
    public const array PAGES = [
        'conexoes' => ['title' => 'Conexões', 'lead' => 'Conecte o Mercado Livre e o WhatsApp.', 'arrives' => 'nas etapas 2 e 5'],
        'nichos' => ['title' => 'Nichos', 'lead' => 'Escolha o que o bot vai vender.', 'arrives' => 'na etapa 3'],
        'destinos' => ['title' => 'Destinos', 'lead' => 'Escolha onde o bot publica.', 'arrives' => 'na etapa 6'],
        'fila' => ['title' => 'Fila', 'lead' => 'Acompanhe o bot trabalhando.', 'arrives' => 'nas etapas 7 e 8'],
    ];

    public function __construct(private readonly ContainerInterface $container, private readonly string $page)
    {
        if (!isset(self::PAGES[$page])) {
            throw new \InvalidArgumentException('Página desconhecida.');
        }
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var TenantContext $tenant */
        $tenant = $request->getAttribute(TenantContext::class);
        $token = (string) $request->getAttribute(RequireAuthMiddleware::SESSION_TOKEN, '');

        return $this->container->get(Views::class)->render($response, 'panel/page.twig', [
            'tenant' => $tenant,
            'current' => $this->page,
            'nav' => self::PAGES,
            'page' => self::PAGES[$this->page],
            'csrf' => $this->container->get(Csrf::class)->tokenFor('sess|' . $token),
        ]);
    }
}
