<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Copy;

/**
 * Gerador do texto CRIATIVO curto de uma oferta (gancho + chamada). Nunca recebe nem devolve preço, preço anterior,
 * desconto, link, telefone ou qualquer dado objetivo: esses dados continuam exclusivamente com o BotML, que monta a
 * mensagem final. A resposta é sempre validada (CopyValidator) antes de ser usada; qualquer falha → mensagem v1.
 */
interface OfferCopyGenerator
{
    /** 'none' (sem IA) ou o nome do provedor (ex.: 'openai'). Vai para o message_json. */
    public function provider(): string;

    /** Modelo usado (null sem IA). Vai para o message_json. */
    public function model(): ?string;

    /** Versão do prompt (null sem IA). Vai para o message_json. */
    public function promptVersion(): ?string;

    /**
     * @return ?OfferCopy null = sem IA configurada (usar a mensagem fixa v1, sem ser fallback)
     *
     * @throws OfferCopyFailure qualquer falha do provedor (a mensagem v1 é usada como fallback)
     */
    public function generate(OfferCopyRequest $request): ?OfferCopy;
}
