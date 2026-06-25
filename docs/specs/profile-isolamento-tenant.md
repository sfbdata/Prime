# Spec — Isolamento de tenant do domínio Profile (P2)

> Frente P2 da remediação multi-tenant. Risco MÉDIO. Decisão do dono: **guard transitivo,
> SEM migration**. Contexto: `docs/specs/auditoria-multitenant.md` (veredito Profile: 🟡 risco
> menor) e `docs/specs/isolamento-tenant-sistemico.md` ("Profile: manter via User; guard nas
> rotas admin por id").

## Decisão e porquê (sem coluna `tenant`)

`UserProfile` é **1:1 com `User`** (`user_profiles.user_id` UNIQUE NOT NULL). `User` é entidade
**estrutural/compartilhada** (não TenantAware — um usuário pertence a vários tenants). Dar coluna
`tenant` ao `UserProfile` seria modelagem errada: qual tenant gravar para um user multi-tenant? Por
isso o `TenantFilter` **não** cobre Profile — o isolamento é **transitivo via `User`→`UserTenant`**,
garantido por guards explícitos nas rotas administrativas. **Zero migration, zero mudança de schema.**

## Resultado da verificação (read-only): já isolado

A investigação inicial sugeriu IDOR nas rotas admin (ParamConverter resolve `User` por id antes de
validar vínculo). **Refutado na leitura do código**: o `User` é entidade compartilhada (resolvê-lo
por id não é vazamento de dado de tenant), e em **todas** as rotas que tocam o perfil os guards
rodam **antes** de qualquer leitura/mutação do `UserProfile`:

| Rota | Guard 1 (user-alvo ∈ tenant) | Guard 2 (admin controla tenant) | Toca perfil |
|---|---|---|---|
| `TenantUserProfileController::salvarDadosPessoais` | `existeVinculoAtivo` (l.47) → 404 | super-admin OU (próprio tenant + `admin.users.manage`) (l.54) → 403 | l.58 (depois) |
| `TenantController::editUserRole` | `findAtivoPorUserETenant` (l.472-474) → 404 | l.468 → 403 | l.547 (depois) |
| `TenantController::editUserName` / `demitir` | l.604 / l.666 → 404 | l.611 / l.673 → 403 | depois |
| `ProfileController` (index/dados/status/foto) | self-service: `$this->getUser()` (sempre o próprio user) | — | próprio perfil |
| `ProfileController::servirFoto` | `buscarPorFotoUrlETenant` (tenant-scoped) + anti path-traversal | — | só foto do tenant ativo |
| `PontoController::exportar{Pdf,Xlsx}` (folha de terceiro) | `existeVinculoAtivo(target, tenant)` (l.712/795) | super-admin OU `canAdminister(admin.users.manage)` (l.712/795) → 403 | `getProfile()` l.852 (depois) |

> A varredura cobre `buscarPorUsuario`, `getProfile()`/`->profile` e `obterOuCriarPerfil`. A exportação
> de folha lê `getProfile()` do alvo, mas o guard idêntico (vínculo do alvo no tenant + admin) roda antes
> do `getProfile()`/`montarDadosFolha`. O teste de regressão cross-tenant dessa exportação cai na **frente
> Ponto** (folha = dados de ponto: `RegistroPonto`, que vira TenantAware lá), evitando duplicação.

`buscarPorUsuario(user)` (sem filtro de tenant) só é chamado com `getUser()` (self) ou com o
`User` já validado pelos guards → não vaza. `buscarPorFotoUrlETenant` já escopa por tenant ativo.

**Conclusão:** não há IDOR/vazamento explorável hoje. O gap real era **falta de teste de regressão**
(a auditoria marcou Profile como 🟡 não-verificado). Esta frente **fecha o gap com testes**, sem
tocar no código de produção.

## Mudança

- **Nenhuma alteração de código de produção** (os guards já existiam e estão corretos).
- **Teste novo:** `tests/Profile/Functional/PerfilAdminIsolamentoControllerTest` — admin do tenant A
  (com TenantRole `isSystem`, provando que o bloqueio é por escopo, não por permissão):
  - `dados-pessoais` de user do tenant B via URL→B → **403** (Guard 2);
  - `dados-pessoais` de user do tenant B via URL→A → **404** (Guard 1);
  - `dados-pessoais` de user do próprio tenant → **302** (passa pelos guards);
  - `edit-role` de user do tenant B via URL→B → **403** (Guard 2);
  - `edit-role` de user do tenant B via URL→A → **404** (Guard 1, ramo distinto — no `editUserRole`
    o Guard 2 precede o Guard 1);
  - bloqueio **não cria** o perfil do alvo, e **não muta** um perfil já existente (valor conhecido intacto).
- Cobertura de foto cross-tenant já existia (`ServirFotoControllerTest`).

## Follow-ups (não bloqueiam)

- Os guards (Guard 1 + Guard 2) estão **duplicados** em 4 métodos de controller. Extrair um helper
  `garantirUserDoTenant($user, $tenant)` + checagem de admin reduziria o risco de uma rota nova
  esquecer o guard (o `TenantFilter` não protege Profile por design). Refactor opcional, fora do
  escopo desta frente de segurança.
- Qualquer rota admin NOVA que resolva `User` por id e toque o perfil precisa repetir os dois guards.
- `TenantController::editUserName` e `demitirFuncionario` (gerência de User/vínculo, **não** tocam
  `UserProfile`) usam o mesmo par de guards e estão corretos, mas **sem teste de isolamento cross-tenant**
  hoje — recomenda-se cobri-los num passe de hardening de gerência de usuário (fora do escopo de dados de perfil).
