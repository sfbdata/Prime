---
tipo: interno
---
## Changelog
- Pasta: cada upload de documento passa a ter um registro do arquivo físico (`arquivo_armazenado`) com código `DOC-XXXX-XXXX-XXXX`. Sem mudança visível ainda (fatia C1).
- Purga da lixeira: o arquivo só sai do disco quando nenhum documento do escritório o referencia mais.
- Migration `Version20261031130000`: tabela nova e coluna `pasta_documento.arquivo_id` (nula, sem backfill).
- Purga do escritório inclui a tabela nova.
