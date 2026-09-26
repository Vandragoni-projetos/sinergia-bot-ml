<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Offer\SelectionReport;
use Sinergia\Application\Onboarding\ActivateBot;
use Sinergia\Application\Onboarding\ActivationBlocked;
use Sinergia\Application\Onboarding\OfferSearchTooSoon;
use Sinergia\Application\Onboarding\SearchOffers;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/**
 * POST /comecar/ativar e /comecar/ofertas. Nenhum campo do navegador indica etapa concluída:
 * tudo é recalculado no servidor a partir dos dados da conta autenticada.
 */
final class OnboardingAction
{
    public const string ACTIVATE = 'activate';
    public const string SEARCH = 'search';

    public function __construct(private readonly ContainerInterface $container, private readonly string $operation)
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
            if ($this->operation === self::ACTIVATE) {
                $this->container->get(ActivateBot::class)->activate($tenant);

                return $response->withStatus(302)->withHeader('Location', '/fila?ok=bot_ativado');
            }
            $report = $this->container->get(SearchOffers::class)->search($tenant);
            if ($report->status === SelectionReport::AUTH_FAILED) {
                return $response->withStatus(302)->withHeader('Location', '/comecar?erro=ml_recusou');
            }

            return $response->withStatus(302)->withHeader('Location', '/comecar?ofertas=' . count($report->candidates) . '#ofertas');
        } catch (ActivationBlocked $e) {
            return $response->withStatus(302)->withHeader('Location', '/comecar?pendente=' . $e->pendingStep);
        } catch (OfferSearchTooSoon) {
            return $response->withStatus(302)->withHeader('Location', '/comecar?erro=busca_cedo#ofertas');
        }
    }
}
