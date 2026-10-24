---
tipo: alteracao
---
## Changelog
- Login: o botão "Continuar com Facebook" sai do "Criar conta" até existir o app na Meta (ponto de ligação na spec `entrar-com-google.md`).
- Login: "Entrar com o Google" (cartão) e "Continuar com Google" (Criar conta) aparecem sempre; sem `GOOGLE_LOGIN_OAUTH_CLIENT_ID`/`GOOGLE_LOGIN_OAUTH_CLIENT_SECRET`, a ida `/login/google` não vai ao Google e volta à tela com o aviso "O acesso pelo Google ainda não está configurado neste ambiente. Use o e-mail." (no cartão, ou no modal com `origem=cadastro`). Com a credencial, nada muda.

## Para o cliente
- A tela de entrada mostra sempre o acesso pelo Google; o botão do Facebook saiu por enquanto.
