# Copy das ofertas por IA (etapa 11B)

A IA **só redige** um texto criativo curto. O BotML continua sendo a única fonte de verdade para título, preço,
preço anterior, desconto, fatos comerciais e link de afiliado.

## Fluxo

```
produto do nicho/subnicho → dados atuais do Mercado Livre (revalidados no envio)
  → OfferCopyGenerator (gancho + chamada) → CopyValidator → MessageBuilder v2
  → markSendStarted → WhatsAppProvider (Evolution) → grupo
```

A copy é gerada no `QueueSender`, **antes** de marcar o envio iniciado. Se algo falhar, a oferta sai com a
mensagem fixa **v1**. A IA nunca impede a publicação.

## Configuração (somente por ambiente)

| Variável | Valores | Padrão |
|---|---|---|
| `AI_COPY_PROVIDER` | `none` \| `openai` | `none` (mensagem v1, nenhuma chamada externa) |
| `OPENAI_API_KEY` | chave da OpenAI | vazia → cada envio cai na v1 (`not_configured`) |
| `OPENAI_MODEL` | nome do modelo | `gpt-4.1-mini` |
| `OPENAI_TIMEOUT_SECONDS` | 1–30 | `10` |

Com um valor inválido (provedor desconhecido, modelo mal formado, timeout fora da faixa), o BotML **não para**: usa
o gerador sem IA e registra `offer_copy.config_invalid` com o nome da variável.

O modelo escolhido precisa aceitar `temperature` e `response_format` do tipo `json_schema` no Chat Completions. Se não
aceitar, a OpenAI devolve erro HTTP, e todo envio sai na v1 (`offer_copy.fallback_v1`, `reason=http_error`).

## O que a IA recebe e devolve

- **Recebe** (JSON delimitado, como dado): título, nicho, subnicho, `frete_gratis`, `loja_oficial` (fatos booleanos
  do Mercado Livre) e o tom. **Nunca** recebe preço, preço anterior, desconto, link, telefone ou dado financeiro:
  `OfferCopyRequest` não tem campo para eles.
- **Devolve** só `{"gancho": "...", "chamada": "..."}`, com saída estruturada estrita (sem campos extras).
- Chamada: `POST https://api.openai.com/v1/chat/completions`, uma única tentativa, `temperature` 0.7 e
  `max_completion_tokens` 200. Versão do prompt: `copy-v1`.

## Validação (`CopyValidator`)

Qualquer regra violada → fallback v1. Só o nome da regra vai para o log.

- Cada campo em uma linha, sem caracteres de controle. Tamanho de 3–100 caracteres no gancho e 3–60 na chamada. No
  máximo 2 emojis por campo.
- Sem formatação/marcação (`* _ ~ \` < > { } [ ] | \`): a formatação é do BotML.
- Sem URL, domínio, `www`, `@`, símbolo de moeda ou `%`.
- Número só se aparecer no próprio título (ex.: "4L" em "Air Fryer 4L"). Isso bloqueia preços, parcelas, telefones e
  prazos.
- **Termos proibidos sempre:** preço, valor, desconto, OFF, Pix, parcelas, juros, à vista, cupom, reais, barato,
  garantia, estoque, últimas, hoje, amanhã, acaba, esgota, limitado, relâmpago, cashback, brinde, economia, metade,
  dobro, Mercado Livre, WhatsApp, telefone, ligue, gratuito.
- **Termos condicionais:** "frete/grátis/entrega" só com frete grátis confirmado; "oficial/original" só com loja
  oficial confirmada.

A lista é conservadora de propósito: um falso positivo (ex.: "acabamento") só faz a oferta sair na v1.

## Mensagens

v1 (fixa, também o fallback):

```
{título}

De R$ {anterior} por R$ {atual} ({desconto}% OFF)      ← ou "Por R$ {atual}"

{link exatamente como gravado}
```

v2 (com copy validada). Título, linha de preço e link são idênticos aos da v1:

```
{gancho}

{título}

De R$ {anterior} por R$ {atual} ({desconto}% OFF)

{chamada}

{link exatamente como gravado}
```

## Registro e segurança

- `message_json` guarda só metadados seguros em `copy`: `provider`, `model`, `prompt_version` e `fallback`. O campo
  `format` fica `v1` ou `v2`.
- Logs: `offer_copy.generated` (provedor, modelo, duração) e `offer_copy.fallback_v1` (provedor, modelo, motivo,
  regra, HTTP, duração). **Nunca** chave, prompt ou resposta da IA.
- A chave só existe como `SensitiveValue` e só sai no cabeçalho `Authorization`. Não há tela de configuração nem
  armazenamento em banco nesta etapa.
