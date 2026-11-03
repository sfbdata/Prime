---
tipo: correcao
---
## Changelog
- Modal da lixeira (pasta e Lixeira do Expediente): o fundo da tela ficava todo branco. Causa: no `pasta-explorador.css` o bloco que dá os tokens ao `.pex-lix` também aplicava o cartão (`background: var(--pex-card-bg)`, borda, sombra) e o `.pex-lix` é a raiz do `.modal` do Bootstrap, que cobre a tela inteira. O cartão ficou só no `.pex`; o `.pex-lix` recebe só os tokens. Teste em `PastaExploradorLixeiraTest`.

## Para o cliente
- A lixeira da pasta agora abre por cima da tela, com a página visível e escurecida atrás, como as outras janelas do sistema. Antes o fundo ficava todo branco.
