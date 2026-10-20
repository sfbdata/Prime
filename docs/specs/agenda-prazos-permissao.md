# Agenda: permissão de perfil "Ver prazos na agenda" (78b-1)

Risco: **MÉDIO** (Permission/TenantRole; concede permissão a perfis existentes). Leitura obrigatória:
`docs/AUTORIZACAO.md`. Decisão do dono (20/10/2026, trazida pelo coordenador): os prazos e audiências das
pastas aparecem na Agenda para quem tem a permissão de perfil "Ver prazos na agenda", configurada pelo
administrador em Editar perfil; ela **nasce ligada** em todos os perfis que hoje veem pastas.

## Estado da frente (20/10/2026)

- Branch `agenda-prazos-permissao`, base master, PR próprio. Ordem de merge: #78 (Google), este, 78b-2.
- Migration `Version20261020134100` (nome dado pelo coordenador).
- Para o dono: nada além do merge; a concessão roda sozinha na migration.

## Decisões

- **Código** `resources.agenda.ver_prazos`, grupo `resources`, descrição "Ver prazos na agenda". Os padrões do
  catálogo são `modules.*` (tela), `admin.*` e `resources.*`; não é tela, então não é `modules.*`. Checado por
  `PermissionChecker::hasPermission` (bypass do Master e do perfil `isSystem` como todo o resto).
- **O que a permissão controla** (separação aprovada pelo coordenador): os prazos e audiências das pastas na
  grade (`ListarCompromissosUseCase`), nos lembretes (`ListarLembretesUseCase`), no push
  (`VarrerLembretesDePushUseCase`) e no "Avisar no Chat" do prazo (`CompromissoController::avisarPrazo`). O
  cadeado da pasta (#120) continua valendo por cima: prazo de pasta oculta para a pessoa não aparece.
- **O que continua com `modules.pastas.view`:** vincular uma pasta a um compromisso comum (campo PASTA do
  modal, `SalvarCompromissoUseCase`) e mostrar a pasta desse compromisso (`pastaId`/`pastaNup`).
- **Uma regra só:** `App\Agenda\Service\PermissoesDaAgenda::verPrazos()` (e `verPastas()`), usada nos quatro
  pontos.
- **Editar perfil:** a permissão aparece sozinha pelo catálogo; entra em `GruposDePermissaoDoPerfil::CARTOES`
  logo depois de "Agenda", com o rótulo "Ver prazos na agenda". Form, template e JS da tela não mudam (b5
  avisada).
- **Migration** (primeira do repositório a conceder permissão a perfis existentes): insere o código se não
  existir e o concede a todo perfil que tem `modules.pastas.view` **e** a todo perfil `is_system` (que já veria
  pelo bypass; a linha deixa o banco coerente com o `TenantBootstrapService`, que dá o catálogo inteiro ao
  perfil de sistema de escritório novo). `INSERT ... SELECT ... ON CONFLICT DO NOTHING`; `granted_at` na hora
  de Brasília (`NOW() AT TIME ZONE 'America/Sao_Paulo'`). O `down` tira as concessões e o código.
- **Fixture** `PermissionFixture` com o mesmo código, descrição e grupo (teste amarra migration e fixture).
  `tests/ci/schema-de-teste.sql` não é regerado: o CI roda as migrations por cima do snapshot e esta insere o
  código sozinha (o script diz que regerar não é obrigatório a cada migration).

## Testes

- Unitário: `PermissoesDaAgenda`; migration e fixture com o mesmo código, descrição e grupo.
- Funcional: com `modules.pastas.view` e sem a permissão nova, a grade e os lembretes não trazem prazos, mas o
  compromisso ainda mostra a pasta; com a permissão nova e sem pastas, trazem os prazos; o "Avisar no Chat" do
  prazo segue a permissão nova.
- Migration (`ConcessaoDeVerPrazosTest`): aplicada sobre perfis de teste, concede a quem tem `modules.pastas.view` e aos `is_system`, e não a
  quem não vê pastas; idempotente.
