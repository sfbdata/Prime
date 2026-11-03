---
tipo: alteracao
---
## Changelog
- Importar pastas em lote: o número no começo do nome da subpasta sai do nome do cliente ("001 - Fulano", "01_Fulano", "1. Fulano" viram "FULANO"). Nome só com número fica como está. O gerador `scripts/lote-teste/gerar.py` segue a mesma regra (cenário 04).

## Para o cliente
- Ao importar pastas em lote, pastas numeradas como "001 - Maria" passam a sugerir o cliente "MARIA", sem o número na frente. O nome continua editável antes de importar.
