<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Affiliate\BatchRejected;
use Sinergia\Application\Affiliate\ManualBatchAffiliateLinkProvider;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Infrastructure\Persistence\AffiliateLinkRepository;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;

/**
 * POSTs do bloco de afiliados da Fila, sempre para a conta AUTENTICADA. O lote é procurado pela chave aleatória
 * DENTRO da conta. Nenhum link recebido é aberto, resolvido ou alterado.
 */
final class AffiliateBatchAction
{
    public const string EXPORT = 'export';
    public const string PASTE = 'paste';
    public const string CONFIRM = 'confirm';
    public const string CANCEL = 'cancel';

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

        $provider = $this->container->get(ManualBatchAffiliateLinkProvider::class);
        $key = (string) ($args['lote'] ?? '');
        try {
            switch ($this->operation) {
                case self::EXPORT:
                    $products = $this->container->get(AffiliateLinkRepository::class)->productsAwaitingLink($tenant->installationId, ManualBatchAffiliateLinkProvider::MAX_BATCH_ITEMS);
                    $provider->exportBatch($tenant->installationId, $tenant->userId, $products);

                    return self::redirect($response, 'ok=exportado');
                case self::PASTE:
                    $provider->previewImport($tenant->installationId, $key, is_string($body['links'] ?? null) ? $body['links'] : '');

                    return self::redirect($response, 'ok=previa');
                case self::CONFIRM:
                    $associations = [];
                    foreach (is_array($body['associar'] ?? null) ? $body['associar'] : [] as $lineNo => $itemKey) {
                        if (is_string($itemKey) && ctype_digit((string) $lineNo)) {
                            $associations[(int) $lineNo] = $itemKey;
                        }
                    }
                    $report = $provider->confirmImport($tenant->installationId, $key, $associations, $tenant->userId);

                    return self::redirect($response, 'ok=confirmado&resumo=' . implode('-', [$report->created, $report->replaced, $report->reused, $report->leftUnmatched]));
                default:
                    $provider->cancel($tenant->installationId, $key);

                    return self::redirect($response, 'ok=descartado');
            }
        } catch (BatchRejected $e) {
            return self::redirect($response, 'erro=' . $e->reason);
        }
    }

    private static function redirect(ResponseInterface $response, string $query): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', '/fila?' . $query . '#afiliados');
    }
}
