---
tipo: seguranca
---
## Changelog
- Aviso de login novo: a cada login interativo (formulário, Google, Facebook; não o lembrar-me) o sistema guarda uma impressão do acesso (HMAC-SHA256 de família do navegador (sem versão), sistema e faixa do IP, /24 no IPv4 e /48 no IPv6; o IP cru nunca é gravado). Quando a impressão é nova para quem já tinha acessos, sai o e-mail "Novo acesso à sua conta" (data e hora no fuso do sistema, navegador, sistema e link para trocar a senha). O primeiro login só grava. Falha de gravação ou de e-mail nunca derruba o login.
- Nova tabela `acesso_conhecido` (migration `Version20261027153500`), sem tenant. Nova configuração do Master `avisoLoginNovo` (Ligado/Desligado, padrão Ligado), no grupo Segurança.
- A purga `app:purgar-dados-expirados` apaga os acessos não vistos há `retencao` dias (padrão 365).

## Para o cliente
- Quando alguém entrar na sua conta por um navegador ou rede que o BlueJus ainda não conhecia, você recebe um e-mail avisando, com o botão para trocar a senha caso não tenha sido você.
