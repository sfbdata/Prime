---
tipo: alteracao
---
## Changelog
- Convênios, PR 3 da spec `docs/specs/convenios.md`: `SincronizarVinculosDeConvenioUseCase` e a regra `RegraDosVinculosDeConvenio` gravam os vínculos dos escritórios com o Convênio OAB (UF válida e não desligada, OAB do criador não divergente, chave geral ligada).
- Tarefa diária `app:convenios:sincronizar-vinculos` às 2h10. A primeira volta completa grava `convenio.vinculosSincronizadosEm` e acaba com o convênio OAB implícito.
- OAB divergente ou ausente: prova pendente por 30 dias, depois o vínculo fecha. Fecha também por estado, pela UF sem convênio e pela validade.
- Ganchos: mudança de estado nos dados do escritório e mudança da UF no painel "Convênio OAB por estado" acertam os vínculos na hora; o painel recarrega a lista de contas depois de gravar uma UF.

## Para o cliente
- O BlueJus passou a registrar quais escritórios estão no Convênio OAB, conferindo todo dia o estado do escritório e a OAB de quem o criou. Quem tem OAB válida continua com o convênio como antes.
- Na área do Master, o aviso verde de convênio embaixo do plano aparece só nas contas que estão no Convênio OAB.
