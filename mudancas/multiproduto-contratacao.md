---
tipo: interno
---
## Changelog
- Tabela `contratacao` (migration `Version20261123180000`, aditiva): os produtos que cada escritório tem, com situação (ativa ou encerrada), início, fim, origem (cadastro, dono ou Master) e quem fez a última mudança. CHECK nos valores e índice único parcial: uma linha ativa por produto. Nenhum escritório ganha linha: sem linha, o escritório continua completo.
- Entidade `Contratacao` (TenantAware, Auditavel) com `ativar()` e `encerrar()`; religar cria linha nova e a encerrada fica no histórico.
- `ProdutosDoEscritorio`: diz quais produtos valem para o escritório (sem linha ou `completo` ativa = todos; outras ativas = só elas; só encerradas = nenhum). Ainda sem uso: a guarda chega num PR seguinte.
- A purga do escritório apaga as linhas de `contratacao` pela ordem de deleção.

## Para o cliente
- Preparamos o registro de quais ferramentas cada escritório usa. Todos os escritórios de hoje continuam com tudo o que já tinham, sem nenhuma mudança na tela.
