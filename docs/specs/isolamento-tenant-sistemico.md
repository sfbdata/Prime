# Spec (design) — Isolamento de tenant sistêmico

> Resposta transversal ao achado de `docs/specs/auditoria-multitenant.md`: fechar a CLASSE
> inteira de vazamentos multi-tenant (listagens sem filtro + IDOR por id) com um mecanismo
> central, em vez de remendar query a query. Decisão: **sistêmico primeiro, depois por
> domínio**. Risco ALTO (toca todas as queries + modelo de dados). **Esta spec é design —
> implementar só após aprovação.**

## Objetivo
1. Toda query de entidade de negócio passa a filtrar por tenant **automaticamente**.
2. Carregar recurso por id de outro tenant retorna "não existe" (fecha o IDOR).
3. Prevenir regressão: esquecer o filtro numa query nova não reabre o vazamento.

## Mecanismo central

### 1. `TenantAwareInterface` + coluna `tenant`
- Interface marcadora `App\Shared\Contract\TenantAware` (getter `getTenant(): ?Tenant`).
- Entidades de negócio implementam a interface e têm `#[ORM\ManyToOne(Tenant)]`
  `JoinColumn(nullable: false)` + índice. (Kanban/Pasta/Expediente/ServiceDesk já têm; falta
  adicionar nos P0/P2 — ver tabela abaixo.)

### 2. Doctrine SQL Filter (`TenantFilter`)
- `App\Shared\Doctrine\Filter\TenantFilter extends Doctrine\ORM\Query\Filter\SQLFilter`.
- `addFilterConstraint(ClassMetadata $m, string $alias)`: se
  `$m->reflClass->implementsInterface(TenantAware::class)`, retorna
  `"$alias.tenant_id = " . $this->getParameter('tenant_id')`; senão `''`.
- Registrado em `config/packages/doctrine.yaml` (`orm.filters.tenant`), `enabled: false` por
  padrão (ligado por request).

### 3. Ativação por request
- Listener `kernel.request` (prioridade após o `TenantContextValidatorListener`): se há tenant
  ativo, `em.getFilters().enable('tenant').setParameter('tenant_id', $tenantId)`.
- **Contextos sem filtro (bypass explícito e auditável):**
  - `ROLE_SUPER_ADMIN` em telas de plataforma (sem tenant ativo) → filtro **não** habilitado.
  - Console/commands, fixtures, migrations → sem request, filtro fica desabilitado; quem
    precisar de escopo filtra manualmente.
  - Cron/relatórios cross-tenant → habilitar/trocar o parâmetro deliberadamente.

### 4. O ponto crítico: `find()` / ParamConverter — RESOLVIDO
- ✅ **Verificado por teste** (`tests/Shared/Functional/TenantFilterTest::testFiltroEmFindPorId`,
  ORM 3.x): com o filtro ligado, `EntityManager::find()` por id de outro tenant retorna
  **`null`** (após `em->clear()`). Como o ParamConverter (`Tipo $x`) usa `find()`, um id
  cross-tenant vira `null` → **404 automático**. O filtro fecha **listagens E IDOR por id**.
- Consequência: o guard `garantirRecursoDoTenant` por rota deixa de ser obrigatório e vira
  **defesa-em-profundidade opcional** (útil só se o filtro for desligado em algum contexto).
  O fix por domínio passa a ser essencialmente: **coluna `tenant` + marcar `TenantAware`**.

## Entidades a tornar `TenantAware`
| Domínio | Já tem tenant | Precisa coluna + migration + backfill |
|---|---|---|
| Kanban, ServiceDesk | ✅ | — |
| Pasta, Expediente | ✅ (entidades) | só marcar interface + fechar queries/rotas parciais |
| **Cliente** | ❌ | `Cliente`, `ClienteDocumento` — backfill via `criadoPor`→tenant |
| **Processo** | ❌ | `Processo`, `DocumentoProcesso`, `MovimentacaoProcesso`, `ParteProcesso` — backfill via `Processo.criadoPor`; filhos via processo |
| **Agenda** | ❌ | `Evento`, `LegendaCor` — backfill via `criador`; legenda precisa de dono (hoje global) |
| **Ponto** | ⚠️ parcial | `RegistroPonto`, `JustificativaPonto`, `JornadaColaborador` — via `user`→tenant |
| Tarefa | via Pasta | adicionar tenant direto OU garantir filtro via pasta nas queries |
| Profile | via User | manter via User; guard nas rotas admin por id |

> `User`, `Tenant`, `Permission`, `UserTenant`, `TenantRole` **NÃO** são tenant-aware
> (são compartilhados/estruturais) — o filtro não os toca.

## Backfill (premissa)
Cada entidade sem tenant ganha a coluna nullable → backfill a partir da origem natural
(`criadoPor`/`criador`/`user` → `user_tenant` ativo) → NOT NULL + FK + índice. Mesmo padrão
da migration do ServiceDesk (`Version20260625120342`). Onde a origem é multi-tenant ou nula,
relatar linhas órfãs antes do NOT NULL.

## Ordem de implementação (incremental, cada item committável + testado)
1. **Infra (sem efeito ainda):** `TenantAware`, `TenantFilter`, listener (entidades existentes
   já tenant-aware passam a ser filtradas — validar que Kanban/Pasta/ServiceDesk continuam OK).
2. **Decidir o guard de `find()`** (teste) → padronizar `garantirRecursoDoTenant`/resolver.
3. **P0 Cliente** → coluna+migration+backfill+interface+fechar rotas+testes cross-tenant.
4. **P0 Processo** → idem.
5. **P0 Agenda** → idem (+ decidir dono das legendas).
6. **P1/P2** Pasta/Expediente/Tarefa/Ponto/Profile → marcar interface + guards + testes.
7. Atualizar `AUTORIZACAO.md` (F1 fechada) e `CLAUDE.md` (novo mecanismo obrigatório).

## Testes
- Base: com filtro ligado, `findAll()`/`findBy()`/DQL de entidade tenant-aware só retorna o
  tenant ativo (teste cross-tenant por domínio).
- `find()` por id de outro tenant → null/404 (decide o guard).
- Contextos de bypass (super admin, console) não quebram.
- Toda a suíte (718) precisa continuar verde — o filtro afeta queries existentes; risco de
  regressão em telas que hoje (erradamente) dependem de ver cross-tenant é baixo, mas validar.

## Riscos
- O filtro afeta **todas** as queries — uma query legítima que precise cross-tenant (raro)
  quebra; mapear antes.
- `find()`/ParamConverter pode não ser coberto pelo filtro → guard obrigatório.
- Migrations de P0 mexem em tabelas grandes em produção (Cliente/Processo) — backfill + NOT
  NULL com cuidado; janela de deploy.
- Joined inheritance (Cliente PF/PJ) — o filtro precisa mirar a tabela base.

## Decisões a confirmar (antes de implementar)
1. Mecanismo: Doctrine SQL Filter global (recomendado) — ok?
2. Guard de `find()`: padronizar `garantirRecursoDoTenant` nas rotas por id como defesa em
   profundidade, independente do filter — ok?
3. Tarefa/Profile: manter isolamento transitivo (via Pasta/User) + guards, ou adicionar
   coluna tenant direta?
4. Legendas da Agenda: dar dono (tenant) ou tornar configuração por-tenant?
