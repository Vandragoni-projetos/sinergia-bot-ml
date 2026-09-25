<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Affiliate\BatchPreview;
use Sinergia\Application\Affiliate\ReceivedLine;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Infrastructure\Persistence\AffiliateLinkRepository;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\View\Views;

/**
 * GET /fila — na etapa 7, só o bloco de afiliados: "Aguardando link (N)", Copiar URLs, Colar links gerados,
 * pré-visualização e confirmação. Planejamento, agendamento e envio chegam na etapa 8.
 */
final class QueuePageAction
{
    public const array MESSAGES = [
        'batch_not_found' => 'Lote não encontrado.',
        'batch_closed' => 'Este lote já foi concluído, descartado ou expirou. Gere um novo com "Copiar URLs".',
        'nothing_to_export' => 'Nenhum produto aguardando link.',
        'empty_paste' => 'Cole os links gerados antes de pré-visualizar.',
        'paste_too_large' => 'O texto colado é grande demais para um lote.',
        'not_previewed' => 'Cole os links e confira a pré-visualização antes de confirmar.',
        'nothing_selected' => 'Escolha pelo menos um produto para associar.',
        'invalid_line' => 'Só linhas com link válido podem ser associadas.',
        'unknown_item' => 'Produto inválido para este lote.',
        'item_twice' => 'Cada produto só pode receber um link.',
        'id_conflict' => 'O link indica outro produto e não pode ser associado a este.',
    ];

    private const array SUCCESS = [
        'exportado' => 'Lote criado. Copie as URLs, gere os links no Gerador oficial e cole o resultado abaixo.',
        'previa' => 'Confira a pré-visualização. Nada foi gravado ainda.',
        'confirmado' => 'Links gravados na biblioteca.',
        'descartado' => 'Lote descartado. Nada foi gravado.',
    ];

    public const array FORMAT = [
        'valid' => 'Link válido',
        'invalid' => 'Não é um link',
        'invalid_domain' => 'Domínio não aceito',
        'empty' => 'Linha vazia',
        'duplicate' => 'Link repetido',
    ];

    public const array EVIDENCE = [
        'product_id' => 'Forte — o link indica este produto',
        'position_only' => 'Fraca — só pela posição',
        'conflict' => 'Conflito — o link indica outro produto',
        'none' => '—',
    ];

    public const array ANOMALIES = [
        BatchPreview::COUNT_MISMATCH => 'a quantidade de links é diferente da quantidade de produtos',
        BatchPreview::EMPTY_LINE => 'há linha vazia no meio',
        BatchPreview::DUPLICATE => 'há link repetido',
        BatchPreview::INVALID_DOMAIN => 'há link de domínio não aceito',
        BatchPreview::INVALID_LINE => 'há linha que não é link',
        BatchPreview::ID_CONFLICT => 'há link que indica outro produto',
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
        $store = $this->container->get(AffiliateLinkRepository::class);
        $awaiting = $store->productsAwaitingLink($tenant->installationId, 500);
        $batch = $store->openBatch($tenant->installationId, $this->container->get(Clock::class)->now());
        $ok = is_string($query['ok'] ?? null) ? $query['ok'] : null;
        $error = is_string($query['erro'] ?? null) ? $query['erro'] : null;

        $lines = [];
        foreach ($batch->lines ?? [] as $line) {
            $proposedKey = '';
            foreach ($batch->items as $item) {
                if ($line->proposedPosition === $item->position) {
                    $proposedKey = $item->itemKey;
                }
            }
            $lines[] = [
                'no' => $line->lineNo,
                'text' => $line->url,
                'format' => self::FORMAT[$line->formatStatus] ?? $line->formatStatus,
                'valid' => $line->formatStatus === ReceivedLine::VALID,
                'evidence' => self::EVIDENCE[$line->evidence] ?? $line->evidence,
                'strong' => $line->evidence === ReceivedLine::EVIDENCE_PRODUCT_ID,
                'proposed' => $proposedKey,
            ];
        }

        return $this->container->get(Views::class)->render($response, 'panel/queue.twig', [
            'tenant' => $tenant,
            'current' => 'fila',
            'nav' => PanelPageAction::PAGES,
            'page' => PanelPageAction::PAGES['fila'],
            'csrf' => $this->container->get(Csrf::class)->tokenFor('sess|' . $token),
            'awaiting' => $awaiting,
            'batch' => $batch,
            'lines' => $lines,
            'anomalies' => array_map(static fn (string $a): string => self::ANOMALIES[$a] ?? $a, $batch->anomalies ?? []),
            'notice' => $ok === null ? null : (self::SUCCESS[$ok] ?? null),
            'report' => is_string($query['resumo'] ?? null) && preg_match('/^\d{1,3}-\d{1,3}-\d{1,3}-\d{1,3}$/', $query['resumo']) === 1 ? explode('-', $query['resumo']) : null,
            'error' => $error === null ? null : (self::MESSAGES[$error] ?? 'Não foi possível concluir a operação.'),
        ]);
    }
}
