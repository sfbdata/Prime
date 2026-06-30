# ResourceAccess isolado por tenant (B5 frente 4 / B3)

Risco: **ALTO** (entidade de permissão + modelo de dados + migration). Última frente do B5
(escopo do super-admin) — `docs/specs/super-admin-escopo-tenant.md`.

## Problema

`App\Entity\Permission\ResourceAccess` (acessos granulares "usuário X pode ver/editar/excluir o
item Y") implementava só `Auditavel`, **sem coluna `tenant`**. Logo o `TenantFilter` não tocava
suas queries, e o isolamento dependia apenas, indiretamente, da posse do usuário:

- `removeResourceAccess` (`TenantController`) carregava o RA por id (`find($raId)`) e só checava se o
  acesso pertencia ao `$userId` (já validado como membro do tenant da URL). Para um **usuário
  multi-tenant**, um admin de A podia remover, pela URL de A, um grant desse usuário a um recurso de
  **B** (write/IDOR cross-tenant residual — o que a **frente 3** deixou explicitamente em aberto).
- `findForUserAndResource` (base de `PermissionChecker::canAccessResource`) consultava por
  `user+type+id` sem escopo de tenant.

## Decisão

`ResourceAccess implements TenantAware` + coluna `tenant` NOT NULL — o tenant é o do **recurso dono**
(cliente/pasta/processo) ao qual o acesso se refere. Com isso o `TenantFilter` escopa TODAS as
queries de RA (find/findOneBy/findBy), e a trava (`TenantUrlScopeListener`) + o `TenantFilterListener`
passam a cobrir `removeResourceAccess` e `canAccessResource` sem remendo por-rota.

**Mantida a unique atual** `(user_id, resource_type, resource_id)`: como `resource_id` é PK global do
recurso, o tenant é funcionalmente determinado por ele — dois RAs com o mesmo `(user, type, id)` não
podem ter tenants diferentes. Acrescentar `tenant` à unique só a enfraqueceria. Não foi tocada.

## Mudanças

- **Entity** `ResourceAccess`: `implements Auditavel, TenantAware` + `#[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private ?Tenant $tenant` + `getTenant()/setTenant()`.
- **Write-site** (único em produção) `AccessRequestController::approve`: `setTenant($tenant)` no RA novo
  (tenant da sessão do admin; no caminho normal == tenant do recurso). Bônus: um grant a um recurso
  estrangeiro (gap M2 residual) fica **inócuo** em vez de funcionar (o filtro não o encontra na sessão
  do recurso).
- **`removeResourceAccess`**: NOTA atualizada — a trava agora escopa o `find($raId)`; as guardas de
  posse permanecem como defesa-em-profundidade.
- **Repository**: sem mudança (o filtro cobre `findForUserAndResource`, `findByUsers`, `find`).

## Migration `Version20260630120000`

`resource_access` +`tenant_id`: add nullable → backfill pelo tenant do recurso (3 UPDATEs por
`resource_type` lendo `cliente`/`pasta`/`processo`.tenant_id; `cliente` é JOINED → tenant_id na tabela
base) → fallback determinístico tenant único → `SET NOT NULL` (aborta se sobrar NULL = recurso
inexistente em multi-tenant) → FK `FK_CE95C1AE9033212A` + índice `IDX_CE95C1AE9033212A`.

- **Aplicada no dev** via `migrations:execute 'DoctrineMigrations\Version20260630120000' --up` ISOLADA
  (ledger sem as 2 migrations antigas do Ponto). Dev = 1 tenant / 0 RAs → trivial. `schema:validate` OK.
- **Schema de teste** sincronizado via `schema:update --force --env=test`.
- **Deploy prod:** 1 tenant (igual ao dev) → backfill cai no fallback, roda liso. Adicionar ao runbook
  `DEPLOY-PROD-multitenant.md`. Pré-check: `SELECT COUNT(*) FROM tenant` e nenhum RA órfão não-derivável
  em instalação multi-tenant (aborta de propósito).

## Testes

`tests/Permission/Functional/ResourceAccessIsolamentoControllerTest` (3):
1. **IDOR fechado** — admin de A não remove, pela URL de A, o grant de um usuário multi-tenant a um
   recurso de B → 404 + grant sobrevive (verificado com o filtro desligado).
2. **Lookup escopado** — `findForUserAndResource` (base do `canAccessResource`) só enxerga o grant do
   tenant filtrado (filtro em A encontra; em B não).
3. **Write-site** — `approve` grava o RA com o tenant da sessão do admin.

Mutação 2× (remover `implements TenantAware`): os testes 1 e 2 (dependentes do filtro) ficam RED; o 3
(write-site) segue verde. Ajustado o teste da frente 3 (`TenantEscopoRemendoControllerTest`): helper
`criarResourceAccess` recebe `Tenant`; a verificação de sobrevivência do RA desliga o filtro antes do
`find` (RA agora é filtrado). Suíte **893/893**.

## Efeito

Fecha o vazamento concreto do `ResourceAccess` e o resíduo da frente 3. Conclui o B5: o super-admin
(e qualquer admin) fica preso ao `{tenantId}` da URL nas rotas de escritório, inclusive nos acessos
granulares.
