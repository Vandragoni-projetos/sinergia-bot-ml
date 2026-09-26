<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Onboarding\ActivateBot;
use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Infrastructure\Persistence\AccountNicheRepository;
use Sinergia\Infrastructure\Persistence\NicheCatalogRepository;
use Sinergia\Shared\Config\Config;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\View\Views;

/**
 * GET /nichos — nicho → subnichos → filtros. Só nomes amigáveis: nenhuma categoria/ID do Mercado Livre
 * chega ao template. As escolhas exibidas são sempre as da conta do TenantContext.
 */
final class NichesPageAction
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        return $this->render($request, $response, [
            'saved' => is_string($query['salvo'] ?? null) ? $query['salvo'] : null,
            'unavailable' => ($query['erro'] ?? null) === 'opcao_indisponivel',
        ]);
    }

    /**
     * @param array{saved?: ?string, unavailable?: bool, failed?: array{slug: string, subniches: list<string>, form: array<array-key, mixed>, errors: array<string, string>}} $state
     */
    public function render(ServerRequestInterface $request, ResponseInterface $response, array $state, int $status = 200): ResponseInterface
    {
        /** @var TenantContext $tenant */
        $tenant = $request->getAttribute(TenantContext::class);
        $token = (string) $request->getAttribute(RequireAuthMiddleware::SESSION_TOKEN, '');
        $site = $this->container->get(Config::class)->siteId();

        $catalog = $this->container->get(NicheCatalogRepository::class)->offered($site);
        $selections = $this->container->get(AccountNicheRepository::class)->selections($tenant->installationId);
        $failed = $state['failed'] ?? null;

        $niches = [];
        foreach ($catalog as $niche) {
            $selection = $selections[$niche->id] ?? null;
            $filters = $selection->filters ?? NicheFilters::defaults();
            $isFailed = $failed !== null && $failed['slug'] === $niche->slug;
            $checked = $isFailed ? $failed['subniches'] : [];

            $subniches = [];
            $active = 0;
            foreach ($niche->subniches as $subniche) {
                $on = $isFailed
                    ? in_array($subniche->slug, $checked, true)
                    : in_array($subniche->id, $selection->subnicheIds ?? [], true);
                $active += $on ? 1 : 0;
                $subniches[] = ['slug' => $subniche->slug, 'name' => $subniche->name, 'checked' => $on];
            }

            $niches[] = [
                'slug' => $niche->slug,
                'name' => $niche->name,
                'subniches' => $subniches,
                'active' => $isFailed ? count(array_filter($subniches, static fn (array $s): bool => $s['checked'])) : $active,
                'total' => count($subniches),
                'form' => $isFailed ? self::echoForm($failed['form']) : [
                    'desconto_minimo' => $filters->minDiscountPct === null ? '' : (string) $filters->minDiscountPct,
                    'preco_minimo' => NicheFilters::formatCents($filters->minPriceCents),
                    'preco_maximo' => NicheFilters::formatCents($filters->maxPriceCents),
                    'exige_foto' => $filters->requirePhoto,
                ],
                'summary' => self::summary($filters),
                'errors' => $isFailed ? $failed['errors'] : [],
                'saved' => ($state['saved'] ?? null) === $niche->slug,
                'open' => $isFailed || ($state['saved'] ?? null) === $niche->slug || $active > 0,
            ];
        }

        // Primeiro acesso: abre o primeiro nicho para mostrar o caminho nicho → subnichos → filtros.
        if ($niches !== [] && !in_array(true, array_column($niches, 'open'), true)) {
            $niches[0]['open'] = true;
        }

        return $this->container->get(Views::class)->render($response, 'panel/niches.twig', [
            'onboarding' => $this->container->get(ActivateBot::class)->checklist($tenant),
            'tenant' => $tenant,
            'current' => 'nichos',
            'nav' => PanelPageAction::PAGES,
            'page' => PanelPageAction::PAGES['nichos'],
            'csrf' => $this->container->get(Csrf::class)->tokenFor('sess|' . $token),
            'niches' => $niches,
            'unavailable' => $state['unavailable'] ?? false,
        ], $status);
    }

    /**
     * @param array<array-key, mixed> $form
     *
     * @return array{desconto_minimo: string, preco_minimo: string, preco_maximo: string, exige_foto: bool}
     */
    private static function echoForm(array $form): array
    {
        $text = static fn (string $key): string => is_string($form[$key] ?? null) ? mb_substr(trim($form[$key]), 0, 20) : '';

        return [
            'desconto_minimo' => $text('desconto_minimo'),
            'preco_minimo' => $text('preco_minimo'),
            'preco_maximo' => $text('preco_maximo'),
            'exige_foto' => ($form['exige_foto'] ?? null) === '1',
        ];
    }

    private static function summary(NicheFilters $filters): string
    {
        $parts = [];
        if ($filters->minDiscountPct !== null) {
            $parts[] = 'Desconto ≥ ' . $filters->minDiscountPct . '%';
        }
        $min = $filters->minPriceCents;
        $max = $filters->maxPriceCents;
        if ($min !== null && $max !== null) {
            $parts[] = 'R$ ' . NicheFilters::formatCents($min) . ' a ' . NicheFilters::formatCents($max);
        } elseif ($min !== null) {
            $parts[] = 'A partir de R$ ' . NicheFilters::formatCents($min);
        } elseif ($max !== null) {
            $parts[] = 'Até R$ ' . NicheFilters::formatCents($max);
        }
        $parts[] = $filters->requirePhoto ? 'com foto' : 'foto opcional';

        return implode(' · ', $parts);
    }
}
