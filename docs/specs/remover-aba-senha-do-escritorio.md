# Remover a aba "Alterar Senha" de Editar escritório

Risco: **ALTO** (identidade User/Tenant: senha de usuário). Pedido do dono em 18/10/2026.

## O que existe hoje

`/tenant/{tenantId}/edit` (`app_tenant_edit`, `TenantController::edit`, pasta legada) tem quatro
abas; a quarta, "Alterar Senha" (`TenantPasswordType`, campos `password` e `confirm_password`),
deveria trocar a senha do "administrador do escritório".

- **Nunca funcionou na v2.** Com as duas senhas iguais, o POST cai em
  `findOneBy(['tenantRole' => ...])` sobre `User`, campo que saiu da entidade em 16/05/2026
  (`f12eb1c4`): Doctrine lança `UnrecognizedField` antes de qualquer consulta, a tela devolve 500 e
  nenhuma senha muda. Nenhum teste enviava esse formulário.
- **Consertada, seria uma brecha.** A guarda da tela é só `admin.tenant.settings.manage`, sem a
  `RegraDosPrimarios`: um secundário com essa permissão trocaria a senha de um primário. E "o
  administrador" é ambíguo (em produção há escritório com dois primários).
- **A troca de senha de outra pessoa já existe** em editar colaborador
  (`app_tenant_user_edit_role`, `TenantController::editUserRole`), guardada por
  `RegraDosPrimarios::podeTrocarSenhaDe` e coberta por testes (secundário não troca a de primário;
  sócio não troca a do titular; primário não troca a do Master). Conferido antes desta mudança.

## Decisão

Remover a aba, não consertar. Sai:

1. o bloco do formulário de senha em `TenantController::edit`, e os dois parâmetros que só ele usava
   (`UserPasswordHasherInterface`, `TenantRoleRepository`);
2. `app/src/Form/TenantPasswordType.php` (sem outro uso);
3. o botão e o painel da aba em `app/templates/tenant/edit.html.twig`.

Nada muda nas outras três abas (Dados, Cargos, Lotações), na guarda da tela nem em editar
colaborador.

## Critérios de aceite (provados por teste)

- A tela de editar escritório não tem mais a aba, o painel nem campo de senha.
- Um POST com os campos do formulário antigo (`tenant_password[...]`) não altera a senha de ninguém
  e não dá 500.
- Editar colaborador continua trocando senha: o primário troca a de um secundário; e um secundário
  com `admin.users.manage` não troca a do Master (caso que faltava teste).
- As outras abas seguem salvando (o nome do escritório, ao menos).

## Fora do escopo

Refatorar `TenantController` (legado, risco ALTO), mexer na `RegraDosPrimarios` ou no fluxo de
editar colaborador.
