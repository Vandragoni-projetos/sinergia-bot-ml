<?php

declare(strict_types=1);

namespace Sinergia\Application\Onboarding;

/**
 * Passos do onboarding (plano F1 v2.1: Conectar → Nichos → Destinos → 1º lote de links → Ativar), derivados SÓ dos
 * dados da conta. Critérios de conclusão:
 *   1 mercado_livre  conexão Mercado Livre ativa (a declaração de Mídias da conta é opcional e não trava o onboarding)
 *   2 nichos         pelo menos 1 subnicho ativado (com categoria aprovada no catálogo)
 *   3 whatsapp       WhatsApp da conta conectado
 *   4 destinos       pelo menos 1 destino ATIVO (elegível, configurado com nicho/subnicho e não pausado)
 *   5 links          pelo menos 1 oferta encontrada E pelo menos 1 dessas ofertas com link de afiliado ativo da conta
 * Ativar só com os 5 concluídos.
 */
final class OnboardingChecklist
{
    /** @var list<OnboardingStep> */
    public readonly array $steps;

    public function __construct(public readonly OnboardingFacts $facts)
    {
        $f = $facts;
        $this->steps = [
            new OnboardingStep(
                'mercado_livre', 'Conectar o Mercado Livre',
                $f->mercadoLivreConnected,
                $f->mercadoLivreConnected ? 'Mercado Livre conectado' : 'Mercado Livre não conectado',
                'Conecte a sua conta do Mercado Livre.',
                '/conexoes', 'Ir para Conexões',
            ),
            new OnboardingStep(
                'nichos', 'Escolher o que vender',
                $f->activeSubniches > 0,
                $f->activeSubniches > 0 ? sprintf('%d subnicho(s) ativo(s)', $f->activeSubniches) : 'Nenhum subnicho escolhido',
                'Marque pelo menos um subnicho e salve.',
                '/nichos', 'Ir para Nichos',
            ),
            new OnboardingStep(
                'whatsapp', 'Conectar o WhatsApp',
                $f->whatsAppConnected,
                $f->whatsAppConnected ? 'WhatsApp conectado' : 'WhatsApp não conectado',
                'Conecte o número que vai publicar, pelo QR code ou código de pareamento.',
                '/conexoes#whatsapp', 'Conectar WhatsApp',
            ),
            new OnboardingStep(
                'destinos', 'Escolher onde publicar',
                $f->readyDestinations > 0,
                match (true) {
                    $f->readyDestinations > 0 => sprintf('%d destino(s) pronto(s)', $f->readyDestinations),
                    $f->destinations > 0 => 'Nenhum destino pronto ainda',
                    default => 'Nenhum destino cadastrado',
                },
                $f->destinations > 0
                    ? 'Deixe um destino elegível (declarações), escolha o nicho e clique em Ativar no destino.'
                    : 'Atualize a lista do WhatsApp e adicione um canal ou grupo.',
                '/destinos', 'Ir para Destinos',
            ),
            new OnboardingStep(
                'links', 'Preparar os primeiros links',
                $f->candidates > 0 && $f->candidatesWithLink > 0,
                match (true) {
                    $f->candidates === 0 => 'Nenhuma oferta encontrada ainda',
                    $f->candidatesWithLink === 0 => sprintf('%d oferta(s) aguardando link', $f->candidates),
                    default => sprintf('%d oferta(s) com link pronto', $f->candidatesWithLink),
                },
                $f->candidates === 0
                    ? 'Busque as ofertas dos seus nichos.'
                    : 'Copie as URLs, gere os links no Gerador de Links do Mercado Livre, cole e confirme.',
                $f->candidates === 0 ? '/comecar#ofertas' : '/fila#afiliados',
                $f->candidates === 0 ? 'Buscar ofertas' : 'Ir para Links',
            ),
        ];
    }

    public function canActivate(): bool
    {
        return $this->firstPending() === null;
    }

    public function firstPending(): ?OnboardingStep
    {
        foreach ($this->steps as $step) {
            if (!$step->done) {
                return $step;
            }
        }

        return null;
    }

    public function doneCount(): int
    {
        return count(array_filter($this->steps, static fn (OnboardingStep $s): bool => $s->done));
    }
}
