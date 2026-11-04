---
tipo: alteracao
---
## Changelog
- Importar pastas em lote copia a organização do computador (D12): as pastas abaixo de cada pasta criada viram subpastas dos Documentos, pela rota do explorador (`pasta_secao_criar`, `CriarPastaSecaoUseCase`), e cada arquivo vai com `secao_id` para a sua. A criação da pasta devolve `secaoUrl` e `secaoToken` só para quem pode editar. Até 10 níveis; o que passar fica no 10º, com o resto na descrição. O caminho na descrição (D11) deixa de ser o padrão.
- `scripts/lote-teste/gerar.py` mostra a subpasta de cada arquivo e ganha o cenário 11 (subpastas fundas).

## Para o cliente
- Ao importar pastas em lote, a organização do seu computador vem junto: se a pasta do cliente tem "Processo" e, dentro dela, "Recursos", os documentos aparecem nas mesmas subpastas dentro de Documentos, em vez de ficarem todos soltos.
