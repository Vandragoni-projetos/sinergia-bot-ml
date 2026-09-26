<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Onboarding\ActivateBot;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\View\Views;

/** GET /comecar — Primeiros passos: estado de cada passo calculado no servidor a partir dos dados da conta. */
final class OnboardingPageAction
{
    public const array PENDING = [
        'mercado_livre' => 'Antes de ativar, conecte o Mercado Livre e registre a declaração de Mídias.',
        'nichos' => 'Antes, escolha pelo menos um subnicho em Nichos.',
        'whatsapp' => 'Antes de ativar, conecte o WhatsApp.',
        'destinos' => 'Antes de ativar, deixe pelo menos um destino pronto em Destinos.',
        'links' => 'Antes de ativar, confirme pelo menos um link de afiliado para as ofertas encontradas.',
    ];

    private const array MESSAGES = [
        'busca_cedo' => 'Aguarde alguns minutos antes de buscar ofertas de novo.',
        'ml_recusou' => 'O Mercado Livre recusou a consulta. Reconecte a conta em Conexões.',
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var TenantContext $tenant */
        $tenant = $request->getAttribute(TenantContext::class);
        $token = (string) $request->getAttribute(RequireAuthMiddleware::SESSION_TOKEN, '');
        $query = $request->getQueryParams();
        $checklist = $this->container->get(ActivateBot::class)->checklist($tenant);
        $pending = is_string($query['pendente'] ?? null) ? $query['pendente'] : null;
        $error = is_string($query['erro'] ?? null) ? $query['erro'] : null;
        $found = is_string($query['ofertas'] ?? null) && ctype_digit($query['ofertas']) ? (int) $query['ofertas'] : null;

        return $this->container->get(Views::class)->render($response, 'panel/onboarding.twig', [
            'tenant' => $tenant,
            'current' => 'comecar',
            'nav' => PanelPageAction::PAGES,
            'page' => ['title' => 'Primeiros passos', 'lead' => 'Configure o Sinergia Bot em cinco passos. Nada é publicado até você ativar.'],
            'csrf' => $this->container->get(Csrf::class)->tokenFor('sess|' . $token),
            'checklist' => $checklist,
            'onboarding' => $checklist,
            'pending_message' => $pending === null ? null : (self::PENDING[$pending] ?? null),
            'error' => $error === null ? null : (self::MESSAGES[$error] ?? null),
            'found' => $found,
        ]);
    }
}
