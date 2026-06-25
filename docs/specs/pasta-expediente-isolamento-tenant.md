# Spec — Isolamento de tenant: Pasta + Expediente (P1)

> P1 da `docs/specs/auditoria-multitenant.md` (severidade menor que os P0). Diferente dos P0:
> as entidades **já têm coluna `tenant`** — falta só marcá-las `TenantAware` para o filtro
> global cobri-las, o que **fecha os IDORs automaticamente**. **Sem migration para marcar**
> (a coluna já existe); a única migration possível é opcional (unique composto do `nup`).
> Risco MÉDIO (regressão de ligar o filtro em domínio muito consultado, já mapeada).

## Achado central

8 entidades já têm `private ?Tenant $tenant` (`ManyToOne(Tenant)`, `nullable:false`, índice,
`getTenant()`), atributo no nome exato exigido pelo `TenantFilter`, e **nenhuma implementa
`TenantAware`**. Marcar a interface (1 linha/arquivo) liga o filtro para elas — **zero
migration**, e como já são criadas com tenant, **nenhum write-site muda**.

| Entidade | Arquivo | Tenant direto |
|---|---|---|
| `Pasta` | `app/src/Pasta/Entity/Pasta.php` | sim |
| `PastaDocumento` | `.../PastaDocumento.php` | sim |
| `PastaSecao` | `.../PastaSecao.php` | sim |
| `PastaMensagem` | `.../PastaMensagem.php` | sim |
| `PastaChecklistItem` | `.../PastaChecklistItem.php` | sim |
| `PastaObservacaoDetalhes` | `.../PastaObservacaoDetalhes.php` | sim |
| `PastaObservacaoFinanceira` | `.../PastaObservacaoFinanceira.php` | sim |
| `Marcador` (Expediente) | `app/src/Expediente/Entity/Marcador.php` | sim |

> **Kanban fora de escopo:** já isolado por `KanbanBoard.temAcesso()`; só `KanbanBoard` tem
> coluna `tenant` (as outras 7 entidades Kanban escopam via board). `KanbanMarcadorController`
> já valida tenant pós-`find()` (não explorável). Deixar como está.

## Leaks que o filtro FECHA ao marcar TenantAware (confirmados explor áveis hoje)
- **`PeticionarController`** (ParamConverter `Pasta $pasta`/`PastaDocumento $doc` por id, sem
  guard): show/upload/criarTexto/uploadImagem/editarTexto/exportarTexto → **IDOR** ver/editar/
  exportar/enviar peça em pasta de outro tenant. Filtro → `find()` cross-tenant = null → 404.
- **`SincronizarMarcadoresDaPastaUseCase`** (`pastaRepository->find($pastaId)` sem tenant):
  sobrescreve/zera marcadores de pasta alheia (corrupção). Filtro → 404.
- **`MoverDocumentoParaSecaoUseCase`** (valida só `secaoDestino`, não o `$documento`) +
  **`ReordenarDocumentosUseCase`** + `PastaSecaoController::{renomear,excluir,moverDocumento,
  reordenarDocumentos}` (`em->find()` por id): IDOR de documento/seção. Filtro → 404.
- (renomear/excluir já têm guard no UseCase via `getTenant()!==$tenant` — não exploráveis, mas
  o filtro move a defesa para a camada certa e remove o oráculo de existência.)

## A única regressão real — UNIQUE global do `nup`

`pasta.nup` tem UNIQUE **global** (`uniq_9b3bbc81bf45c3b7`). `CriarPastaUseCase:32` e
`EditarPastaUseCase:30` fazem `findOneBy(['nup' => $nup])` **de propósito sem tenant** para casar
com essa constraint. Com `Pasta` `TenantAware` + filtro, esse `findOneBy` passa a filtrar por
tenant → se o tenant B usar um NUP já existente no A, a validação amigável passa e o `flush`
viola a UNIQUE global → **`UniqueConstraintViolationException` (500)**. Mesmo padrão do
`numero_processo` (S3). **Decisão necessária** (ver abaixo).

## Plano (após aprovação)
1. **Marcar `TenantAware`** as 8 entidades (`implements Auditavel, TenantAware`; import de
   `App\Shared\Contract\TenantAware`). Zero migration.
2. **Tratar o `nup`** conforme a decisão:
   - **(a) recomendado — unique composto:** trocar a UNIQUE global por `(tenant_id, nup)`
     (entidade: `#[ORM\UniqueConstraint(columns:['tenant_id','nup'])]` + remover unique do campo)
     **+ migration** (drop unique global, add composto) + escopar os `findOneBy(['nup'])` por
     tenant (passar o tenant aos UseCases). Semântica multi-tenant correta; consistente com S3.
   - **(b) sem migration:** manter UNIQUE global e **desligar o filtro** nas 2 checagens
     `findOneBy(['nup'])` (`em->getFilters()->disable('tenant')` em volta da query). Preserva o
     oráculo de NUP entre tenants (vazamento de existência), mas zero schema.
3. **Verificar** que os repos que já filtram tenant explicitamente (PastaRepository,
   MarcadorRepository — ~20 métodos) continuam OK: dupla filtragem é **inócua** (mesmo valor de
   sessão), confirmado pela investigação.
4. **Smoke/cosmético:** `TenantController::resolveResourceLabels` (`find()` de Pasta p/ label)
   degrada NUP→`"Pasta #id"` quando super-admin vê tenant ≠ sessão. Não vaza; nota.

## Testes (cross-tenant)
- **HTTP**: `peticionar` show/editar/exportar de pasta/doc de outro tenant → 404; sincronizar
  marcadores de pasta de outro tenant → 404; mover/reordenar documento de outro tenant → 404.
- **Repo/filtro**: `find()`/`findByFilters` de Pasta e `find()` de Marcador só do tenant ativo;
  `find()` por id cross-tenant → null.
- **NUP** (se decisão a): dois tenants podem ter o mesmo NUP; `findOneBy` escopado.
- Suíte completa (760) verde — atenção a testes existentes de Pasta/Tarefa que possam depender
  do filtro desligado (a investigação indicou que Tarefa **melhora**, não quebra).

## Status de implementação (jun/2026) — ✅ ENTREGUE

- 8 entidades `TenantAware` (zero write-site). Migration `Version20260625203433` (NUP global →
  composto `(tenant_id, nup)`) aplicada no dev. `schema:validate` OK dev+teste. Suíte **765/765**.
- **NUP escopado por tenant nos UseCases** (`Criar`/`EditarPastaUseCase`:
  `findOneBy(['nup','tenant'])`) — não só pelo filtro, porque em **CLI o filtro fica desligado**
  e há call-site real (`ImportarAcervoCommand:236`). Sem isso, o importador pularia NUPs legítimos
  de outro tenant como "já existe" (achado da revisão adversarial, corrigido). Teste unit do
  `EditarPastaUseCase` atualizado para asseverar o critério com tenant.
- Revisão adversarial (2 agentes): reprovou o ponto do NUP/CLI → **corrigido e re-verificado**;
  `TarefaController:78` `find($pastaId)` cross-tenant agora → null é **melhoria** (fecha leak),
  não regressão (suíte verde).

## Follow-ups / fora de escopo
- **Deploy prod:** antes da migration do NUP, conferir `SELECT nup, count(*) FROM pasta GROUP BY
  nup HAVING count(*) > 1` (deve dar 0 — o unique era global). O `up()` só rodou em tabela vazia
  no dev; o argumento de não-duplicata é correto enquanto o unique global existia, mas conferir.
- **Cobertura** (não-bloqueante): IDOR direto de `PastaDocumento`/`PastaSecao` por id e o caminho
  HTTP mover/reordenar documento cross-tenant não têm teste dedicado (mesma mecânica de Pasta,
  já provada); idem um teste do `ImportarAcervoCommand` sob a nova constraint.
- `TenantController::resolveResourceLabels` (`find()` de Pasta p/ label) degrada NUP→`"Pasta #id"`
  quando super-admin vê tenant ≠ sessão. Cosmético, não vaza.
- `DemitirFuncionarioUseCase` bulk DQL `UPDATE Pasta SET responsavel ...` filtra só por `:user`
  (sem tenant) — bulk **escapa do filtro**; pré-existente, não muda com TenantAware. (Já é
  follow-up registrado junto com o SQL nativo de `evento_participante`.)
- Kanban (isolado por board) — manter; marcar `KanbanBoard`/filhas seria outra frente.
- P2 (Tarefa direta/Ponto/Profile) — próximo da fila após P1.
