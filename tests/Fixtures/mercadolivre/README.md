# Fixtures do Mercado Livre

Respostas usadas pelos testes automatizados (nenhum teste chama a API real).

| Arquivo | Origem |
|---|---|
| `highlights_category.json` | Estrutura do exemplo oficial da página "Mais vendidos no Mercado Livre" (`/highlights/MLB/category/{id}`), reduzida a 5 entradas. |
| `category_leaf.json` / `category_branch.json` | Estrutura do exemplo oficial de `/categories/{id}` (páginas "Categorização de produtos" / "Domínios e Categorias"), com valores fictícios. |
| `site_roots.json` | Estrutura documentada de `/sites/{site_id}/categories` com valores fictícios. |

Não contêm tokens, cookies nem dados pessoais.
