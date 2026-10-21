---
tipo: alteracao
---
## Changelog
- Minhas Metas segue o desenho 04 (decisão do dono, 21/10): grupos na ordem Atrasadas, Vencem em 7 dias, Aguardando revisão, No prazo, Sem prazo e Concluídas, cada um com a cor do desenho, sem nota; Concluídas é um grupo aberto (o corte de 30 dias continua). Rótulos de grupo e pílulas de status vêm do catálogo (`metas.grupos.*`, `metas.status.*`).
- "Seu andamento" mostra três itens na legenda (concluídas, atrasadas, em revisão); a fatia "em aberto" saiu.
- A barra de filtro perdeu o filtro Status (o `status` da URL é ignorado).
- Prioridade própria da meta (`PrioridadeDaMeta`: normal, alta, urgente; coluna `tarefa.prioridade`, migration `Version20261020149300`). A meta nova herda a da pasta (urgente vira Urgente, prioridade vira Alta); as antigas herdam pelo comando `app:tarefa:herdar-prioridade-da-pasta` (`--dry-run`, `--escritorio=ID`), a rodar uma vez depois da publicação (DEPLOY.md). O filtro "Prioridade" passa a olhar a prioridade da meta, e o cartão deixa de mostrar a prioridade da pasta.

## Para o cliente
- Minhas Metas ficou com a cara do novo desenho: os grupos têm nova ordem e cores, as concluídas aparecem abertas e o filtro de prioridade passa a ser o da própria meta (Normal, Alta ou Urgente).
