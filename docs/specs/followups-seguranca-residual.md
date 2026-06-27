# Follow-ups de segurança residual (frente "C") — handoff

> Itens de vazamento/segurança que sobraram **depois** que P0/P1/P2 (Cliente, Processo, Agenda,
> Pasta, Expediente, Tarefa, Profile, Ponto) já estão isolados por tenant. São leaks REAIS
> (não cosméticos), em geral por código que **escapa do `TenantFilter`** (SQL nativo, bulk DQL
> UPDATE/DELETE, validação global) ou por arquivos servidos direto do `public/`.
>
> Padrão do projeto p/ cada item: investigar (subagente read-only) → spec se ALTO/MÉDIO → implementar
> (orquestrador) → testes cross-tenant → `/review`. Migration só com aprovação do dono (freio; prod =
> bluejus.com.br). Contexto geral: `PROGRESSO-PENDENCIAS.md` + memória `project_remediacao_multitenant`.

## Ordem recomendada
C1 (pronto, concreto) → C2 (rápido) → C3 (migration) → C4 (enumerar) → C5 (maior). **C6 está BLOQUEADO** (decisão de produto).

## STATUS (atualizar ao avançar) — jun/2026
- ✅ **C1** `DemitirFuncionarioUseCase` cross-tenant — COMMITADO `cd95d52`.
- ✅ **C2** `updatePastEventsStatus` (era morto) removido — COMMITADO `f344653`.
- ✅ **C3** cpf/cnpj de Cliente únicos por escritório (Opção B, migration `Version20260626150000`) — COMMITADO `787c97b`.
- ✅ **C4** CSRF em endpoints AJAX/JSON — **COMPLETO** (pendente commit de C4b/C4c). **C4a** (jornada +
  `ponto_batida`, ALTO) COMMITADO `79798d4`. **C4b** (Kanban, **12** endpoints — não 14: board criar/editar
  já usam form CSRF) + **C4c** (Agenda/Notificação, 3) ENTREGUES, reusando a infra do C4a (interceptador em
  `base.html.twig` + trait `App\Shared\Trait\ValidaCsrfAjaxTrait`). Enumeração re-verificada por leitura +
  `debug:router` (não confiou no workflow). Suíte **825/825**; mutação confirmada (15/16 vermelhos ao
  neutralizar o trait). Detalhes em `docs/specs/csrf-ajax-endpoints.md`. Follow-up aberto: IDOR de
  `kanban_card_mover` (frente própria).
- ✅ **C5** uploads fora do `public/` (ALTO) — **RESOLVIDO E DEPLOYADO**. **C5.1** (`3426be6`): nginx
  (dev+prod) deixa de servir `/uploads/` estático → front controller + firewall exige login (fecha o bypass
  anônimo de TODOS os módulos); rota autenticada das imagens do editor (`PecaImagemController`). **Provado em
  prod: `200→404`.** Spec `docs/specs/uploads-fora-do-public.md`.
  ❌ **C5.2/C5.3/C5.4 CANCELADOS.** Descobriu-se no deploy que `var/uploads` **não é volume persistido** em
  prod (só `uploads_prod` em `public/uploads`) → mover p/ var quebraria. Como o bloqueio do nginx (C5.1) já
  protege todo o `/uploads/`, **todos os uploads ficam em `public/uploads/` protegidos pelo nginx** — mover por
  módulo virou desnecessário. Os configs de clientes/justificativas/chamados (que `dcceb14`/`7f269e4` tinham
  apontado p/ var) foram **revertidos p/ public** (`16fc10d`).
- ⛔ **C6** frestinha super-admin — BLOQUEADO (decisão de produto). Só o Ponto foi fechado.
- **Pendência manual:** smoke no browser do interceptador CSRF do C4a (Chrome ausente no ambiente da sessão).
- **Deploy:** a migration do C3 (`Version20260626150000`) precisa entrar no runbook `DEPLOY-PROD-multitenant.md`.

---

## C1 — `DemitirFuncionarioUseCase` escreve cross-tenant 🔴 (começar por aqui)
- **Arquivo:** `app/src/Tenant/UseCase/DemitirFuncionarioUseCase.php` (rota `app_tenant_user_demitir` no `TenantController`).
- **Problema:** `removerResponsabilidades()` e `transferirResponsabilidades()` filtram **só por `user_id`**, sem tenant:
  - DQL bulk `UPDATE Pasta p SET p.responsavel = ... WHERE p.responsavel = :user` e idem `Chamado` — bulk DQL **não aplica o TenantFilter**.
  - SQL nativo `DELETE/INSERT` em `tarefa_responsaveis` e `evento_participante` por `user_id`.
  - → demitir um funcionário **multi-tenant** de UM escritório apaga/transfere as responsabilidades dele em **TODOS** os escritórios (corrupção/vazamento cross-tenant).
- **Fix:** escopar cada operação por `$input->tenant`. Como `tarefa` e `evento` JÁ têm `tenant_id` (P2.1/S4) e `pasta`/`chamado` também:
  - Pasta/Chamado DQL: `AND p.tenant = :tenant` / `AND c.tenant = :tenant`.
  - `tarefa_responsaveis`: `... AND tarefa_id IN (SELECT id FROM tarefa WHERE tenant_id = :tid)`.
  - `evento_participante`: `... AND evento_id IN (SELECT id FROM evento WHERE tenant_id = :tid)`.
- **Migration:** não. **Risco:** MÉDIO (gestão User/Tenant + write cross-tenant).
- **Teste:** demitir user com vínculo em A e B, responsável por pasta/chamado/tarefa/evento nos dois → após demitir de A, os dados de B ficam intactos.

## C2 — `updatePastEventsStatus` bulk DQL sem tenant
- **Arquivo:** `app/src/Repository/EventoRepository.php:245`.
- **Problema:** bulk UPDATE de status de eventos passados, sem escopo de tenant; **nenhum chamador encontrado em `src/`** (provável morto ou job/cron).
- **Fix:** confirmar chamador (grep amplo incl. `src/Command`, cron, agendadores). Se vivo → escopar por tenant. Se morto → remover.
- **Migration:** não. **Risco:** BAIXO.

## C3 — unique global de `cpf`/`cnpj` (Cliente) → composto por tenant
- **Arquivos:** `app/src/Cliente/Entity/ClientePF.php:9` (`#[UniqueEntity('cpf')]`), `ClientePJ.php:9` (`#[UniqueEntity('cnpj')]`); conferir índice unique no banco (`\d cliente`).
- **Problema:** cpf/cnpj únicos **globais** → o mesmo cliente não pode ser cadastrado em 2 escritórios, e a validação vaza a existência do cadastro cross-tenant (msg "já cadastrado").
- **Fix:** (a) índice unique global → composto `(tenant_id, cpf)` / `(tenant_id, cnpj)` via migration (conferir duplicados em prod antes — já no runbook de deploy); (b) `UniqueEntity` escopado por tenant — `UniqueEntity` não escopa trivialmente; avaliar validação no UseCase (findOneBy cpf+tenant) ou custom. Espelha o que foi feito com `numero_processo` (S3) e `nup` (P1).
- **Migration:** SIM. **Risco:** MÉDIO.

## C4 — CSRF ausente em endpoints JSON
- **Problema:** endpoints POST/JSON mutantes sem verificação CSRF (candidatos: `ponto_batida`, `app_jornada_colaborador_save/delete`, `app_jornada_tenant_save`, e outros AJAX). Em S4 o `agenda atualizar-datas` já ganhou CSRF — usar de referência.
- **Fix:** **enumerar** os endpoints JSON mutantes (grep por `JsonResponse` + `methods: ['POST'|'DELETE']` sem `isCsrfTokenValid`), decidir entre CSRF stateful (token storage) ou stateless token; aplicar.
- **Migration:** não. **Risco:** MÉDIO. Investigar/enumerar primeiro (não há lista fechada ainda).

## C5 — Uploads fora do `public/` (app-wide)
- **Problema:** uploads de `pastas`, `justificativas`, `clientes`, `perfil` ainda em `public/uploads/*` → arquivos com PII acessíveis por URL direta (sem auth/tenant/posse). O H2 só tratou os anexos do ServiceDesk.
- **Fix:** mover cada dir para `var/uploads/*` + rota controlada (auth + tenant + posse), padrão de `docs/specs/servicedesk-anexo-download-seguro.md` (H2). No deploy, mover os arquivos existentes.
- **Migration:** não (mas precisa mover arquivos no deploy). **Risco:** ALTO (PII exposta). Maior esforço (vários módulos) — considerar uma frente por módulo.

## C6 — 🔴 Frestinha super-admin (BLOQUEADO — não iniciar)
- **Estado:** depende da **decisão de produto sobre os poderes do super-admin** (combinada para o fim das pendências).
- **Detalhe + fix proposto:** `docs/specs/ponto-isolamento-tenant.md` (follow-up "frestinha super-admin"): guard por-id `$entidade->getTenant()?->getId() === $tenant->getId()` nas rotas admin do Ponto + `'tenant'=>$tenant` no `findBy` do `aprovarTodos` + teste do vetor super-admin-sem-tenant. É sistêmico (mesmo padrão em todos os domínios via `TenantController`).
