---
tipo: novidade
---
## Changelog
- Login: "Entrar com o Google" para quem já tem conta. Pede só `openid email profile`, com `state`, `nonce` e PKCE; o `id_token` tem cliente, emissor, validade e e-mail confirmado conferidos. A conta é ligada pelo identificador do Google (coluna nova `user.google_sub`, migration `Version20261020151700`) ou, na primeira vez, pelo e-mail, só para conta Gmail ou Workspace (`hd`). Conta com o e-mail "a confirmar" que entra pelo Google é confirmada e tem a senha antiga trocada por uma inutilizável, antes do `UserChecker`. Mesmo destino e barreiras do login por senha; "Manter conectado" vale quando a ida sai do próprio botão. Cliente OAuth próprio (`GOOGLE_LOGIN_OAUTH_CLIENT_ID/SECRET`); sem ele, os botões do Google não aparecem. O conferente do `id_token` passa a ser compartilhado com o Google Agenda.
- Criar conta (desenho 10 VS 24.10.26): "Continuar com Google" e "Continuar com Facebook" acima dos campos. Pelo Google, a conta nasce confirmada e ligada, com o e-mail do Google guardado na sessão (`CadastroPeloGoogle`, 15 minutos), e entra na hora: cartão "conectado pelo Google" com "Usar outro e-mail", sem o campo E-mail. Rotas `POST /cadastro/google/criar` e `POST /cadastro/google/esquecer`. O botão do Facebook está na tela, sem app na Meta: o clique avisa que ainda não está disponível.

## Para o cliente
- Agora dá para entrar no BlueJus e criar a sua conta com a sua conta Google, pelos botões "Entrar com o Google" e "Continuar com Google".
