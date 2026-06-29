# M4 — Isolamento das notificações por escritório (Notificacao → TenantAware)

Risco: **MÉDIO** (achado M4 da auditoria pós-remediação). Padrão de isolamento P0/P2.

## Vazamento

`Notificacao` (`src/Entity/Notificacao.php`) não tinha coluna `tenant`. Todas as queries do
sino (`NotificacaoRepository`) filtravam só por `usuario`. Um colaborador vinculado a dois
escritórios (A e B), logado em B, via no sino/índice/contador as notificações geradas em A
(título e URL de tarefa, evento, chamado e ponto do OUTRO escritório). O `marcarComoLida`
carregava a notificação por id sem checar tenant (IDOR). Risco de divulgação de metadados
(não de conteúdo): título + link, não o corpo do recurso.

**Insight verificado:** TODA notificação nasce por `NotificacaoService::criar` ou
`::criarNotificacao` (zero `new Notificacao` fora do service em código de produção) → setar o
tenant nesses 2 métodos cobre 100% das criações.

## Design

`Notificacao implements TenantAware` + coluna `tenant` NOT NULL (o atributo PHP TEM de se
chamar `tenant`). Efeitos:

- O `TenantFilter` auto-escopa TODAS as SELECTs por id/findBy do sino
  (`NotificacaoExtension`, `NotificacaoController::index/count/listaDropdown`) e o `find()` do
  value resolver no `marcarComoLida` → fecha o IDOR (404 quando a notificação é de outro
  escritório).
- Só os bulk DQL escapam o filtro → escopo de tenant **explícito** neles.

## Write-sites (passar `Tenant` aos 2 métodos do service + `setTenant`)

- `NotificacaoService::criar(User, Tenant, ...)` e `::criarNotificacao(User, Tenant, ...)`
  ganham o parâmetro `Tenant` e chamam `setTenant`.
- Fontes do tenant em cada chamador:
  - `notificarTarefa{Criada,Pendente,Concluida}` → `$tarefa->getTenant()`.
  - `notificarJustificativaEnviada` / `notificarNovoChamado` → o `$tenant` que já recebem.
  - `notificarJustificativa{Aprovada,Rejeitada}` → `$justificativa->getTenant()`.
  - `AgendaController` (3 sites: criarAjax/novo/cancelar) → `$evento->getTenant()`.
  - `ServiceDeskController` (4 sites: nova interação ×2, atribuição, mudança de status) →
    `$chamado->getTenant()`.
- Evento / Chamado / Tarefa / JustificativaPonto já são TenantAware (getTenant non-null em
  entidade persistida).

## Bulk (escopo explícito por tenant da sessão)

- `NotificacaoRepository::marcarTodasComoLidas(User, Tenant, ?categoria)` e
  `excluirDoUsuario(User, Tenant, ids)` ganham `Tenant` + `andWhere('n.tenant = :tenant')`.
- Idem nos wrappers do `NotificacaoService`.
- `NotificacaoController` injeta `TenantContext` e passa o tenant da sessão; **fail-closed** se
  não houver tenant (flash+redirect no `marcarTodasComoLidas`, JSON 400 no `excluir`).
- Read-sites: SEM mudança (o filtro cobre).

## B2 (junto)

Removido `NotificacaoRepository::removerAntigas` — bulk DELETE morto (zero chamadores em
src/tests/bin/config), sem tenant → footgun num TenantAware.

## Migration `Version20260629120000` (🔴 aplicar só no dev, isolada, após OK do dono)

`notificacao` +`tenant_id`: add nullable → backfill (1) `tarefa.tenant_id` p/ notif de tarefa;
(2) fallback **tenant único** p/ o resto (prod = 1 escritório → cobre tudo) → NOT NULL + FK
`FK_5ACD93869033212A` + índice `IDX_5ACD93869033212A`; **aborta** se sobrar null (multi-tenant
não-derivável). Aplicar via `migrations:execute 'DoctrineMigrations\Version20260629120000' --up`
(NUNCA `migrate` puro — 2 migrations antigas do Ponto fora do ledger) + `schema:update --force
--env=test`. **Dev tem 1 tenant e 0 notificações → backfill trivial.**

## Testes cross-tenant

- `NotificacaoIsolamentoRepositoryTest` (KernelTestCase, filtro alternado manualmente):
  leitura (findNaoLidas/count/paginadas) só do tenant ativo; `find()`-by-id de outro tenant →
  null (com `em->clear()`); `marcarTodasComoLidas`/`excluirDoUsuario` escopados (tenant A não
  toca B mesmo com o id na lista).
- `NotificacaoIsolamentoControllerTest` (WebTestCase, usuário multi-escritório A+B): contador e
  índice só de B; `lista-dropdown` (sino) só de B; aba **gestão** (gate `podeVerGestao` →
  `temNotificacaoGestao` + lista) escopada por escritório; `marcarComoLida` de notif de A logado
  em B → 404 (com `em->clear()` p/ esvaziar a identity map); `marcar-todas-lidas` em B não marca
  a de A.
- Testes existentes (`NotificacaoCsrf/Excluir/Index/Dropdown`) ajustados para `setTenant`.

**Mutação 2×:** (A) remover `TenantAware` da entidade → leitura/IDOR/dropdown/gestão vermelhos;
(B) inverter o `andWhere('n.tenant = :tenant')` → `!=` nos bulk → escopo vermelho (5 falhas).
Suíte **869/869** (859 + 10 novos).

## Revisão adversarial (`feature-review-agent`)

APROVADO COM RESSALVAS (3 BAIXA): (1) `notificarTarefa{Criada,Pendente,Concluida}` são código
morto pré-existente (zero chamadores) — o tenant adicionado a eles é defensivo, remoção fica
para follow-up; (2) lacuna de teste em `lista-dropdown`/aba gestão — **ENDEREÇADA** (2 testes
cross-tenant adicionados, mutação confirmada); (3) higiene de commit: NÃO arrastar
`sincronizacao-drive-bidirecional.md` (WIP do dono) nem `.playwright-mcp/`. Confirmado por
prova: `new Notificacao(` só nos 2 métodos do service; nomes FK/IDX batem com o ORM; backfill
seguro no dev (1 tenant / 0 notificações); `removerAntigas` sem chamador.
