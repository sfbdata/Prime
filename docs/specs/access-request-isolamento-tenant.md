# M2 — Isolamento das solicitações de acesso por escritório (AccessRequest → TenantAware)

Risco: **MÉDIO** (achado M2 da auditoria pós-remediação; entidade em `Entity/Permission/`).

## Vazamento

`AccessRequest` (`src/Entity/Permission/AccessRequest.php`) não tinha coluna `tenant`.
`AccessRequestRepository::findPendingByTenant` escopava só por JOIN no vínculo do solicitante
(`UserTenant`), e `AccessRequestController::assertBelongsToAdminTenant` validava apenas
`existeVinculoAtivo(solicitante, tenantDoAdmin)`. **Vetor:** um usuário U vinculado a A **e** B
cria uma solicitação (sem tenant; `resourceId` é global). Ela aparecia no painel de aprovação de
**ambos** os escritórios. O admin de B aprovava (o guard passava porque U tem vínculo em B),
criando um `ResourceAccess` para um recurso que pode ser de A — concessão por um admin **sem
autoridade** sobre aquele recurso. (Limitador: o `ResourceAccess` só vira acesso efetivo no
tenant dono do recurso, onde o `TenantFilter` ainda gateia o load — é violação de autoridade de
concessão, não bypass do filtro.)

## Design

`AccessRequest implements TenantAware` + coluna `tenant` NOT NULL. A solicitação pertence ao
**escritório onde foi feita** (o tenant da sessão no `submit`). Efeitos:

- `submit` resolve o tenant da sessão (`TenantContext`), **guard fail-closed** se ausente
  (JSON 400), e faz `setTenant` na solicitação.
- `findPendingByTenant` passa a escopar por `ar.tenant = :tenant` (explícito, autoritativo) em
  vez do JOIN no vínculo — fecha o vazamento para os painéis dos outros escritórios do usuário.
- `approve`/`deny` carregam por `find($id)` → o `TenantFilter` zera cross-tenant (→ 404). Como
  defesa-em-profundidade, `assertBelongsToAdminTenant` agora checa
  `accessRequest.getTenant() === $tenant` (404, não 403, p/ não revelar a existência por status).
- A dependência `UserTenantRepository` (só usada pelo guard antigo) saiu do controller.

## Bug latente corrigido (no caminho do M2)

`AccessRequestRepository` usava `User $user` em `findPendingForUserAndResource` **sem**
`use App\Entity\Auth\User;` → `submit` fataria se exercitado. A feature está dormente (dev tem 0
solicitações, 0 testes). Import adicionado (necessário para os testes do `submit`).

## Fora do escopo (observações)

- O índice único parcial `uniq_access_request_pending` (Version20260331120000) está **no ledger
  mas não existe no banco** (dev e test) — drift pré-existente. A migration do M2 **não o toca**.
  A unicidade de pendentes hoje depende do app (`findPendingForUserAndResource`, que com o filtro
  passa a ser per-tenant). **Ao fechar o drift (B-series), recriar o índice COM `tenant_id`** —
  restaurar o antigo (sem tenant) bloquearia um usuário multi-tenant de solicitar o mesmo recurso
  em A e B, conflitando com o modelo per-escritório (ver teste `testSubmitMultiTenantCadaSolicitacaoNoSeuTenant`).
- `ResourceAccess` segue não-TenantAware (B3, fora do escopo).
- **Gap residual de autoridade ✅ FECHADO (follow-up pós-frente-4, jun/2026):** o `submit` agora valida
  que o `resourceId` pertence ao escritório da sessão (helper `recursoPertenceAoTenant` — compara o
  tenant do recurso TenantAware EXPLICITAMENTE; robusto independente do filtro, pois `find()` por PK
  pode resolver pelo identity map sem reaplicá-lo), e o `approve` aplica a MESMA checagem (cobre
  solicitação legada criada antes do fix). Recurso de outro escritório → 404. Testes
  `testSubmitRejeitaRecursoDeOutroTenant` + `testApproveRejeitaRecursoDeOutroTenant`; mutação confirmada.

## Migration `Version20260629130000` (🔴 aplicar só no dev, isolada, após OK)

`access_request` +`tenant_id`: add nullable → backfill (1) vínculo ativo único do solicitante;
(2) fallback **tenant único** → NOT NULL + FK `FK_F3B2558A9033212A` + índice `IDX_F3B2558A9033212A`;
**aborta** se sobrar null. Aplicar via `migrations:execute 'DoctrineMigrations\Version20260629130000'
--up` (NUNCA `migrate` puro — 2 migrations antigas do Ponto fora do ledger) + `schema:update
--force --env=test`. **Dev tem 0 solicitações → backfill trivial.**

## Testes cross-tenant

- `AccessRequestIsolamentoRepositoryTest` (KernelTestCase): `findPendingByTenant` só do tenant
  (filtro DESLIGADO = pior caso, prova o `ar.tenant` explícito); `find()`-by-id cross-tenant →
  null com filtro ligado + `em->clear()` (prova o filtro, fecha o IDOR).
- `AccessRequestIsolamentoControllerTest` (WebTestCase): `submit` grava o tenant da sessão;
  `approve`/`deny` de solicitação de outro escritório → 404 + nada concedido/decidido (prova o
  guard explícito — sem `em->clear()`, depende dele); painel não lista solicitação de outro
  escritório (e lista a do próprio).

  Inclui `testSubmitMultiTenantCadaSolicitacaoNoSeuTenant` (era `testSubmitDuplicadoEscopaPorTenant`,
  reescrito após o gap-fix): usuário multi-escritório submete o recurso de CADA escritório (um id
  pertence a 1 tenant — frente 4) → 2 solicitações, cada uma no seu tenant. E os 2 testes de rejeição
  cross-tenant (`testSubmit/ApproveRejeitaRecursoDeOutroTenant`) fecham o gap de autoridade acima.

**Mutação 3×:** (A) remover `TenantAware` → IDOR find-by-id + submit-duplicado vermelhos; (B) inverter
`ar.tenant = :tenant` → `!=` → findPendingByTenant + painel vermelhos; (C) inverter o guard
`getTenant() !== $tenant` → `===` → approve/deny vermelhos. Suíte **876/876** (869 + 7 novos).

## Revisão adversarial (`feature-review-agent`)

APROVADO COM RESSALVAS (todas BAIXA/dívida pré-existente): (1) falta `strict_types` em
AccessRequest/AccessRequestRepository (baseline legado, não introduzido aqui — não tocar em edição
cirúrgica); (2) drift do índice parcial (alerta: recriar COM tenant no B-series); (3) migration
pendente no dev por design (FREIO). Sugestão endereçada: teste de submit duplicado cross-tenant.
Confirmado por prova: único write-site (submit) seta tenant; nomes FK/IDX batem; `existeVinculoAtivo`
segue usado em ~15 arquivos (remoção da dep limpa); suíte 876/876.
