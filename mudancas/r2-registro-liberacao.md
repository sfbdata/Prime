---
tipo: alteracao
---
## Changelog
- Espelho R2 (etapa B da liberação do disco): migration `Version20261103180000` cria `armazenamento_liberacao` (registro de liberação, só aditiva, com índice único parcial de uma linha aberta por chave). Nada grava nela ainda: o comando de liberar é a etapa C.
- Reconciliação (`armazenamento:reconciliar-espelho`): arquivo ausente no disco com liberação aberta e o R2 conferindo vira `liberado` (não alerta, contado à parte); presente no disco com liberação aberta vira `restaurado_sem_registro` (aviso, sem falhar a rodada). Sem liberação, nada muda.
- Leitura de recuperação: aceita como prova também a liberação aberta, quando a outbox já não tem linha da chave (retenção de 90 dias), conferindo o SHA do que vem do R2.

## Para o cliente
- Preparação para tirar do servidor os arquivos que já estão guardados com segurança na nuvem, abrindo espaço sem perder nada. Por enquanto nenhum arquivo sai do lugar, e documentos, fotos e anexos continuam abrindo como sempre.
