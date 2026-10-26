---
tipo: novidade
---
## Changelog
- Painel: a foto diária do estoque (`dashboard_foto`) passa a guardar também metas em revisão, pastas urgentes e total de metas (migration `Version20261026152200`: três colunas anuláveis, sem preenchimento do passado).
- Painel: Metas ativas, Demandas ativas, Em revisão e Pastas urgentes ganham a seta de tendência (e a pílula no Total) comparando o estoque de hoje com a foto do dia anterior ao período. Sem foto, a seta fica oculta.
- Painel: o histórico mensal dos cards Metas ativas, Demandas urgentes e Meta global passa a mostrar a série a partir da primeira foto; meses sem foto aparecem vazios ("sem histórico").
- Metas concluídas, Taxa de conclusão e Tempo médio seguem sem seta, de propósito: o período anterior distorceria a comparação.

## Para o cliente
- No Painel, as colunas Metas ativas, Demandas ativas, Em revisão e Pastas urgentes passam a mostrar uma seta de tendência quando já existe a foto diária de comparação. As setas só valem daqui para a frente; antes disso, ficam ocultas.
- O histórico dos cards Metas ativas, Demandas urgentes e Meta global começa a ser montado a partir de agora, mês a mês.
