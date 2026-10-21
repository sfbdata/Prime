# Cadastro por e-mail exige a confirmação antes de usar o sistema

Risco ALTO (identidade User). Decisão do dono (22/10/2026, via coordenador): "a conta criada com e-mail e senha só
usa o sistema depois de clicar no link que chega no e-mail, como era antes". Motivo: hoje (#101) ela entra na hora
com o e-mail "a confirmar", o que deixa um terceiro cadastrar e usar o e-mail de outra pessoa (achado da 4b).

## Estado da frente

- 22/10: spec escrita; implementação começando. Sem migration.
- 22/10: implementada na branch `cadastro-exige-confirmacao` (commit local, sem push). Cadastro sem login com o
  estado "Confirme seu e-mail" no modal; recusa no `UserChecker::checkPostAuth` (formulário e lembrar-me); reenvio
  público `POST /login/reenviar-confirmacao` com a conta lida da sessão (`conta_a_confirmar`) e os tetos extraídos
  para o `TetoDoReenvioDeConfirmacao`; `SessaoDeContaAConfirmarListener` encerra a sessão aberta. A tela de entrada
  mostra a mensagem e o botão sempre que a sessão guarda uma conta ainda a confirmar (inclusive depois do lembrar-me
  e da sessão encerrada, sem flash duplicado). Testes direcionados verdes; falta o smoke do dono na tela.
- 22/10: ressalvas da revisão. A chave `conta_a_confirmar` guarda o momento e vale 30 minutos
  (`ContaAConfirmar::VALIDADE`, com o relógio); sai quando outra conta entra com sucesso
  (`EsquecerContaAConfirmarNoLoginListener`, `LoginSuccessEvent` do firewall main), para o navegador compartilhado não
  mostrar o e-mail de uma pessoa à próxima. Testes provam também que o cookie REMEMBERME volta vencido na recusa do
  lembrar-me e na sessão encerrada. A ordem com o gate dos Termos ficou como estava (ressalva aceita).

## Produção hoje (MCP jusprime-prod, 22/10, só contagens)

2 contas com `email_a_confirmar_desde` preenchido, de 22. Cada uma sozinha no próprio escritório e com 0 pastas.
Recebem a regra nova como qualquer conta: a sessão aberta delas termina e o login pede a confirmação, com o botão de
reenviar o link. Nada de tratamento especial nem de migration.

## Decisão de desenho

A conta continua nascendo no cadastro (User, escritório, aceite dos Termos, ciência da Política, link de
confirmação, `ContaCriadaPeloCadastro` para a indicação pelo link), como no #101. O que muda é que ela **não entra**
até o e-mail ser confirmado. Não se volta ao `CadastroPendente` antigo: ele exigia a OAB, que o desenho 10 tirou, e a
conta "a confirmar" sem acesso já fecha a tomada de conta (o terceiro não usa nada; a dona do e-mail recupera pela
senha por código, que confirma o e-mail, ou pelo Google).

## Comportamento

1. **Cadastro** (`POST /cadastro/criar`): cria a conta e manda o link, mas **não faz login**. Responde
   `{ok: true, confirmar: true, email}`, e o modal de cadastro mostra o estado "Confirme seu e-mail" com os textos
   da tela antiga (`verifique_email.html.twig`, antes de 54706dbe), adaptados à validade de hoje (7 dias):
   título "Confirme seu e-mail"; "Enviamos um link de confirmação para **{email}**. Clique nele para ativar sua conta
   e seu escritório. O link expira em 7 dias."; "Não recebeu? Verifique a caixa de spam ou peça um novo link."; botão
   "Reenviar o link de confirmação" e o link "Ir para o login". (O desenho 10 vai direto à animação de entrada; isto é
   o desvio decidido pelo dono.)
2. **Login de conta não confirmada** (formulário e lembrar-me): recusado **depois** de conferir a senha
   (`UserChecker::checkPostAuth`, para não revelar que a conta existe a quem não tem a senha), com a mensagem
   "Confirme o seu e-mail para entrar. Enviamos o link para {email}." e o botão "Reenviar o link de confirmação".
   O id da conta fica na sessão só depois da senha certa; o reenvio público lê dali (nunca de um e-mail digitado),
   com CSRF e os tetos de hoje (2 minutos e 5 por 24 h).
3. **Sessão já aberta** de conta não confirmada (as 2 de produção): termina no próximo pedido de página e volta ao
   login com a mesma mensagem. Pedido de dados recebe 401, como o resto (#172).
4. **Link do e-mail** (`/cadastro/confirmar-email/{token}`): confirma, como hoje, e mostra "E-mail confirmado." com
   o botão Entrar. Não faz login sozinho.
5. **Recuperar a senha por código**: continua confirmando o e-mail (o código chegou na caixa). Depois disso entra.
6. **Convites** (escritório, plataforma, Trabalho Externo): as contas nascem confirmadas (o convite prova o e-mail).
   Nada muda.
7. **Entrar com o Google** (4b, em outra branch): o dono decidiu que o Google confirma o e-mail. O bloqueio no
   `UserChecker` rodaria antes da confirmação do Google; a 4b confirma dentro do `authenticate()` (antes do checker),
   combinado com ela.
8. As recusas que existem hoje para "a confirmar" (convites, link do Push, Trabalho Externo) ficam como defesa, mas
   na prática deixam de ser alcançáveis.
9. O `CadastroPendente` e o `ConfirmarCadastroUseCase` antigos continuam como estão (código morto na prática, fora
   desta frente).
