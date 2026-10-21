---
tipo: alteracao
---
## Changelog
- Minhas Metas (desenho 04): a aba Criei agrupa por prazo, com os mesmos grupos, nomes, ordem e cores das outras abas (Atrasadas, Vencem em 7 dias, Depois, Sem prazo, Enviadas para revisão, Concluídas recolhido). Sai o agrupamento por etapa da revisão (`AgrupadorDeMetas::porEtapaDaRevisao`, `AbaMetas::agrupaPorEtapaDaRevisao`).
- Trilho: o cartão se chama sempre "Seu andamento" e o "X de Y concluídas", a barra e a legenda (com "em aberto") contam todas as metas da pessoa (universo da aba Todas, concluídas dos últimos 30 dias, sem as pastas do cadeado), sem depender da aba nem dos filtros da tela, também na recarga parcial. Consulta agregada nova `TarefaRepository::contarAndamento` e DTO `AndamentoDasMetasOutput`.
- "Precisa de atenção": as atrasadas de todas as metas da pessoa, da que venceu primeiro, no máximo 4 (`MinhasMetasOutput::TETO_ATENCAO`), sem "Ver todas as N atrasadas" e sem a frase de rodapé. Consulta nova `TarefaRepository::findAtrasadasParaAtencao`, em duas idas ao banco.
- Filtro de prazo com os rótulos do desenho ("Atrasadas", "Vencem em 7 dias", "Sem prazo"; os valores `vencidas`, `proximas` e `sem` não mudam) e botão "Limpar filtros" de texto na linha dos selects, só nesta tela (CSS local; `filtro-tabela.js` e `filtro-tabela.css` sem mudança). Os chips dos filtros ativos não aparecem mais nesta barra.

## Para o cliente
- Em Minhas Metas, a aba Criei agora separa as metas por prazo, como as outras abas. O cartão "Seu andamento" mostra sempre o resumo de todas as suas metas, e "Precisa de atenção" lista as 4 atrasadas mais antigas.
