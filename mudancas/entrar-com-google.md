---
tipo: novidade
---
## Changelog
- Login: "Entrar com o Google" para quem já tem conta (entrega 1). Pede só `openid email profile`, com `state`, `nonce` e PKCE; o `id_token` tem cliente, emissor, validade e e-mail confirmado conferidos. A conta é ligada pelo identificador do Google (coluna nova `user.google_sub`) ou, na primeira vez, pelo e-mail confirmado; um e-mail ainda "a confirmar" fica confirmado. Mesmo destino e barreiras do login por senha; "Manter conectado" vale quando a ida sai do próprio botão. Cliente OAuth próprio (`GOOGLE_LOGIN_OAUTH_CLIENT_ID/SECRET`); sem ele, o botão não aparece. O conferente do `id_token` passa a ser compartilhado com o Google Agenda.

## Para o cliente
- Agora dá para entrar no BlueJus com a sua conta Google, pelo botão "Entrar com o Google" na tela de entrada.
