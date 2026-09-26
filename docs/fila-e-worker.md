# Fila, planejamento, envio e worker (F1, etapa 8)

Código: `src/Application/Queue/` (QueuePlanner, QueueSender, BotWorker, MessageBuilder, SendWindow, ManageQueue).
Execução: `php bin/console bot:worker` (laço a cada 60 s; `--once` para um ciclo). Nenhum envio fora do `WhatsAppProvider`.

## Estados da fila (`dispatch_queue.status`)
| Estado | Significado |
|---|---|
| `awaiting_affiliate_link` | Planejado, mas o produto não tem link ativo DA CONTA. Nunca é trocado por URL comum. |
| `pending_approval` | Destino em modo manual: espera Aprovar/Pular na tela Fila. |
| `scheduled` | Pronto para sair no horário planejado (auto, ou aprovado). |
| `sending` | Reservado por UM worker (claim_token). |
| `sent` | Enviado; guarda a mensagem enviada (message_json) e o id do provedor. |
| `skipped` | Pulado pelo cliente ou descartado na revalidação (produto/filtros). Não volta ao destino. |
| `failed` | Falha definitiva, inclusive resultado incerto (nunca reenviado). |
| `cancelled` | Destino removido. |

`UNIQUE(installation_id, destination_id, ml_product_id)`: um produto nunca se repete no mesmo destino (qualquer estado).

## Planejamento (a cada ciclo, por conta com bot ativo)
- Só destinos `active` (elegíveis, configurados e não pausados) e só candidatos da última seleção da PRÓPRIA conta.
- Horizonte de 24 h. Primeiro horário = max(agora, último planejado + intervalo, último enviado + intervalo),
  ajustado para dentro da janela diária (fuso da conta). Seguintes: + intervalo, sempre ajustados à janela.
- Rodízio entre os subnichos do destino (retoma depois do último usado). Dentro do subnicho, o candidato de melhor
  posição ainda não usado no destino.
- Link ativo da conta → `scheduled` (auto) ou `pending_approval` (manual); sem link → `awaiting_affiliate_link`.
  Quando o link é confirmado (etapa 7), o item é promovido no ciclo seguinte.

## Estado real do WhatsApp (antes de reservar qualquer item)
Quando há item vencido, o worker consulta `WhatsAppProvider::status` uma vez por ciclo e conta. Desconectado → o
estado é gravado (o painel passa a mostrar) e nenhum item é reservado nem alterado: continuam `scheduled`. Falha na
consulta → nada é enviado no ciclo. Nenhuma chamada de envio acontece nesses casos, então não há risco de duplicar.

## Revalidação antes do envio (sempre com dados lidos na hora, da conta do item)
bot da conta ativo → destino existe e está `active` → modo manual exige decisão → janela permite agora →
cadência (último envio + intervalo ≤ agora) → link ATIVO da conta para o produto (o substituto, se houve troca) →
WhatsApp da conta conectado → produto e oferta ATUAIS no ML com o token da conta → produto ativo, com página e foto →
filtros do nicho (preço mín./máx., desconto mín.) com o preço atual.
Bloqueios temporários (janela, cadência, destino pausado/inelegível, bot pausado, ML indisponível) devolvem o item
para `scheduled` sem enviar. Produto indisponível ou fora dos filtros → `skipped`.

## Mensagem fixa (sem IA)
```
{título do produto}

De R$ {preço anterior} por R$ {preço atual} ({desconto}% OFF)    ← só com preço anterior válido (> atual)
Por R$ {preço atual}                                             ← sem preço anterior válido

{link de afiliado exatamente como gravado}
```
Enviada como imagem (foto do produto) com essa legenda, pelo `WhatsAppProvider::sendImage`. O preço é sempre o
atual do momento do envio; desconto = floor((anterior − atual) × 100 / anterior).

## Retry e idempotência
- Reserva atômica `scheduled → sending` (UPDATE condicionado ao status): um item, um worker. Trava por conta
  (`GET_LOCK`, sem espera) evita dois workers na mesma conta.
- `send_started_at` é gravado ANTES de chamar o provedor.
- Retry (no máximo 3 tentativas) SÓ quando o provedor comprovadamente não processou:
  429 e 503 (5, 15, 45 min ou Retry-After) · 401/403 (30 min).
- Resultado incerto → `failed`, NUNCA reenviado: timeout, erro de rede, resposta inválida, 5xx ≠ 503, outros.
- 404 → `failed` e o destino fica inelegível na hora.
- Worker interrompido: após 10 min em `sending`, sem envio iniciado → volta para `scheduled`;
  com envio iniciado → `failed` (`interrupted_unknown_outcome`).
- Cada tentativa fica em `dispatch_attempts` (resultado, código de erro, status HTTP, horário). Nunca token.

## Pausa e revalidação periódica
- `installations.bot_status` (nasce `paused`): Pausar bot interrompe planejamento e envio da conta sem apagar
  fila, biblioteca, destinos ou configurações.
- A cada ~6 h o worker revalida os destinos da conta (mesma sincronização da tela Destinos) e renova a seleção de
  ofertas (etapa 4). Destino que fica inelegível para de receber imediatamente.
