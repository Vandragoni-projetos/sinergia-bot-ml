<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Affiliate\MediaDeclaration;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\WhatsApp\ManageWhatsAppConnection;
use Sinergia\Application\WhatsApp\WhatsAppCard;
use Sinergia\Infrastructure\Persistence\MediaDeclarationRepository;
use Sinergia\Infrastructure\Persistence\MlConnectionStatusRepository;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\View\Views;

/** GET /conexoes — card Mercado Livre (conexão + bloco Afiliado) e card WhatsApp (etapa 5). */
final class ConnectionsPageAction
{
    /** Mensagens de erro exibíveis (o código vem da URL; qualquer outro valor vira genérico). */
    private const array ERRORS = [
        'authorization_denied' => 'O Mercado Livre não autorizou a conexão.',
        'missing_parameters' => 'O Mercado Livre não devolveu os dados da autorização.',
        'state_unknown' => 'O link de autorização é desconhecido ou já foi usado.',
        'state_expired' => 'O link de autorização expirou (vale 10 minutos).',
        'token_exchange_failed' => 'O Mercado Livre recusou a troca do código de autorização.',
        'storage_failed' => 'A conexão foi autorizada, mas não pôde ser gravada.',
        'login_required' => 'Sua sessão terminou antes de concluir a conexão.',
        'account_mismatch' => 'A autorização foi iniciada por outra conta do painel.',
        'unavailable' => 'A integração com o Mercado Livre não está configurada neste servidor.',
    ];

    /** Mensagens do card WhatsApp por código (nunca o texto bruto do provedor). */
    private const array WA_ERRORS = [
        'unauthorized' => 'O serviço de WhatsApp recusou a autenticação do servidor.',
        'forbidden' => 'O serviço de WhatsApp não permitiu esta operação.',
        'not_found' => 'A conexão não foi encontrada no serviço de WhatsApp.',
        'rate_limited' => 'O serviço de WhatsApp está no limite de conexões. Tente de novo em alguns minutos.',
        'server_error' => 'O serviço de WhatsApp está instável no momento.',
        'timeout' => 'O serviço de WhatsApp demorou demais para responder.',
        'network_error' => 'Não foi possível falar com o serviço de WhatsApp.',
        'invalid_response' => 'O serviço de WhatsApp respondeu de forma inesperada.',
        'http_error' => 'O serviço de WhatsApp recusou a operação.',
        'conflict' => 'Já existe uma conexão em andamento.',
        'unavailable' => 'A integração com o WhatsApp não está configurada neste servidor.',
        'telefone_invalido' => 'Informe o número com DDI e DDD, só números (ex.: 5511999999999).',
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
        $status = $this->container->get(MlConnectionStatusRepository::class);
        $now = $this->container->get(Clock::class)->now();

        $summary = $status->summary($tenant->installationId);
        $result = is_string($query['ml'] ?? null) ? $query['ml'] : null;
        $reason = is_string($query['motivo'] ?? null) ? $query['motivo'] : '';

        $state = match (true) {
            $result === 'erro' => 'error',
            $summary !== null && $summary->isConnected() => 'connected',
            $summary !== null => 'reconnect',
            $status->hasPendingPanelAuthorization($tenant->installationId, $now) => 'connecting',
            default => 'disconnected',
        };

        return $this->container->get(Views::class)->render($response, 'panel/connections.twig', [
            'tenant' => $tenant,
            'current' => 'conexoes',
            'nav' => PanelPageAction::PAGES,
            'page' => PanelPageAction::PAGES['conexoes'],
            'csrf' => $this->container->get(Csrf::class)->tokenFor('sess|' . $token),
            'ml' => [
                'state' => $state,
                'just_connected' => $result === 'conectado' && $state === 'connected',
                'error' => $state === 'error' ? (self::ERRORS[$reason] ?? 'Não foi possível conectar o Mercado Livre.') : null,
                'summary' => $summary,
                'still_connected' => $state === 'error' && $summary !== null && $summary->isConnected(),
            ],
            'wa' => $this->whatsApp($tenant, $query),
            'affiliate' => [
                'mode' => $status->affiliateMode($tenant->installationId),
                'declaration' => $this->container->get(MediaDeclarationRepository::class)->current($tenant->installationId),
                'text' => MediaDeclaration::TEXT,
                'feedback' => is_string($query['afiliado'] ?? null) ? $query['afiliado'] : null,
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function whatsApp(TenantContext $tenant, array $query): array
    {
        $card = $this->container->get(ManageWhatsAppConnection::class)->card($tenant);
        $feedback = is_string($query['wa'] ?? null) ? $query['wa'] : null;
        $reason = is_string($query['motivo'] ?? null) ? $query['motivo'] : '';
        $errorCode = $feedback === 'erro' ? $reason : $card->errorCode;

        return [
            'card' => $card,
            'feedback' => in_array($feedback, ['conectando', 'desconectado', 'aguarde'], true) ? $feedback : null,
            'error' => $errorCode === null || $errorCode === '' ? null : (self::WA_ERRORS[$errorCode] ?? 'Não foi possível concluir a operação no WhatsApp.'),
            // Atualiza a página enquanto o QR/código vale (o QR muda no provedor; nunca exibimos um antigo).
            'refresh' => $card->state === WhatsAppCard::WAITING ? min(15, max(5, (int) $card->secondsLeft)) : null,
        ];
    }
}
