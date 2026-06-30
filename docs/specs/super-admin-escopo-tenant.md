# B5 — Escopo de dados do super-admin nas rotas de escritório

Risco: **ALTO** (mexe no mecanismo global de escopo por tenant — o `TenantFilter`/listener — e no comportamento do `ROLE_SUPER_ADMIN`). Decidido via brainstorming (2026-06-29).

## Status
- **Frente 1 ✅ COMMITADA (`7fcb827`)** — listener `TenantUrlScopeListener` (prio 4) + teste. Suíte 880/880.
  Plano: `docs/superpowers/plans/2026-06-29-b5-frente1-listener-trava-tenant.md`.
- **Frente 2 ✅ ENTREGUE+REVISADA (APROVADA), não commitada** — 5 rotas `{id}`→`{tenantId}` + `MapEntity` + 23 callers; teste
  `TenantRotasTenantIdControllerTest`; suíte 887/887. A trava (frente 1) agora cobre as 5 rotas sem marcador. Detalhe em
  `docs/specs/PROGRESSO-PENDENCIAS.md` §"🎯 B5".
- **Frente 3 ⬜ PRÓXIMA** — remendo explícito + testes por-rota. **Frente 4 ⬜** — B3 (ResourceAccess TenantAware, migration 🔴).

## Problema (a "frestinha super-admin")

O `ROLE_SUPER_ADMIN` é a conta de plataforma (o dev/dono), criada via `CreateSuperAdminCommand`,
**sem vínculo de tenant**. Ela roda **sem tenant na sessão** → o `TenantFilter` fica inerte
(`TenantFilterListener` só liga o filtro quando há tenant na sessão) → queries de entidades
TenantAware não são escopadas. Nas rotas `{tenantId}` (gestão de um escritório específico), a
remediação adicionou o helper `TenantController::escoparFiltroNoTenant($em, $tenant)` (re-aponta o
filtro para o `{tenantId}` da URL após o guard) em **7 rotas**, mas **outras esqueceram** — é o B5:

- `TenantController::listUsers` (`/{id}/users`) — não escopa.
- `TenantController::downloadAnexoJustificativa` (`.../justificativa/{justificativaId}/anexo`) — não escopa.
- read-sites de `ResourceAccess` (`findByUsers`/`find`) em `listUsers`/`removeResourceAccess`.

Para o super-admin (filtro off), essas telas servem dados **cross-tenant**. Só alcançável pela
conta de plataforma; admin comum não chega (o guard exige `canAdminister` no tenant da URL). Além
de ser um resíduo, **bloqueia o B3** (tornar `ResourceAccess` TenantAware colide com essas rotas
que não escopam).

## Decisão (brainstorming)

**Uso do super-admin (resposta do dono):** plataforma + suporte a **um** escritório por vez; nunca
precisa ver vários escritórios misturados na mesma tela.

**Princípio:**
- **Rotas de plataforma** (sem escritório na URL — `app_tenant_index` lista, `app_tenant_new`
  criar): visão **global** legítima. É o único lugar onde o super-admin enxerga "todos os escritórios".
- **Rotas de escritório** (escritório na URL): o super-admin fica **preso ao escritório da URL**,
  igual a um admin comum. Nenhuma tela mistura escritórios.
- O **bypass global de permissão** do super-admin no `PermissionChecker` (`isGlobalSuperAdmin` →
  passa em qualquer checagem) **permanece**. O que muda é só **qual tenant os dados mostram** nas
  rotas de escritório.

**Mecanismo (decisão do dono: "os dois" — trava automática + remendo explícito):**

1. **Trava automática (rede de segurança) — listener.** Um listener de `kernel.request` (depois do
   roteamento, prioridade abaixo do `TenantFilterListener`) que, quando a rota casada tem o
   parâmetro `tenantId`, **fixa o `TenantFilter` naquele tenant** (sobrescreve o pin da sessão).
   Markerless: como `{tenantId}` já é a convenção dominante (usada em 5 controllers —
   `TenantController`, `TenantUserProfileController`, `TenantRoleController`, `FeriadoController`,
   `JornadaColaboradorController`), rotas novas nascem protegidas sem ação por-rota.
   - **Não faz autorização** — só escopa dados. O guard de cada controller (super-admin OU
     `canAdminister` no tenant da URL) continua rejeitando quem não pode (403/404). Fixar o filtro
     num tenant **não concede nada**; só restringe. Logo, fixar antes do guard é seguro.
   - Entidades carregadas por param-converter antes do guard (`Tenant` por id, `User` por id) **não
     são TenantAware** → nenhuma query escopada prematura vaza.

2. **Padronização das 5 rotas legadas** (habilita a trava markerless): `app_tenant_show`,
   `app_tenant_edit`, `app_tenant_delete`, `app_tenant_users`, `app_tenant_sedes` usam `{id}` para o
   **tenant** → renomear para `{tenantId}` (e ajustar assinaturas + os ~23 `path()`/`generateUrl`/
   `redirectToRoute` que as referenciam). Onde usam `Tenant $tenant` via param-converter,
   `#[MapEntity(id: 'tenantId')]` ou `find($tenantId)` explícito.

3. **Remendo explícito (cinto, defesa-em-profundidade).** Manter os 7 `escoparFiltroNoTenant`
   existentes e **adicionar** nas rotas que o B5 citou (`listUsers`, `downloadAnexoJustificativa`,
   `removeResourceAccess`, e as demais `{id}`-tenant que leem dados TenantAware). Redundante com a
   trava, mas auto-documenta e protege se o listener for desabilitado.

4. **Teste de regressão da classe.** Funcional: super-admin (sem tenant na sessão) abre uma rota de
   escritório do tenant A → os dados saem **escopados em A** (ex.: `listUsers` de A não mostra
   usuários de B; download de anexo de B logado "em A pela URL" → 404). Mutação: desligar a trava →
   vaza. Idealmente também um teste de arquitetura que enumera as rotas `{tenantId}` e garante o
   escopo (pega telas futuras).

## Efeito: destrava o B3

Com `listUsers`/`removeResourceAccess` presos ao tenant da URL pela trava, tornar `ResourceAccess`
TenantAware deixa de quebrar essas rotas (o filtro aponta para o tenant certo). **B3 vira a frente
seguinte**, separada.

## Fora do escopo (consciente)

- Implementação do **B3** (frente seguinte, após o B5).
- Alterar o **bypass global de permissão** do super-admin no `PermissionChecker` (continua passando
  em todas as permissões; só o escopo de dados das rotas de escritório muda).
- RBAC/poderes finos do super-admin (não há demanda — a conta é do dono da plataforma).

## Plano de entrega (frentes/commits separados)

1. **Listener da trava automática** + teste da classe (núcleo do B5).
2. **Padronização `{id}`→`{tenantId}`** das 5 rotas + callers (habilita a trava markerless).
3. **Remendo explícito** nas rotas citadas + testes por-rota.
4. (Depois) **B3** completo (ResourceAccess TenantAware).

Cada frente: investigar → testes cross-tenant → fix → mutação 2× → `/review` → commit. 🔴 FREIO em
qualquer migration (o B5 em si não tem migration; o B3 terá).
