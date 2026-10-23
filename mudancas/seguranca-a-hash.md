---
tipo: correcao
---
## Changelog
- Convites: o banco guarda só o hash do código do link do convite; os links já enviados continuam valendo (achado de auditoria interna).
- Convites: reenviar um convite gera um link novo, e o link anterior deixa de valer.
- Auditoria: o código do link do convite não entra mais no registro de auditoria, e o que já estava gravado foi apagado.
- Cadastro: criar conta com um e-mail que já tem conta dá a mesma resposta do e-mail novo; a dona da conta recebe um e-mail avisando que já tem conta, com o caminho do "Esqueci a senha" (achado de auditoria interna).

## Para o cliente
- O link do convite fica ainda mais protegido: só quem recebe o e-mail tem o código, e reenviar o convite troca o link.
- Ao criar uma conta, a tela não diz mais se o e-mail já está cadastrado: quem já tem conta recebe um e-mail com o caminho para recuperar a senha.
