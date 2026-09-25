<?php

declare(strict_types=1);

namespace Sinergia\Web\View;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/** Renderiza templates Twig (escape automático de HTML) em respostas sem cache. */
final class Views
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, $data));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
