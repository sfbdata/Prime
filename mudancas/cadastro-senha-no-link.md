---
tipo: seguranca
---
## Changelog
- Cadastro: a senha passa a ser criada no link de confirmação do e-mail. A conta nasce com uma senha inutilizável (a digitada no cadastro é ignorada) e o link abre "Crie sua senha", com "Nova senha", "Confirmar nova senha" e a régua de força; "Criar senha e confirmar" grava a senha, confirma o e-mail e marca o link como usado numa transação só. Fecha a brecha de um terceiro cadastrar o e-mail de outra pessoa com a senha dele e entrar depois que a dona clica no link.
- Cadastro: abrir o link não confirma mais nada (o robô de antivírus que abre o link também não). Erro de senha volta ao formulário sem gastar o link; dois envios ao mesmo tempo só gravam uma senha. Não faz login sozinho.
- Cadastro: textos novos no modal "Confirme seu e-mail", no e-mail de confirmação (botão "Confirmar e criar minha senha") e na resposta do reenvio sem conta na sessão, que agora aponta o "Esqueci a senha".

## Para o cliente
- Ao criar a conta, a senha agora é criada no link que chega no seu e-mail. Isso garante que só quem lê o e-mail consegue entrar.
