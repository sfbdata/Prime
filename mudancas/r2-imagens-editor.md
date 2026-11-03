---
tipo: alteracao
---
## Changelog
- Espelho R2: `armazenamento:carregar-acervo --do-disco` carrega as categorias sem tabela no banco (imagens do editor das pastas) listando a subpasta de cada escritório. Só vale para `pasta_imagem_editor` e `cobranca_documento`; idempotente, com `--simular` e `--limite`.

## Para o cliente
- As imagens antigas coladas no texto das pastas passam a ter cópia de segurança na nuvem, como os documentos.
