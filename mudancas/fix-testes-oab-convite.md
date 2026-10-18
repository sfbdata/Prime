---
tipo: interno
---
## Changelog
- Testes: ConsultasDoPainelTest volta a passar. O cenário gravava a OAB `0012.044`/`df`, que as
  restrições `chk_user_oab_numero` e `chk_user_oab_uf` do banco recusam; agora grava `0012044`/`DF`,
  que continua exigindo formatação por causa dos zeros à esquerda.
- Testes: ConviteDoTrabalhoExternoTest deixa de falhar das 21h à meia-noite. A validade vencida era
  `CURRENT_DATE - 1` do Postgres (UTC), que nessa janela é o "hoje" do PHP (Brasília); agora o teste
  grava o ontem calculado no PHP.
