---
tipo: alteracao
---
## Changelog
- Painel do Master, Segurança e auditoria: a trilha passa a ser paginada no servidor (`GET /master/dados/auditoria` com `pagina`, `porPagina` 20/50/100, padrão 50, `tipo`, `conta` e `todas=1`). Filtro de ação, pílulas e contagem valem para o filtro inteiro; o PDF pede `todas=1` e continua com o filtro inteiro. A lista ganha rolagem própria com o cabeçalho fixo.

## Para o cliente
- No Painel do Master, a Trilha de auditoria agora mostra os registros em páginas (20, 50 ou 100 por página), com rolagem própria e o cabeçalho da tabela sempre visível. O filtro de ações, os totais e o Gerar PDF continuam valendo para todos os registros do filtro.
