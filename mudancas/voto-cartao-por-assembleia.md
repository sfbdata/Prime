---
tipo: alteracao
---
## Changelog
- Voto, Gestão eleitoral: o filtro e cada assembleia ganham um cartão `.vC` próprio, com 12px entre os cartões, como nas abas Votar e Resultados (pedido M1.1 de 24/11, muda o desenho 18, que emendava tudo num `.vC` só). `_painel.html.twig` (`.voto-gestao` vira coluna sem moldura, `.voto-gestao-filtro` com o `.vFil` e o vazio), `_assembleia_cartao.html.twig` (`.vC.voto-assembleia`) e `voto-painel.css` (cabeçalho do grupo `.vGrH` com a borda e a sombra do `.vC`; sem a linha de baixo do `.vFil` e do `.vGr`).
- `voto-painel.js` e `voto-ciclo.js` sem mudança: os seletores são por `data-voto-*`. Teste de arranjo por filho direto em `PainelPorEntidadeTest`.

## Para o cliente
- No Voto, na Gestão eleitoral, cada assembleia agora aparece num quadro separado, com um espaço entre um e outro, como já acontece em Votar e Resultados. Fica mais fácil ver onde termina uma assembleia e começa a próxima.
- O filtro (Buscar, Entidade e Situação) também ganhou um quadro só dele.
