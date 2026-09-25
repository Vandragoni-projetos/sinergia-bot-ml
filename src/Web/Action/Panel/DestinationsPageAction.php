<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Destination\DestinationDeclarations;
use Sinergia\Application\Destination\DestinationRecord;
use Sinergia\Application\Destination\Eligibility;
use Sinergia\Application\Destination\ManageDestinations;
use Sinergia\Infrastructure\Persistence\DestinationRepository;
use Sinergia\Infrastructure\Persistence\WhatsAppConnectionRepository;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\View\Views;

/**
 * GET /destinos — nome → tipo → rótulo → nicho → horário → intervalo → automático/manual → status.
 * Nada de JID, id interno ou token no HTML: formulários usam chaves aleatórias da própria conta.
 */
final class DestinationsPageAction
{
    public const array REASONS = [
        'not_found_in_whatsapp' => 'não aparece mais no seu WhatsApp',
        'channel_not_admin' => 'seu número não administra este canal',
        'community_group' => 'é uma comunidade ou subgrupo de comunidade',
        'join_approval_required' => 'exige aprovação para entrar',
        'not_admin' => 'seu número não é administrador do grupo',
        'no_invite_link' => 'o grupo não tem link de convite ativo',
        'insufficient_information' => 'o WhatsApp não informou dados suficientes para verificar',
        'public_declaration_missing' => 'falta a declaração de grupo público',
        'media_declaration_missing' => 'falta a declaração de Mídia cadastrada',
    ];

    public const array MESSAGES = [
        'not_found' => 'Destino não encontrado. Atualize a lista e tente de novo.',
        'whatsapp_not_connected' => 'Conecte o WhatsApp em Conexões para buscar e verificar destinos.',
        'not_ready' => 'Escolha o nicho e os subnichos antes de ativar.',
        'not_eligible' => 'Este destino não está elegível e não pode ser ativado nem receber envios.',
        'not_a_group' => 'Essa declaração vale só para grupos.',
        'test_send_disabled' => 'O envio de teste está desligado neste servidor.',
        'confirmation_required' => 'Marque a caixa da declaração para registrá-la.',
        'unauthorized' => 'O serviço de WhatsApp recusou a conexão desta conta. Reconecte em Conexões.',
        'forbidden' => 'O serviço de WhatsApp não permitiu a consulta.',
        'rate_limited' => 'O serviço de WhatsApp está no limite. Tente de novo em alguns minutos.',
        'server_error' => 'O serviço de WhatsApp está instável no momento.',
        'timeout' => 'O serviço de WhatsApp demorou demais para responder.',
        'network_error' => 'Não foi possível falar com o serviço de WhatsApp.',
        'invalid_response' => 'O serviço de WhatsApp respondeu de forma inesperada.',
        'http_error' => 'O serviço de WhatsApp recusou a operação.',
        'not_found_provider' => 'O WhatsApp não encontrou esse destino.',
        'conflict' => 'Já existe uma operação em andamento.',
        'busy' => 'Já existe uma operação em andamento. Aguarde alguns segundos.',
    ];

    private const array SUCCESS = [
        'sincronizado' => 'Lista de grupos e canais atualizada.',
        'adicionado' => 'Destino adicionado. Configure o nicho e as declarações.',
        'salvo' => 'Configuração salva.',
        'pausado' => 'Destino pausado.',
        'ativado' => 'Destino ativado.',
        'declarado' => 'Declaração registrada.',
        'removido' => 'Destino removido.',
        'teste_enviado' => 'Mensagem de teste enviada.',
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $ok = is_string($query['ok'] ?? null) ? $query['ok'] : null;
        $error = is_string($query['erro'] ?? null) ? $query['erro'] : null;

        return $this->render($request, $response, [
            'notice' => $ok === null ? null : (self::SUCCESS[$ok] ?? null),
            'error' => $error === null ? null : (self::MESSAGES[$error] ?? 'Não foi possível concluir a operação.'),
        ]);
    }

    /**
     * @param array{notice?: ?string, error?: ?string, failed?: array{key: string, form: array<array-key, mixed>, errors: array<string, string>}} $state
     */
    public function render(ServerRequestInterface $request, ResponseInterface $response, array $state, int $status = 200): ResponseInterface
    {
        /** @var TenantContext $tenant */
        $tenant = $request->getAttribute(TenantContext::class);
        $token = (string) $request->getAttribute(RequireAuthMiddleware::SESSION_TOKEN, '');
        $store = $this->container->get(DestinationRepository::class);
        $connection = $this->container->get(WhatsAppConnectionRepository::class)->find($tenant->installationId);
        $niches = $store->nicheOptions($tenant->installationId);
        $nicheNames = array_column($niches, 'name', 'id');
        $failed = $state['failed'] ?? null;

        $rows = [];
        foreach ($store->all($tenant->installationId) as $destination) {
            $isFailed = $failed !== null && $failed['key'] === $destination->publicKey;
            $rows[] = $this->row($destination, $nicheNames, $niches, $isFailed ? $failed : null);
        }

        $available = [];
        foreach ($store->available($tenant->installationId) as $item) {
            if (!$item->registered) {
                $available[] = [
                    'key' => $item->pickKey,
                    'name' => $item->name,
                    'type' => $item->type === 'channel' ? 'Canal' : 'Grupo',
                    'hint' => match (true) {
                        $item->type === 'channel' => $item->weAreAdmin === false ? 'Você só segue este canal' : null,
                        $item->joinApproval === true => 'Exige aprovação para entrar',
                        $item->isCommunity === true => 'Comunidade',
                        $item->weAreAdmin === false => 'Você não é administrador',
                        default => null,
                    },
                ];
            }
        }

        return $this->container->get(Views::class)->render($response, 'panel/destinations.twig', [
            'tenant' => $tenant,
            'current' => 'destinos',
            'nav' => PanelPageAction::PAGES,
            'page' => PanelPageAction::PAGES['destinos'],
            'csrf' => $this->container->get(Csrf::class)->tokenFor('sess|' . $token),
            'connected' => $connection !== null && $connection->status === 'connected',
            'rows' => $rows,
            'available' => $available,
            'niches' => $niches,
            'texts' => DestinationDeclarations::TEXTS,
            'test_send' => $this->container->get(ManageDestinations::class)->testSendEnabled(),
            'notice' => $state['notice'] ?? null,
            'error' => $state['error'] ?? null,
        ], $status);
    }

    /**
     * @param array<int, string>                                                                                              $nicheNames
     * @param list<array{id: int, slug: string, name: string, subniches: list<array{id: int, slug: string, name: string}>}> $niches
     * @param array{key: string, form: array<array-key, mixed>, errors: array<string, string>}|null                         $failed
     *
     * @return array<string, mixed>
     */
    private function row(DestinationRecord $d, array $nicheNames, array $niches, ?array $failed): array
    {
        $label = match ($d->eligibility) {
            Eligibility::CHANNEL_PUBLIC => 'Canal público',
            Eligibility::GROUP_DECLARED_PUBLIC => 'Grupo declarado público',
            default => 'Não elegível — ' . (self::REASONS[(string) $d->ineligibleReason] ?? 'motivo não informado'),
        };
        $fact = static fn (?bool $v): string => $v === null ? 'Não informado' : ($v ? 'Sim' : 'Não');
        $nicheSlug = '';
        foreach ($niches as $niche) {
            if ($niche['id'] === $d->nicheId) {
                $nicheSlug = $niche['slug'];
            }
        }
        $form = $failed['form'] ?? null;
        $subSlugs = [];
        foreach ($niches as $niche) {
            foreach ($niche['subniches'] as $sub) {
                if (in_array($sub['id'], $d->subnicheIds, true)) {
                    $subSlugs[] = $sub['slug'];
                }
            }
        }

        return [
            'key' => $d->publicKey,
            'name' => $d->name,
            'type' => $d->type === 'channel' ? 'Canal' : 'Grupo',
            'is_group' => $d->type === 'group',
            'label' => $label,
            'eligible' => $d->eligibility !== Eligibility::INELIGIBLE,
            'niche' => $d->nicheId === null ? 'Não definido' : ($nicheNames[$d->nicheId] ?? 'Não definido'),
            'window' => $d->windowStart . '–' . $d->windowEnd,
            'interval' => 'A cada ' . $d->intervalMinutes . ' min',
            'mode' => $d->mode === 'auto' ? 'Automático' : 'Manual',
            'status' => match ($d->status) { 'active' => 'Ativo', 'paused' => 'Pausado', default => 'Não elegível' },
            'status_class' => match ($d->status) { 'active' => 'ok', 'paused' => '', default => 'bad' },
            'paused' => $d->userPaused,
            'facts' => $d->type === 'channel'
                ? ['Aparece no seu WhatsApp' => $fact($d->facts->presentInWhatsApp), 'Seu número administra o canal' => $fact($d->facts->weAreAdmin)]
                : [
                    'Aparece no seu WhatsApp' => $fact($d->facts->presentInWhatsApp),
                    'Seu número é administrador' => $fact($d->facts->weAreAdmin),
                    'Tem link de convite ativo' => $fact($d->facts->hasInviteLink),
                    'Exige aprovação para entrar' => $fact($d->facts->joinApproval),
                    'Só administradores enviam' => $fact($d->facts->announceOnly),
                    'Comunidade ou subgrupo' => $fact($d->facts->isCommunity),
                ],
            'checked_at' => $d->techCheckedAt,
            'public' => ['declared' => $d->publicDeclared, 'at' => $d->publicDeclaredAt, 'by' => $d->publicDeclaredBy, 'version' => $d->publicDeclarationVersion],
            'media' => ['declared' => $d->mediaDeclared, 'at' => $d->mediaDeclaredAt, 'by' => $d->mediaDeclaredBy, 'version' => $d->mediaDeclarationVersion],
            'last_test' => $d->lastTestSentAt,
            'form' => [
                'nicho' => is_string($form['nicho'] ?? null) ? $form['nicho'] : $nicheSlug,
                'subnichos' => is_array($form['subnichos'] ?? null) ? array_values(array_filter($form['subnichos'], 'is_string')) : $subSlugs,
                'inicio' => is_string($form['inicio'] ?? null) ? mb_substr($form['inicio'], 0, 5) : $d->windowStart,
                'fim' => is_string($form['fim'] ?? null) ? mb_substr($form['fim'], 0, 5) : $d->windowEnd,
                'intervalo' => is_string($form['intervalo'] ?? null) ? mb_substr($form['intervalo'], 0, 4) : (string) $d->intervalMinutes,
                'modo' => is_string($form['modo'] ?? null) ? $form['modo'] : $d->mode,
            ],
            'errors' => $failed['errors'] ?? [],
            'open' => $failed !== null,
        ];
    }
}
