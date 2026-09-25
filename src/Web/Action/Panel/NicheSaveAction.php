<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Niche\InvalidNicheFilters;
use Sinergia\Application\Niche\NicheSelectionRejected;
use Sinergia\Application\Niche\SaveNicheSelection;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/** POST /nichos/{nicho} — salva subnichos e filtros de um nicho para a conta AUTENTICADA. */
final class NicheSaveAction
{
    public function __construct(private readonly ContainerInterface $container)
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

        $slug = (string) ($args['nicho'] ?? '');
        $subniches = is_array($body['subnichos'] ?? null) ? array_values($body['subnichos']) : [];

        try {
            $this->container->get(SaveNicheSelection::class)->save($tenant, $slug, $subniches, $body);
        } catch (NicheSelectionRejected) {
            return $response->withStatus(302)->withHeader('Location', '/nichos?erro=opcao_indisponivel');
        } catch (InvalidNicheFilters $e) {
            return (new NichesPageAction($this->container))->render($request, $response, [
                'failed' => [
                    'slug' => $slug,
                    'subniches' => array_values(array_filter($subniches, 'is_string')),
                    'form' => $body,
                    'errors' => $e->errors,
                ],
            ], 422);
        }

        return $response->withStatus(302)->withHeader('Location', '/nichos?salvo=' . rawurlencode($slug) . '#nicho-' . rawurlencode($slug));
    }
}
