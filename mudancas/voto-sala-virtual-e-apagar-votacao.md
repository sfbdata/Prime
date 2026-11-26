---
tipo: novidade
---
## Changelog
- Voto: campo opcional "Sala virtual" na assembleia (link http ou https, até 500 caracteres, coluna `voto.assembleia.link_sala_virtual`), mostrado na Gestão eleitoral e na faixa "Acompanhar" do Portal do Eleitor, com o botão "Abrir sala" em nova aba e, sem link, o aviso na própria página.
- Voto: "Apagar votação" no menu de três pontos da assembleia encerrada (nenhuma pauta aberta ou programada para abrir), com janela que exige digitar o nome ou APAGAR; votos, unidades, candidaturas, edital e ata saem de vez e a exclusão fica no audit_log.

## Para o cliente
- Ao criar a votação você pode colocar o link da reunião (Meet, Zoom, Teams...). Quem vai votar vê, logo abaixo das pautas, o botão "Abrir sala" para entrar no debate. Se o link ainda não foi informado, aparece um aviso explicando.
- Votações já encerradas agora podem ser apagadas pelo menu de três pontos. Para evitar engano, é preciso digitar o nome da votação ou a palavra APAGAR. Tudo some para sempre: votos, unidades, candidaturas, edital e ata.
