---
tipo: alteracao
---
## Changelog
- Cadastro por e-mail exige a confirmação antes de usar o sistema (spec `cadastro-exige-confirmacao.md`). `POST /cadastro/criar` cria a conta e manda o link, mas não faz mais login: responde `{ok, confirmar, email}` e o modal mostra "Confirme seu e-mail" com o reenvio do link e o "Ir para o login".
- Login de conta com o e-mail a confirmar (formulário e lembrar-me) recusado no `UserChecker::checkPostAuth`, depois da senha conferida: "Confirme o seu e-mail para entrar. Enviamos o link para {email}." e o botão "Reenviar o link de confirmação". Senha errada continua com o erro de credencial comum.
- Reenvio público `POST /login/reenviar-confirmacao` (`auth_confirmacao_reenviar_publico`): CSRF, conta lida só da sessão (`conta_a_confirmar`, gravada pelo cadastro, pelo login com a senha certa ou pela sessão encerrada; vale 30 minutos e sai quando outra conta entra), os mesmos tetos da rota logada (2 minutos e 5 por 24 h, agora no `TetoDoReenvioDeConfirmacao`). Formulário recebe flash; o `fetch` do modal recebe JSON.
- Sessão já aberta de conta a confirmar termina no próximo pedido (`SessaoDeContaAConfirmarListener`, `kernel.request` prioridade 7): a navegação volta ao login com o aviso, o pedido de dados recebe o 401 em JSON. Liberadas as rotas do próprio fluxo (login, logout, link do e-mail, reenvio, senha, Política).
- Convites (escritório, plataforma, Trabalho Externo) continuam criando contas confirmadas; as recusas por e-mail a confirmar nos convites e no link do Push ficam como defesa.

## Para o cliente
- Ao criar a conta, confirme o seu e-mail pelo link que enviamos antes de entrar. Se o e-mail não chegou, a tela de entrada tem o botão para pedir um novo link.
