# Spec — Isolamento de tenant do domínio Agenda (S4 / P0)

> Último P0 catastrófico da `docs/specs/auditoria-multitenant.md`, seguindo o mecanismo central
> (`isolamento-tenant-sistemico.md`) e o padrão de S2 Cliente / S3 Processo. **Risco ALTO**
> (modelo de dados + migration em produção; domínio 100% legado). Status: **AGUARDA APROVAÇÃO**
> (spec + migration) antes de codar; 2ª aprovação antes do banco.

## Problema

`Evento` e `LegendaCor` (`app/src/Entity/Agenda/`) **não têm `tenant`**. O `EventoRepository`
filtra só por usuário/visibilidade — nunca por tenant — e o ramo `visibilidade=todos AND
participantes=0` torna eventos visíveis para **qualquer** usuário de **qualquer** escritório.
5 rotas carregam `Evento` por id sem validar posse (IDOR: show/editar/excluir/cancelar/
atualizar-datas). As **legendas de cor são um catálogo GLOBAL** que um escritório edita/apaga
para todos. O **dropdown de participantes lista usuários de todos os tenants**.

## Objetivo

`Evento` e `LegendaCor` → `TenantAware` (filtro escopa listagens de calendário e carga por id) +
escopar por tenant o **dropdown/seleção de participantes** (User não é TenantAware; usar os
métodos prontos do `UserRepository`) + escopar a edição/remoção de legendas.

## Achados da investigação

### Entidades (legado, sem herança, PK int, sem tenant)
- **`Evento`** (`evento`) — `criador` = `ManyToOne(User)` **`nullable:false`** (`criador_id`),
  âncora confiável de tenant. Participantes `ManyToMany(User)` via `evento_participante`.
  **`cor` é string hex** (`COR_AZUL` default) — **não há FK para `LegendaCor`**. Campos:
  `titulo`, `descricao`, `local`, `dataInicio/Fim`, recorrência, `visibilidade`, `status`.
- **`LegendaCor`** (`legenda_cor`) — só `nome`, `cor`, `ordem`, timestamps. **Sem dono, sem
  relação alguma. Catálogo global. VAZIA no dev (0 linhas).**

### Dados do banco dev
- `evento`: **6 linhas, todas `criador_id` resolvem tenant 4, 0 órfãos**. `evento_participante`
  (junção, sem tenant — herda via evento). `legenda_cor`: **0 linhas**. 1 tenant (id 4).
- Backfill evento: `criador_id → user_tenant ativo` (ORDER BY ut.id ASC LIMIT 1) + fallback
  tenant único. Backfill legenda: **fallback de tenant único** (não dá para derivar por evento —
  sem FK; o join por `cor` é frágil/ambíguo e a tabela está vazia).

### Repositórios (tudo DQL, sem SQL nativo)
- `EventoRepository`: `findForCalendar`, `findByDateRange`, `findByUser`, `findUpcomingByUser`,
  `findTodayEvents` — classe (A), cobertos pelo filtro quando `Evento` for `TenantAware`.
  ⚠️ **`updatePastEventsStatus()` é bulk DQL UPDATE** — o SQLFilter **não** se aplica a bulk
  update/delete; cruzaria tenants. **Mas não tem chamador hoje** (legado morto/cron externo) →
  inerte. Registrar; se reativado, precisa `WHERE tenant_id` manual ou job por-tenant.
- `LegendaCorRepository`: `findAllOrdered`/`findAll` — classe (C), cobertos só após `LegendaCor`
  TenantAware. `find($id)` por PK **não** é filtrado (IDOR de legenda) → validar posse.

### Write-sites / vetores (AgendaController, EventoType, AppFixtures)
| # | Local | Ação | Correção |
|---|---|---|---|
| W1 | `AgendaController::criarAjax` `:152` `new Evento` | persist | `setTenant($tenant)` |
| W2 | `AgendaController::novo` `:225` `new Evento` | persist | `setTenant($tenant)` |
| W7 | `AgendaController::salvarLegendas` `:472` `new LegendaCor` | persist | `setTenant($tenant)` |
| W10 | `AppFixtures` `:737` `new Evento` (loop) | seed | `setTenant` (via criador→tenant) |
| P1 | `index()` `:64` `userRepository->findBy(isActive)` | dropdown participantes | `findColaboradoresAtivosPorTenant($tenant)` |
| P2 | `EventoType` `:109` query_builder de participantes | dropdown | passar tenant via option + método por-tenant |
| P3 | `criarAjax` `:182` `userRepository->find($userId)` | resolve participante por id | `findPorIdsETenant`/`findPorIdETenant` (rejeita user de outro tenant) |
| L1 | `salvarLegendas` `:450/:481-484` `findAll()` + remove em massa | edição/remoção de legenda | `findAll` já filtrado após TenantAware; garantir que `find($id)`/remoção só toquem o tenant atual |

- IDOR show/editar/excluir/cancelar/atualizar-datas: ParamConverter `Evento $evento` → fechado
  pelo filtro quando `Evento` for `TenantAware` (404 cross-tenant). Guard por-id é defesa
  opcional (deferido, como S2/S3).
- Hardening menor (decidir incluir): `agenda_atualizar_datas` sem CSRF; `agenda_legendas` (GET)
  sem `canAccessModule`.

## Mudanças de código (após aprovação)
- **Entidades**: `Evento` e `LegendaCor` `implements TenantAware` + `ManyToOne(Tenant)`
  `nullable:false` + get/set (atributo `tenant`).
- **AgendaController**: `setTenant` em W1/W2/W7; trocar os 3 sites de participantes (P1/P2/P3)
  pelos métodos tenant-scoped do `UserRepository`; garantir legendas escopadas (L1).
- **EventoType**: receber o tenant por option e usar `findColaboradoresAtivosPorTenant`.
- **AppFixtures**: `setTenant` nos eventos de seed.
- (Se aprovado) CSRF em `atualizar-datas` + `canAccessModule` em `agenda_legendas`.

## Migration (proposta — **NÃO aplicar sem 2ª aprovação**)
Mesma estratégia (add nullable → backfill → fallback tenant único → NOT NULL + FK + índice),
2 tabelas. `evento` via `criador_id`; `legenda_cor` direto pelo fallback de tenant único (vazia
no dev; sem âncora confiável). `evento_participante` **não** ganha tenant (herda via `evento`,
já TenantAware). Nomes FK/índice via `migrations:diff`.

```php
// evento
ALTER TABLE evento ADD tenant_id INT DEFAULT NULL
UPDATE evento SET tenant_id = (SELECT ut.tenant_id FROM user_tenant ut
  WHERE ut.user_id = evento.criador_id AND ut.is_active = true ORDER BY ut.id ASC LIMIT 1) WHERE tenant_id IS NULL
UPDATE evento SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
  WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
ALTER TABLE evento ALTER COLUMN tenant_id SET NOT NULL  + FK + índice

// legenda_cor (catálogo por-tenant; dev vazio -> fallback cobre tudo)
ALTER TABLE legenda_cor ADD tenant_id INT DEFAULT NULL
UPDATE legenda_cor SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
  WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
ALTER TABLE legenda_cor ALTER COLUMN tenant_id SET NOT NULL  + FK + índice
```
Produção: com >1 tenant e legenda/evento órfão, o NOT NULL aborta (rollback) → tratar os dados
antes. Para legendas globais pré-existentes em prod multi-tenant, a decisão de negócio é
**duplicar o catálogo por tenant** (seed) — não adivinhar por cor.

## Testes (cross-tenant)
- **Repo** (`tests/Agenda/Functional/`): `findForCalendar`/`findByDateRange` só do tenant ativo
  (incl. o ramo `visibilidade=todos`); `find()` por id de Evento de outro tenant → null;
  `LegendaCor` (findAllOrdered) só do tenant ativo + find() IDOR.
- **HTTP**: `agenda_show`/`editar`/`excluir`/`cancelar` de outro tenant → 404/403; o dropdown de
  participantes não lista usuário de outro tenant; adicionar participante de outro tenant é
  rejeitado; salvarLegendas de um tenant não apaga legendas de outro.

## Decisões a confirmar
1. **`LegendaCor` por-tenant com backfill de tenant único** (recomendado — vazia no dev, sem dono,
   derivar por cor é frágil). OK?
2. **Corrigir o vazamento do dropdown de participantes nesta frente** (P1/P2/P3) — recomendado
   (é o vetor de usuários citado na auditoria e os métodos do `UserRepository` já existem). OK?
3. **Incluir o hardening menor** (CSRF em atualizar-datas + permissão em agenda_legendas) ou
   deferir?

## Status de implementação (jun/2026) — ✅ ENTREGUE

- `Evento` + `LegendaCor` `TenantAware`; migration `Version20260625200952` aplicada no dev (6
  eventos → tenant 4 via criador; `legenda_cor` vazia; 0 órfãos). `schema:validate` OK dev+teste.
  Suíte **760/760**.
- Participantes escopados por tenant nos 3 vetores (index, EventoType, criarAjax) + o modal
  (usa a lista já escopada). Legendas: edição/remoção só do tenant atual (sem `find($id)` cru).
  CSRF em `atualizar-datas` (controller + template). Permissão em `agenda_legendas`.
- Revisão adversarial (2 agentes): **aprovada com ressalvas** (só higiene de commit).

## Follow-ups / fora de escopo
- 🔴 **`DemitirFuncionarioUseCase`** (`app/src/Tenant/UseCase/DemitirFuncionarioUseCase.php`,
  ~linhas 88/124): faz **SQL nativo** `DELETE`/`INSERT` em `evento_participante` filtrando só por
  `user_id` (sem `evento.tenant_id`). Um usuário vinculado a >1 tenant, ao ser demitido em um
  escritório, teria participações alteradas em eventos de **outro** tenant. SQL nativo **escapa do
  filtro**. Pré-existente, domínio Tenant/offboarding (não Agenda). **Corrigir em frente própria**:
  escopar por `evento_id IN (SELECT id FROM evento WHERE tenant_id = :tenant)`. Só se manifesta com
  múltiplos tenants (hoje 1).
- `updatePastEventsStatus` (bulk DQL UPDATE escapa do filtro) — **sem chamador** hoje; se
  reativado (cron), precisa `WHERE tenant_id` manual ou job por-tenant.
- **CSRF assimétrico**: `criar-ajax` e `legendas/salvar` (POST JSON) seguem sem CSRF (pré-existente,
  como todo endpoint JSON do projeto). Fora do escopo do hardening pedido; decisão app-wide.
- Guard por-id de defesa-em-profundidade (deferido, como S2/S3).
- `User` permanece compartilhado (escopo via `UserTenant`), por design.
