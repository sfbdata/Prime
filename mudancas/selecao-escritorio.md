---
tipo: correcao
---
## Changelog
- Tela "Selecione um escritório" (`tenant/selecionar.html.twig`, pedido A6 de 19/11): com duas ou mais contas, cada escritório volta a ser uma linha com o nome em cima e o perfil embaixo. A padronização de 18/11 tinha posto `.bjp-btn` com estilo inline, e o botão em linha (inline-flex) deixava nome e perfil lado a lado. Agora é a linha de conta do desenho `bj-contas.js` (a mesma do "Trocar conta" do menu do usuário), em `tenant-selecionar.css`, com ícone do escritório, seta à direita e o realce do guia v30 (#2b7ab0 com texto branco) ao passar o mouse e no foco do teclado. Teste `testDuasContasEmLinhasDeConta`.

## Para o cliente
- Para quem tem acesso a mais de um escritório, a tela "Selecione um escritório" depois do login voltou a mostrar cada escritório numa linha, com o nome em cima e o seu perfil embaixo, no mesmo formato da troca de conta do menu.
- Ao passar o mouse sobre um escritório, a linha fica azul com o texto branco, como nas outras listas do sistema.
