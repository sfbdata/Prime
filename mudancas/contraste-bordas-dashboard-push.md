---
tipo: alteracao
---
## Changelog
- Dashboard e Push Processual (pedidos A3 e A4 de 19/11, Guia visual BlueJus v30): textos de apoio, zeros, "sem cargo", placeholders e rótulos vazios passam a #5f7684 (4,7:1 sobre branco; antes de 1,6:1 a 3,9:1), trocando os tokens `--db-muted`, `--db-faint`, `--db-placeholder`, `--db-zero`, `--db-sem-cargo` e `--db-seta-igual`; ícones decorativos a #7b93a2 (3,2:1); troféu e ouro das janelas a #9a7220; coluna fixa das preferências sem a opacidade.
- Bordas de área no tom do guia (#d6e0e7, divisória #e3eaef) no Dashboard e nos CSS do Push; campos de formulário do Push com borda #7b93a2.
- Push > Processos: o indicador usado como filtro ativo deixa de ser marcado só pela cor da borda; ganha "✓", rótulo em negrito e `aria-current`.

## Para o cliente
- No Dashboard e no Push Processual, os textos cinza-claros (números zerados, "sem cargo", legendas, dias fora do mês no calendário) ficaram mais escuros e fáceis de ler.
- As linhas que separam cartões e tabelas ficaram num cinza mais suave e igual em todas as telas, e as caixas de busca e campos do Push ficaram com a borda mais visível.
- Em Push > Processos, ao clicar num indicador para filtrar a lista, ele mostra um "✓" e o nome em negrito, além da cor.
