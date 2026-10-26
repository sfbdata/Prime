# Push Processual: aba Processos da OAB (desenho 11)

## Para o cliente

- A aba Processos do Push Processual ganhou a tela nova: cartões de resumo, inclusão de processo por número, lista com filtros (busca, OAB, movimentação, área, fase, pasta) e exportação em CSV.
- Dá para incluir um processo para acompanhamento só pelo número, parar de acompanhar vários de uma vez e anotar observação, área, fase e e-mail de cada processo.
- Nova sub-aba Relatório: lista os processos que aparecem nas publicações das suas OABs e ainda não estão sendo acompanhados, com botão para incluir.

## Técnico

- Nova tabela `djen_processo_acompanhado` (migration 20261026151500): números incluídos à mão e anotações.
- `ProcessosDaOabController` (`/push-processual/processos`), três UseCases, `ProcessosDaOabRepository` (SQL com CTEs), filtro e classificação em Services.
- `ProcessoRepository::listarNumerosAcompanhadosDoTenant` passa a incluir os números manuais (a sincronização os consulta).
- A lista antiga `/processos/` não foi alterada.
- Fora desta entrega: sub-aba Acervo e "Capturar da OAB".
