---
tipo: correcao
---
## Changelog
- Push Processual: quando uma página do DJEN falhava no meio da paginação de uma OAB, `SincronizarPublicacoesDjenUseCase` descartava todos os itens já recebidos daquela OAB na execução. Agora as páginas anteriores à falha são processadas e a falha continua registrada para o aviso da tarefa. A dedupe por `djenId` evita duplicar na execução seguinte. A fase de processos (por número) não mudou.

## Para o cliente
- Quando o tribunal falha no meio da consulta de uma OAB, as publicações que já tinham chegado não se perdem mais. Antes, duas falhas seguidas podiam fazer publicações deixarem de aparecer no Push Processual.
