<?php

declare(strict_types=1);

namespace Sinergia\Web;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;

/**
 * Erros HTTP em JSON, sem stack trace nem detalhes internos (salvo APP_DEBUG fora de produção).
 */
final class JsonErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        if ($status >= 500) {
            $this->logger->error('http.unhandled_error', [
                'path' => HttpApp::requestPath($request),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        $payload = ['status' => 'error', 'code' => $status, 'message' => match ($status) {
            404 => 'Recurso não encontrado.',
            405 => 'Método não permitido.',
            default => $status >= 500 ? 'Erro interno.' : 'Requisição inválida.',
        }];
        if ($this->debug && $displayErrorDetails) {
            $payload['debug'] = ['exception' => $exception::class];
        }

        return HttpApp::jsonResponse($this->responses->createResponse(), $payload, $status);
    }
}
