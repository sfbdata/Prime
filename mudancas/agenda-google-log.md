---
tipo: correcao
---
## Changelog
- Google Agenda: a falha na volta da conexão (e o escritório trocado entre a ida e a volta) passa a ir ao log de erro de produção com o motivo; antes era um aviso que o log de produção descartava. A recusa do token traz o código do OAuth (ex.: `invalid_client`), sem a descrição livre do Google.
- Google Agenda: quem volta do Google sem a permissão da agenda (a caixinha desmarcada no consentimento) vê o aviso "Marque a permissão da agenda na tela do Google e tente de novo" com o botão "Tentar de novo", em vez do "Não foi possível conectar".

## Para o cliente
- Ao conectar o Google Agenda sem marcar a permissão da agenda na tela do Google, o BlueJus agora explica o que fazer e oferece tentar de novo.
