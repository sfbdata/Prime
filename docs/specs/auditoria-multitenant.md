# Auditoria de isolamento multi-tenant — JusPrime

> Varredura read-only de 9 domínios (jun/2026). **Não corrige — mapeia e prioriza.**
> Conclusão: o isolamento entre escritórios (tenants) está **sistemicamente quebrado**.
> Causa-raiz comum: (a) várias entidades de negócio **não têm coluna `tenant`**;
> (b) **não há Doctrine SQL filter** global de tenant; (c) rotas carregam por **id
> enumerável** sem validar posse de tenant (ParamConverter/find), confiando em
> `canAccessResource`, que **não valida o tenant do recurso** (Falha F1 do `AUTORIZACAO.md`)
> e é bypassado por `isSystem` (admin de escritório) e `ROLE_SUPER_ADMIN`.

## Veredito por domínio

| Domínio | Entidade tem tenant? | Queries filtram? | IDOR por id? | Veredito |
|---|---|---|---|---|
| **Cliente** | ❌ não | ❌ não | ✅ sim | 🔴 VAZA + IDOR |
| **Processo** | ❌ não | ❌ não | ✅ sim | 🔴 VAZA + IDOR |
| **Agenda** (legado) | ❌ não | ❌ não | ✅ sim | 🔴 VAZA + IDOR |
| **Pasta** | ✅ sim | ⚠️ parcial | ✅ sim | 🟠 IDOR + queries parciais |
| **Expediente** | ✅ sim (Marcador) | ⚠️ parcial | ✅ sim | 🟠 IDOR pontual |
| **Tarefa** | ⚠️ via Pasta | ❌ não | ✅ sim | 🟠 vaza p/ user multi-tenant |
| **Ponto** (legado) | ⚠️ parcial | ⚠️ parcial | ✅ sim | 🟡 IDOR mitigado por user.id |
| **Profile** | ❌ (via User) | ⚠️ parcial | ⚠️ parcial | 🟡 risco menor (perfil pessoal) |
| **Kanban** | ✅ (Board) | ✅ sim | ❌ não | ✅ ISOLADO |
| ServiceDesk | ✅ (corrigido H1) | ✅ sim | ❌ (guard) | ✅ corrigido nesta sessão |

## P0 — Catastrófico (entidade sem tenant + listagem vaza tudo)
Qualquer usuário autenticado com o módulo enxerga dados de **todos** os escritórios.

- **Cliente** — `Cliente`/`ClienteDocumento` sem tenant; `ClienteRepository::findAll*/findByFilters`
  sem filtro; `ClienteController::index` lista tudo; show/edit/delete/upload/view/download/edit/delete
  de documento por id sem validar tenant. Vaza nome, CPF/CNPJ, endereço, telefone e **documentos**
  (RG, procuração, contratos) de todos os clientes de todos os escritórios. (~18 achados críticos.)
- **Processo** — `Processo`/`DocumentoProcesso`/`MovimentacaoProcesso`/`ParteProcesso` sem tenant;
  `ProcessoController::index` usa `findAll()`/`findByFilters` sem tenant; dropdowns
  (tribunais/classes/assuntos/números) vazam metadados; show/edit/delete por id (IDOR);
  `findByNumeroProcesso` sem tenant. Vaza a carteira de processos (casos jurídicos) inteira.
- **Agenda** (legado) — `Evento`/`LegendaCor` sem tenant; `EventoRepository`
  (findForCalendar/findByDateRange/findByUser/findUpcoming/findToday) sem filtro de tenant;
  show/editar/excluir/cancelar/atualizar-datas por id (IDOR); legendas globais; dropdown de
  participantes lista usuários de todos os tenants. Vaza calendários e compromissos.

## P1 — Tenant existe, mas há IDOR/queries vazando
Mais barato de corrigir (a entidade já tem tenant; falta guardar/filtrar).

- **Pasta** — entidades têm `tenant` (bom). Mas: `PastaDocumentoRepository::findByPastaECategoria`
  sem tenant; `PastaSecaoController` faz `em->find(...)` por id sem validar tenant (renomear/excluir
  seção, mover documento — IDOR); `EditarPastaUseCase` `findOneBy(['nup'])` sem tenant;
  `PeticionarController` ParamConverter por id sem validar tenant.
- **Expediente** — `Marcador` tem tenant e o repo filtra. Mas `SincronizarMarcadoresDaPastaUseCase`
  faz `pastaRepository->find($pastaId)` sem usar o tenant recebido (IDOR ao manipular marcadores);
  `KanbanMarcadorController` valida tenant pós-`find()` (race).

## P2 — Frágil / IDOR mitigado
- **Tarefa** — `TarefaRepository::findByResponsavel`/`findByProcesso` sem tenant; validação de
  acesso valida posse dos usuários mas **não** que a Pasta é do tenant atual → vaza p/ usuário
  vinculado a múltiplos tenants. Validação tardia (pós-ParamConverter).
- **Ponto** (legado) — rotas de justificativa/ponto/batida carregam por id e validam só `user.id`
  (não o tenant) — IDOR mitigado pela checagem de dono, mas sem validação explícita de tenant.
  `JornadaTenant`/`Feriado` têm tenant; `RegistroPonto`/`JustificativaPonto`/`JornadaColaborador` não.
- **Profile** — `UserProfile` sem tenant (isola via `User→UserTenant`); `buscarPorUsuario` sem
  tenant; rotas de admin (`TenantController`, `TenantUserProfileController`) resolvem `User` por id
  no ParamConverter antes de validar vínculo. Risco menor (dado pessoal do próprio usuário).

## OK
- **Kanban** — `KanbanBoard` tem tenant, repos filtram (`findPorTenantEId`), sem IDOR. Único
  domínio são por construção. (1 bug funcional no path do anexo, sem vazamento.)
- **ServiceDesk** — corrigido nesta sessão (H1: coluna tenant + filtros + guard 404).

## Recomendação de remediação (ordem)
1. **Mitigação imediata possível** (decisão do dono): considerar restringir/feature-flag os
   módulos P0 (Cliente, Processo, Agenda) em produção até o fix, dado o vazamento aberto.
2. **P0 primeiro** — para cada um (Cliente, Processo, Agenda): adicionar `tenant` às entidades +
   migration + backfill (via `criadoPor`/`criador`) + filtrar TODAS as queries + guard de tenant
   nas rotas por id + testes cross-tenant. Cada um é um projeto de risco ALTO (modelo de dados).
3. **P1/P2** — guardas de tenant nas rotas por id + filtro nas queries faltantes (mais barato).
4. **Sistêmico** — avaliar um **Doctrine SQL Filter** global de tenant + repositório base
   `findPorTenant()` + ParamConverter tenant-aware, para fechar a classe inteira de bugs e
   prevenir regressão. Atualizar `AUTORIZACAO.md` (F1) e o `CLAUDE.md`.

## Notas
- Achados completos (50+) no transcript do workflow `auditoria-multitenant` (run wf_da46a56d-1b6).
- `ROLE_SUPER_ADMIN` e `TenantRole.isSystem` bypassam qualquer checagem (por design); por isso o
  fix tem de ser no **dado/query** (filtro de tenant), não só em permissão.
