# Proveniência do código

- Este repositório foi iniciado do zero, com histórico Git próprio.
- Nenhum arquivo, trecho de código, migration, template, asset, documentação ou mecanismo de licença de outro produto foi copiado.
- Um projeto interno anterior foi consultado apenas como **referência conceitual** dos problemas já conhecidos (ex.: necessidade de fila persistente, isolamento por nicho, riscos de integrações não oficiais).
- A integração com o Mercado Livre segue exclusivamente a documentação oficial em developers.mercadolivre.com.br.
- Um teste automatizado (`tests/Unit/Architecture/IndependenceTest.php`) verifica a cada execução que não há referências ao produto anterior nem uso de mecanismos proibidos.
