# Seletor de ofertas — regra completa (F1, etapa 4)

Transforma os nichos/subnichos escolhidos por UMA conta em ofertas candidatas. Termina na seleção:
não envia, não gera link de afiliado, não agenda. Código: `src/Application/Offer/SelectOffers.php` e `OfferChooser.php`.
Execução manual: `php bin/console offers:select <slug-da-conta>` (`--show` só lista a última execução).

## 1. Entrada
- Subnichos ativados pela conta (`account_subniches`) + filtros do nicho (`account_niches`).
- Para cada subnicho, só as categorias `approved` da curadoria (`subniche_categories`), com os `expected_domains`.
- A consulta usa o token Mercado Livre **da própria conta** (`StoredTokenProvider` por `installation_id`).
  Sem credencial própria válida, nada é consultado (nunca se usa o token de outra conta).

## 2. Passos
1. **Ranking oficial** `GET /highlights/MLB/category/{id}` de cada categoria aprovada (até 20 posições).
2. **Só catálogo**: aceita apenas `type = PRODUCT` com ID `MLB` + dígitos. `USER_PRODUCT` (MLBU…) e `ITEM` são
   descartados como `not_catalog_product` e nunca viram chamada. `/items/{id}`, `/user-products/{id}`, busca textual e
   scraping não são usados.
3. **Ordem determinística** das ocorrências: posição no ranking ↑, ordem do nicho, ordem do subnicho, ID da categoria.
4. **Deduplicação por `ml_product_id`**: o produto é consultado e avaliado uma vez por execução. As ocorrências são
   percorridas na ordem acima e o produto é atribuído à **primeira ocorrência cujas regras ele cumpre** (domínio e filtros
   daquele subnicho). Se nenhuma cumprir, o motivo registrado é o da primeira ocorrência.
5. **Produto** `GET /products/{id}`, descartado quando:
   - `status` informado e diferente de `active` → `product_inactive`;
   - `domain_id` fora dos `expected_domains` da categoria de origem → `domain_mismatch` (mesmo em categoria aprovada);
   - sem `permalink` https em `mercadolivre.com.br` → `no_permalink`;
   - filtro "Só produtos com foto" ligado e nenhuma foto https → `no_photo`.
6. **Oferta** (regra determinística, `OfferChooser`):
   1. Se `/products/{id}` traz `buy_box_winner` elegível, ele é a oferta (`buy_box`): é o anúncio que a página do
      produto (permalink) mostra; anunciar outro preço criaria divergência. Nesse caso `/items` não é chamado.
   2. Senão, `GET /products/{id}/items` e, entre as ofertas elegíveis, vence: menor preço → frete grátis → loja
      oficial → maior desconto → menor `item_id` (ordem textual) (`lowest_price`). A ordem da API nunca é critério.
   3. Elegível = `currency_id = BRL`, preço > 0 e `condition = new` (condição ausente é aceita).
   4. Nenhuma elegível → `no_offer`.
7. **Filtros da conta** sobre a oferta escolhida: `price_below_min`, `price_above_max`, `discount_below_min`.
8. **Saída**: candidatos ordenados por posição no ranking, nicho, subnicho e ID do produto, gravados em
   `account_offer_candidates` com o snapshot do preço (a etapa 8 revalida antes de enviar).

## 3. Preços, desconto, fotos e permalink
| Campo | Tratamento |
|---|---|
| Preço atual | `price` da oferta escolhida, em centavos (arredondamento de `price × 100`). |
| Preço original | `original_price` só vale se for **maior** que o atual; senão é tratado como ausente. |
| Desconto | `floor((original − atual) × 100 / original)`; 0 sem preço original válido. Nunca é calculado a partir de outras ofertas. |
| Sem preço original | Candidato válido com desconto 0 e preço original nulo; é descartado se a conta exigir desconto mínimo. |
| Fotos | Primeira foto `https` de `pictures` (`secure_url`, senão `url`); fotos http são ignoradas. |
| Permalink | `permalink` do produto de catálogo, só se for `https://` em `mercadolivre.com.br`. |

## 4. Exemplos (dos testes)
| Produto | Situação | Resultado |
|---|---|---|
| Potes (ofertas R$ 120 de R$ 200 e R$ 99,90 de R$ 149,90) | API devolve a mais cara primeiro | R$ 99,90, −33% (`lowest_price`) |
| Air fryer com `buy_box_winner` R$ 161 de R$ 219,90 | — | R$ 161, −26% (`buy_box`), sem chamar `/items` |
| Panela em "Caçarolas" (pos. 1) e "Jogo de Panelas" (pos. 2) | Duplicado | 1 candidato, atribuído a "Caçarolas" |
| Colchão inflável em "Potes para Alimentos" | Desvio real da curadoria | `domain_mismatch` |
| Impermeabilizante em "Protetor solar"; faqueiro em "Organizadores de Talheres" | Desvios reais | `domain_mismatch` |
| Organizador sem foto | Conta A exige foto / conta B não | A: `no_photo`; B: aceito |
| Organizador de talheres R$ 50 sem preço anterior | A exige desconto ≥ 10% / B sem mínimo | A: `discount_below_min`; B: aceito com 0% |
| Panela R$ 800 / R$ 20 | Faixa R$ 30–500 | `price_above_max` / `price_below_min` |

## 5. Cache (dados públicos, sem `installation_id`)
| Dado | Tabela | Validade | Em caso de erro da API |
|---|---|---|---|
| Ranking | `ml_ranking_snapshots` + `ml_ranking_entries` | 6 h | usa o anterior até 48 h (execução `partial`) |
| Produto | `ml_products` | 1 h (404/403: cache negativo 24 h) | dados cadastrais até 7 dias, mas a oferta exige nova consulta |
| Oferta escolhida | `ml_product_offers` | 1 h | não há fallback: sem oferta atual, o produto sai (`offer_unavailable`) |

O `buy_box_winner` não é cacheado: a oferta sempre parte de um `/products/{id}` consultado na execução.
Dados privados por conta: `offer_selection_runs` (status, chamadas, estatísticas; mantém as 10 últimas) e
`account_offer_candidates`.

## 6. Respostas do Mercado Livre
| Resposta | Efeito |
|---|---|
| 401, credencial ausente, `refresh` inválido | Execução interrompida (`auth_failed`), nenhum candidato novo; os anteriores continuam. |
| 403 no ranking | Categoria pulada (`categories_failed.forbidden`), execução `partial`. |
| 403/404 no produto | Produto descartado (`product_unavailable`) e cache negativo por 24 h. |
| 404 em `/items` | Produto sem oferta (`no_offer`). |
| 429 | Para de chamar a API nesta execução; o restante só usa cache (`partial`). |
| 5xx, timeout, resposta fora do contrato | Usa cache antigo dentro do limite; senão pula (`partial`). |
| Orçamento | No máximo 300 chamadas por execução; excedeu → só cache (`budget_exhausted`). |

## 7. Isolamento
- Público (compartilhado): rankings, produtos e oferta escolhida. Não contêm nada de nenhuma conta.
- Privado: execuções e candidatos, sempre filtrados por `installation_id`; a fonte de catálogo é criada com o token
  da conta que executa.
