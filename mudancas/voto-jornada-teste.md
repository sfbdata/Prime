---
tipo: alteracao
---
## Changelog
- Voto: novo teste funcional de jornada completa (`JornadaDaAssembleiaTest`). Percorre pelo HTTP, tela por tela, o que o organizador e o eleitor fazem: cria a assembleia, importa as unidades, monta a eleição com foto do candidato, publica e abre a pauta, e o eleitor entra pelo portal, recebe o código por e-mail, vê a foto na cédula, vota e guarda o comprovante. Depois o organizador encerra e confere o telão e a apuração. Nenhum código de produção mudou.

## Para o cliente
- Criamos um teste automático que percorre a votação inteira como o organizador e o eleitor fazem, para pegar erro antes de chegar até você.
