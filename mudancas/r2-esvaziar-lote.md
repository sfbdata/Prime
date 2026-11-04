---
tipo: alteracao
---
## Changelog
- Espelho R2 (etapa 9): novo comando `armazenamento:esvaziar-lote --lote=ID`, que apaga de vez as cópias de um lote em `.liberados/`. Simula por padrão; com `--executar`, exige `--confirmo-backup-testado`.
- Cada cópia só sai depois de 7 dias da liberação, sem exclusão registrada, com o envio verificado igual na outbox, com a cópia igual à da liberação e com um download inteiro do R2 que confere o SHA-256 e o tamanho. A linha de liberação continua aberta, e `armazenamento:restaurar-lote` traz o arquivo de volta baixando do R2.

## Para o cliente
- Os documentos antigos que já estão guardados na nuvem deixam de ocupar espaço duas vezes no servidor. Eles continuam abrindo normalmente pelo sistema; o primeiro acesso a um deles pode levar alguns segundos a mais.
