# Progresso — Pendências do JusPrime

> Documento vivo. Ponto de retomada entre sessões. Atualizar ao fim de cada sub-etapa,
> **antes** de pedir o commit ao humano. Plano completo:
> `.claude/plans/atualize-a-questao-do-humming-newell.md`.
>
> 🚀 **Deploy em produção:** checklist consolidado de TODAS as migrations da remediação em
> `docs/specs/DEPLOY-PROD-multitenant.md` (ordem, pré-checks, travas de abort, H2 uploads).
>
> ➡️ **FRENTE C — segurança residual:** `docs/specs/followups-seguranca-residual.md`.
> ✅✅ **DEPLOY EM PRODUÇÃO EXECUTADO E VALIDADO — 2026-06-27 (bluejus.com.br).** Toda a remediação
> (P0-P2.3, C1-C3, C4a/b/c, C5.1) está LIVE. 1 tenant em prod, 8 migrations aplicadas (2 antigas de Ponto
> pulam sozinhas), C5.1 provado `200→404`. Config de uploads revertido p/ public (`16fc10d`) — ver abaixo.
> 🟡 **Falta:** smoke logado em prod. **C5.2/C5.3/C5.4 CANCELADOS** (nginx do C5.1 cobre tudo; `var/uploads`
> não é volume). C6 super-admin BLOQUEADO. Follow-up aberto: IDOR `kanban_card_mover`.
>
> 🆕 **FRENTE SELF-SERVICE DE ESCRITÓRIOS (2026-07-01): COMPLETA e commitada (Fases 1→3).**
> Handoff/spec: `docs/specs/self-service-escritorios.md` (bloco "🧭 Estado atual / Handoff" no topo).
> Commits: `576c093` (F1 switcher), `bf4af10` (2a criar), `beb2f5b` (2b soft delete), `3d8cc95` (3 cadastro
> público). Suíte 961/961. Dívidas na spec: trusted-proxies em prod (rate limit), purga de PII/quarentena,
> validação real da OAB (RS03), extrair validação OAB. Futuro: transferir titularidade, badge agregado.

## Tabela mestre

| ID | Etapa | Complexidade | Status | Commit/Obs |
|----|-------|--------------|--------|------------|
| E1 | Finalizar tema escuro (ajustes de cor) | Baixa | ✅ feito | 14 trechos em 6 arquivos; lint Twig OK |
| E2 | Corrigir bug tipo notificação evento + atualizar 2 specs | Baixa | ✅ feito | lint PHP OK; 17/17 testes Notificacao |
| E3 | `notificarNovoChamado()` + conserto de bug crítico do ServiceDesk | Média | ✅ feito | suíte 705/705; revisão adversarial OK |
| H1 | Hotfix: isolamento multi-tenant do ServiceDesk (dashboard + IDOR) | Alta | ✅ feito | coluna tenant + migration + filtros + guard 404; suíte 715/715 |
| H2 | Hotfix: download seguro de anexos do ServiceDesk | Alta | ✅ feito | fora do public + rota controlada (auth/tenant/posse); suíte 718/718 |
| E4 | Migrar ServiceDesk → `src/ServiceDesk/` | Alta | 🟡 em andamento | E4.1 feito; E4.2 liberado (schema decidido) |
| E5 | Migrar Agenda → `src/Agenda/` | Alta | ⬜ pendente | ~2.374 linhas, sem testes |
| E6 | Migrar Ponto → `src/Ponto/` | Alta (risco ALTO) | ✅ feito | P2.3 Fase 1 (isolamento) + Fase 2a (move estrutural, escopo "move puro"); suíte 784/784. Follow-ups: 2b/2c/strict_types |

Status: ⬜ pendente · 🟡 em andamento · ✅ feito · ⏭️ pulado

---

## 🔴🔴 ACHADO CRÍTICO (descoberto durante o follow-up de uploads) — Cliente sem isolamento de tenant

A entidade `App\Cliente\Entity\Cliente` (e subclasses PF/PJ) **não possui campo/relação
`tenant`** (só `criadoPor`). Não há Doctrine SQL filter global de tenant no projeto. O
`ClienteController::index()` lista via `ClienteRepository::findAll()`/`findByFilters()`, e
`findByFilters()` **não filtra por tenant**. Resultado: **qualquer usuário com
`modules.clientes.view` enxerga a base de clientes de TODOS os escritórios** (nome, CPF/CNPJ,
endereço, telefone, documentos). Vazamento cross-tenant sistêmico da base de clientes.

- Severidade: **CRÍTICA** (dados pessoais/sensíveis de clientes entre escritórios).
- Relação: é a causa-raiz do IDOR de `cliente_documento_download` e maior que ele.
- **NÃO é hotfix:** corrigir exige adicionar `tenant` a `Cliente` + migration + backfill
  (provável via `criadoPor`→tenant) + filtrar TODAS as queries de cliente + testes amplos +
  decisão de produção. Aguarda planejamento e decisão do responsável.
- **Auditoria completa concluída** → `docs/specs/auditoria-multitenant.md`. O problema é
  SISTÊMICO, não só do Cliente:
  - 🔴 **P0 catastrófico** (entidade sem tenant + listagem vaza tudo): **Cliente, Processo,
    Agenda** — vazam base de clientes, carteira de processos e calendários inteiros.
  - 🟠 **P1** (tenant existe, mas IDOR/queries parciais): Pasta, Expediente.
  - 🟡 **P2** (frágil/IDOR mitigado): Tarefa, Ponto, Profile.
  - ✅ **OK**: Kanban (isolado); ServiceDesk (corrigido no H1 desta sessão).
  - Causa-raiz: entidades sem coluna `tenant` + sem Doctrine SQL filter + ParamConverter por
    id sem validar tenant + `canAccessResource` não valida tenant do recurso (F1).
  - Remediação é um programa de risco ALTO (modelo de dados, migrations, backfill, testes) —
    aguarda decisão do dono sobre ordem e eventual mitigação imediata em produção.

## Remediação sistêmica (em andamento)
Decisões do dono: **fix-forward**, **abordagem sistêmica primeiro**, recomendações aprovadas
(coluna tenant em tudo + filtro global + guard por-id como defesa em profundidade). Design:
`docs/specs/isolamento-tenant-sistemico.md`.

- **S1 ✅ Infra do filtro de tenant (validada):** `App\Shared\Contract\TenantAware`,
  `App\Shared\Doctrine\Filter\TenantFilter`, `App\Shared\EventListener\TenantFilterListener`
  (liga por request, prioridade 5), registro em `doctrine.yaml` (`enabled: false`). `Chamado`
  marcado como cobaia. Teste `tests/Shared/Functional/TenantFilterTest`:
  **o filtro cobre findBy E find() por id** (IDOR fecha automático). Suíte **722/722**.
- **S2 ✅ P0 Cliente (entregue):** `Cliente` (base JOINED) e `ClienteDocumento` → `TenantAware`
  + coluna `tenant` NOT NULL. Migration `Version20260625183049` (add nullable → backfill via
  `criado_por_id`→user_tenant ativo → fallback p/ tenant único → NOT NULL + FK/índice; doc herda
  do cliente pai). Aplicada no dev: 8 órfãos → tenant 4, 0 órfãos; `schema:validate` OK dev+teste.
  `setTenant` nos 4 sites de escrita (ClienteController newPF/newPJ/upload, PastaController,
  AppFixtures). Testes cross-tenant (repo + HTTP, PF/PJ/documento) — suíte **731/731**. Revisão
  adversarial: aprovada; achados "findOneBy/UniqueEntity vazam" refutados por prova empírica
  (são filtrados). Spec: `docs/specs/cliente-isolamento-tenant.md` (inclui follow-ups: unique
  global de cpf/cnpj vs por-tenant, guard por-id deferido). **Deploy prod:** rodar a migration;
  conferir antes `SELECT COUNT(*) FROM tenant` e órfãos pós-backfill (se multi-tenant com órfão,
  a migration aborta de propósito).
- **S3 ✅ P0 Processo (entregue):** `Processo` (raiz) + `DocumentoProcesso`/`MovimentacaoProcesso`/
  `ParteProcesso` → `TenantAware` + coluna `tenant` NOT NULL. Migration `Version20260625192651`
  (processo: backfill autor → fallback tenant único → NOT NULL + FK/índice; filhas herdam do pai;
  **unique de `numero_processo`: GLOBAL → composto `(tenant_id, numero_processo)`**). Aplicada no
  dev: 3 processos → tenant 4, filhas 13/13/6 herdaram, 0 órfãos. 8 write-sites com `setTenant`
  (ProcessoController new+sync, PastaController vincular+fill, DatajudProcessoMapper c/ guard,
  Command CLI via processoBase, AppFixtures). Testes: filtro + **4 dropdowns de metadados** +
  IDOR de Processo e de cada filha + número único por-tenant + HTTP show/edit/delete 404. Suíte
  **752/752**. Spec: `docs/specs/processo-isolamento-tenant.md`. Revisão adversarial aprovada
  (follow-ups: down() pós-colisão, CLI cross-tenant, api/search sem permissão). **Deploy prod:**
  rodar a migration; conferir antes `SELECT COUNT(*) FROM tenant` e órfãos pós-backfill natural.
- **S4 ✅ P0 Agenda (entregue):** `Evento` + `LegendaCor` → `TenantAware`. Migration
  `Version20260625200952` (evento: backfill via `criador` + fallback tenant único; `legenda_cor`:
  config por-tenant, fallback tenant único — vazia no dev). Aplicada: 6 eventos → tenant 4, 0
  órfãos. Além do filtro: **dropdown de participantes** escopado por tenant (3 vetores + modal,
  via `UserRepository::findColaboradoresAtivosPorTenant`/`findPorIdsETenant`), **legendas**
  edição/remoção só do tenant (sem `find($id)` cru), **CSRF** em `atualizar-datas`, **permissão**
  em `agenda_legendas`. Testes: calendário (incl. ramo `visibilidade=todos`), IDOR de Evento e
  LegendaCor, salvarLegendas não apaga de outro tenant, participante de outro tenant rejeitado.
  Suíte **760/760**. Spec: `docs/specs/agenda-isolamento-tenant.md`. Revisão adversarial aprovada.
  ⚠️ Follow-up achado: `DemitirFuncionarioUseCase` faz SQL nativo cross-tenant em
  `evento_participante` (frente própria).

- **P1 ✅ Pasta + Expediente (entregue):** 7 entidades de Pasta + `Marcador` → `TenantAware`
  (já tinham coluna `tenant`; **zero migration de coluna, zero write-site**). Isso fecha pelo
  filtro os IDORs de peticionar/sincronizar-marcadores/mover-reordenar-documento/renomear-excluir-
  seção (`find()`/ParamConverter cross-tenant → 404). NUP da pasta: global → composto
  `(tenant_id, nup)` (migration `Version20260625203433`) + `findOneBy(['nup','tenant'])` escopado
  nos UseCases (corrige o importador CLI). Testes: find() IDOR de Pasta/Marcador, listagem isolada,
  NUP por-tenant, HTTP peticionar 404. Suíte **765/765**. Spec:
  `docs/specs/pasta-expediente-isolamento-tenant.md`. Revisão adversarial: reprovou o NUP/CLI →
  corrigido. Kanban fora de escopo (já isolado por board).

- **P2.1 ✅ Tarefa (entregue):** `Tarefa` + `TarefaMensagem` → `TenantAware` + coluna `tenant`
  NOT NULL. Migration `Version20260625212332` (tarefa: backfill via `pasta.tenant`, pasta_id NOT
  NULL → 0 órfãos + fallback tenant único; tarefa_mensagem: herda via `tarefa`). **Aplicada no dev
  via `migrations:execute --up` (NÃO `migrate` — há 2 migrations antigas de Ponto não aplicadas no
  ledger: `Version20260401000000`/`Version20260408180237`)** + teste sincronizado via `schema:update`.
  Achado da revisão: `TarefaMensagem` é carregada por id direto (rotas editar/visualizar-anexo da
  mensagem), então ganhou coluna PRÓPRIA (não só herança) — senão o IDOR só falharia como 500.
  `setTenant` em `criarParaPasta` + `mensagem`; `TarefaFactory` deriva tenant da pasta via
  `afterInstantiate`; 6 testes legados ajustados (setTenant). Testes: `findByResponsavel`/
  `findByProcesso` isolados, IDOR de Tarefa e TarefaMensagem (find→null), HTTP 404 cross-tenant em
  show/editarMensagem/excluir/prazo (antes davam 403 por cache de identity map — agora 404 reflete
  produção). Suíte **771/771**. Spec: `docs/specs/tarefa-isolamento-tenant.md`. Revisão adversarial
  (3 lentes, Opus): aprovada, sem correção de código exigida. **Deploy prod:** rodar a migration
  ISOLADA (execute --up); conferir antes que a cadeia Cliente/Processo/Pasta já esteja aplicada
  (backfill lê `pasta.tenant_id`) e `SELECT COUNT(*) FROM tenant` + órfãos.

- **P2.2 ✅ Profile (entregue):** **sem migration / sem mudança de código de produção.** `UserProfile`
  é 1:1 com `User` (estrutural/compartilhado) → coluna `tenant` seria modelagem errada; o `TenantFilter`
  não cobre Profile. Verificação read-only: as rotas admin de perfil (`salvarDadosPessoais`,
  `editUserRole`) já rodam os 2 guards (user-alvo ∈ tenant + admin controla o tenant) ANTES de tocar o
  perfil → **já isolado**, sem IDOR explorável. O gap era **teste**. Entregue: `PerfilAdminIsolamentoControllerTest`
  (6 casos: 403/404 nos dois ramos de cada rota, não-criação e não-mutação de perfil; admin `isSystem`
  como controle). Spec: `docs/specs/profile-isolamento-tenant.md`. Revisão adversarial (Opus): "já isolado"
  confirmado; achados de completude endereçados (incluída a via `PontoController::exportarFolha→getProfile`,
  guardada). Suíte **777/777**. Follow-up: `editUserName`/`demitir` (gerência de User, não tocam perfil)
  usam os mesmos guards mas sem teste cross-tenant — hardening separado.

- **P2.3 🟡 Ponto — Fase 1 (isolamento) ENTREGUE; Fase 2 (estrutural) pendente.** Decisões do dono:
  modelo **POR VÍNCULO** (coluna tenant); escopo isolamento + migração estrutural, **isolamento primeiro**.
  `RegistroPonto` e `JustificativaPonto` → `TenantAware` + coluna `tenant` NOT NULL; `JornadaTenant`/`Feriado`
  só marcados `TenantAware`. `JornadaColaborador`/blocos FORA (não vazam — guardados/herdados). Migration
  `Version20260626103111` (backfill via `user_tenant` ativo; aborta se ambíguo; fallback tenant único).
  **Aplicada no dev ISOLADA via `execute --up`** (ledger sem as 2 migrations antigas do Ponto). `setTenant`
  nos 6 write-sites; SQL nativo `findCompetencias` agora recebe `Tenant`; bulk `desvincularSede` escopado.
  Testes: `PontoIsolamentoControllerTest` + `PontoIsolamentoRepositoryTest` (controle positivo + IDOR via
  usuário compartilhado + find()). Suíte **784/784**. Revisão adversarial: aprovada, único ALTO = frestinha
  super-admin (adiada por decisão do dono). Spec: `docs/specs/ponto-isolamento-tenant.md`.
  **Deploy prod:** conferir cadeia anterior + `SELECT COUNT(*) FROM tenant`; conferir que nenhum
  registro/justificativa tem user com ≠1 tenant ativo (senão a migration aborta); rodar a migration ISOLADA.

- **P2.3 Ponto — Fase 2a (migração estrutural) ENTREGUE (não commitada).** Escopo 2a "move puro"
  (decidido pelo dono): 30 arquivos (7 entidades, 7 repos, 6 services, 4 forms, 4 controllers próprios)
  movidos para `src/Ponto/`; namespaces `App\Ponto\*`; bloco `AppPonto` no `doctrine.yaml`; bloco
  `ponto_controllers` no `routes/attributes.yaml`. `editUserRole`, ações de ponto no `TenantController`
  e `Sede` FICARAM (importam do novo namespace). Verificação: `schema:validate`/`lint:container`/`lint:twig`
  OK, 20 rotas, **suíte 784/784**. Falta smoke manual. Follow-ups: 2b (extrair ponto do TenantController),
  2c (UseCases), `strict_types`, remover `GeofencingService`+`calcularCargaDiaria` mortos.

## 🎉 P0 COMPLETO + P1 + P2.1 (Tarefa) + P2.2 (Profile) + P2.3 Ponto Fase 1
Os três P0 (Cliente, Processo, Agenda), o P1 (Pasta/Expediente), o P2.1 (Tarefa), o P2.2 (Profile) **e o
P2.3 Ponto Fase 1 (isolamento)** estão isolados por tenant. Falta a **Fase 2 do Ponto** (migração estrutural
E6 → `src/Ponto/`). Itens sistêmicos pendentes (follow-ups registrados): **frestinha super-admin no Ponto
(IDOR residual só p/ super-admin sem tenant na sessão — DECISÃO ADIADA p/ o fim, junto da definição de
poderes do super-admin; fechamento = guard por-id nas rotas admin + teste do vetor)**;
~~SQL nativo cross-tenant + bulk DQL (`DemitirFuncionarioUseCase`)~~ **✅ fechado em C1**;
~~`updatePastEventsStatus` (bulk)~~ **✅ fechado em C2 (era morto, removido)**;
unique global de `cpf/cnpj` (Cliente, C3), CSRF em endpoints JSON (C4),
uploads fora do public (C5), conferência de NUP duplicado em prod.

## Frente C — segurança residual (em andamento)
Plano: `docs/specs/followups-seguranca-residual.md` (C1→C5; C6 super-admin bloqueado por decisão de produto).
**Commits:** C1 `cd95d52` · C2 `f344653` · C3 `787c97b` · C4a `79798d4` (todos COMMITADOS, árvore limpa).

- **C1 ✅ `DemitirFuncionarioUseCase` cross-tenant (entregue).** As 4 operações de
  `removerResponsabilidades`/`transferirResponsabilidades` filtravam só por `user`, sem tenant — bulk DQL
  (UPDATE Pasta/Chamado) e SQL nativo (`tarefa_responsaveis`/`evento_participante`) **escapam do TenantFilter**,
  então demitir um funcionário multi-tenant de UM escritório apagava/transferia as responsabilidades dele em
  TODOS. Fix: escopar cada operação por `$input->tenant` (DQL `AND p.tenant/c.tenant`; join tables via
  `... IN (SELECT id FROM tarefa/evento WHERE tenant_id = :tid)`); `NOT IN` anti-duplicata mantido global de
  propósito. **Sem migration / sem mudança no controller** (já valida CSRF + vínculo + permissão). Risco MÉDIO.
  Teste `DemitirFuncionarioIsolamentoTest` (KernelTestCase, filtro DESLIGADO = pior caso): remover, transferir e
  substituto-já-responsável (caminho `NOT IN`) — B intacto após demitir de A. **Mutation test confirmado** (fix
  neutralizado → RED; `NOT IN` removido → colisão de PK). Suíte **792/792**. Spec:
  `docs/specs/demitir-funcionario-cross-tenant.md`. Revisão adversarial: aprovado com ressalvas, todas
  endereçadas (mutation test registrado na spec; caso do `NOT IN` adicionado; substituto-sem-vínculo-em-A
  aceito como está — guarda mora no controller por design).
- **C2 ✅ `updatePastEventsStatus` (entregue).** Bulk DQL `UPDATE Evento SET status` sem escopo de
  tenant em `EventoRepository`. Investigação read-only exaustiva: **zero chamadores** em todo o repo
  (grep), **nenhum em commit algum** da história (pickaxe `git log --all -S` em `AgendaController` veio
  vazio), sem cron/scheduler/messenger, sem invocação dinâmica, nenhum teste o referencia — nasceu com
  a feature de calendário (`7295020`) e nunca foi ligado a nada. **Removido** (edição cirúrgica). Risco
  BAIXO (inerte por não ter chamador). Suíte **792/792** (nada quebrou). Sem spec dedicada (trivial).
- **C3 ✅ cpf/cnpj de Cliente únicos por escritório (entregue).** Unique de cpf/cnpj era GLOBAL → o 1º
  escritório a cadastrar trancava o documento no sistema (INSERT cross-tenant → 500). `Cliente` é JOINED
  (tenant_id na base `cliente`; cpf/cnpj em `cliente_pf`/`cliente_pj`) → composto `(tenant_id, cpf)` no
  banco é inviável sem denormalizar. **Decisão do dono = Opção B:** remover unique global do banco
  (migration `Version20260626150000`, só DROP de 2 índices, sem dados) + unicidade POR TENANT na
  aplicação (`#[UniqueEntity(['cpf','tenant'])]`/`['cnpj','tenant']` + `setTenant` antes da validação em
  `ClienteController::newPF/newPJ`). **2º caminho** `PastaController::novoCliente` (findOneBy manual)
  também escopado por tenant explícito. Migration **aprovada pelo dono** e aplicada no dev ISOLADA +
  `schema:update --env=test`. Testes: `ClienteUnicidadePorTenantTest` (validação+banco, filtro desligado)
  + `ClienteUnicidadeViaPastaControllerTest` (HTTP). **Mutação confirmada** (validação global → cross-tenant
  viola; unique global recriado → 4 testes cross-tenant quebram). Suíte **801/801**. Risco MÉDIO. Spec:
  `docs/specs/cliente-cpf-cnpj-por-tenant.md`. **Deploy prod:** conferir antes que não haja cpf/cnpj
  duplicado DENTRO do mesmo tenant (a remoção do unique não cria trava nova que pegue isso).
- **C4a ✅ CSRF em endpoints AJAX de jornada/ponto (ALTO) (entregue).** Enumeração via workflow
  read-only (19 controllers): 20 endpoints AJAX mutantes sem CSRF (o resto já protegido por form CSRF
  ou `isCsrfTokenValid`). Mecanismo definitivo (decisão do dono "o mais profissional"): **token CSRF
  único `'ajax'` no header `X-CSRF-Token`** — `<meta>` + interceptador JS global (fetch + XHR) no
  `base.html.twig` + trait `App\Shared\Trait\ValidaCsrfAjaxTrait` no backend. C4a aplicou a validação
  nos endpoints **ALTO** (ponto eletrônico): `app_jornada_tenant_save`, `app_jornada_colaborador_save`,
  `app_jornada_colaborador_delete` **e `ponto_batida`** (este foi PERDIDO pela enumeração Haiku e
  pego pela revisão adversarial — lição: não confiar no resumo do subagente). CSRF checado **após** os
  guards de autz/tenant (404/403 de tenant têm prioridade e ficam testáveis). Testes
  `JornadaCsrfControllerTest` + `BatidaCsrfControllerTest` (sem token→403; com token→sucesso/422);
  mutação confirmada. Suíte **809/809**. Spec `docs/specs/csrf-ajax-endpoints.md`. Revisão adversarial:
  aprovado com ressalvas → todas endereçadas (batida, local do trait, teste do delete). **Smoke do
  interceptador no browser: PENDENTE** (Chrome ausente no ambiente) — passo manual do dono.
- **C4b ✅ CSRF AJAX do Kanban (BAIXO) ENTREGUE — NÃO COMMITADO.** Enumeração re-verificada por leitura dos 6
  controllers + `debug:router` (26 rotas), não confiando no workflow. **12 endpoints** mutantes JSON sem CSRF
  (não 14): card criar/atualizar/mover, checklist criar / item criar / item toggle, anexo upload, marcador
  criar/editar/toggle, comentário criar/editar — em 5 controllers (`Card/Checklist/Anexo/Marcador/Comentario`).
  Ligaram `App\Shared\Trait\ValidaCsrfAjaxTrait` + `validarCsrfAjax($request)` após o guard de tenant/acesso.
  `toggleItem` ganhou `Request $request`. **Já protegidos, NÃO tocados:** os 6 `*_excluir` (token por-intenção no
  corpo) e `kanban_board_criar`/`editar` (Symfony Form `KanbanBoardType`/`KanbanBoardEditType`, CSRF do form) —
  por isso "14" virou 12. `KanbanBoardController` intocado. Teste `KanbanCsrfControllerTest` (13: par sem/com token
  por endpoint + board form 422). Follow-up aberto: IDOR de `kanban_card_mover` (não valida acesso ao board).
- **C4c ✅ CSRF AJAX de Agenda/Notificação (BAIXO/MÉDIO) ENTREGUE — NÃO COMMITADO.** Legado (`src/Controller/`),
  edição cirúrgica. **3 endpoints:** `agenda_criar_ajax`, `agenda_legendas_salvar` (CSRF após `canAccessModule`),
  `notificacao_marcar_lida` (ganhou `Request $request`; CSRF após o guard de posse). **Varredura confirmou** que
  `agenda_excluir/cancelar/atualizar-datas`, `agenda_novo/editar` (form), `notificacao_marcar_todas_lidas` e
  `notificacao_excluir` JÁ têm CSRF — não tocados. Testes `AgendaCsrfControllerTest` (2) + `NotificacaoCsrfControllerTest`
  (1). 2 testes legados de isolamento da Agenda passaram a mandar o token CSRF. **Suíte 825/825; mutação confirmada
  (15/16 vermelhos ao neutralizar o trait).** Spec atualizada: `docs/specs/csrf-ajax-endpoints.md`.
- **C5.1 ✅ Defesa-em-profundidade dos uploads (ALTO) ENTREGUE — NÃO COMMITADO.** Investigação read-only
  (workflow, 5 módulos) + verificação empírica: arquivos com PII em `public/uploads/*` eram baixáveis SEM auth
  por URL direta — e o nginx de prod (`try_files $uri /index.php`) servia o arquivo **se existisse** (só caía no
  PHP quando ausente), então a exposição era real em prod, não só dev. **Decisão do dono:** defesa nginx + faxina
  primeiro; endurecer `nginx.prod.conf` no escopo. Entregue: `nginx.conf`+`nginx.prod.conf` trocam o bloco
  `location ^~ /uploads/` para `rewrite ^ /index.php last` (nunca serve estático → front controller; firewall
  `^/ ROLE_USER` exige login) + `PecaImagemController` (rota autenticada `GET /uploads/pastas/{nome}`, só
  imagens, p/ as imagens do editor de peças embutidas no HTML). **Provado por curl** (justificativa/pasta/perfil
  `.pdf`/`.jpg`: 200→404/302). Teste `PecaImagemControllerTest` (anônimo→302; logado+imagem→200; inexistente→404;
  não-imagem→404). Suíte **829/829**. Spec `docs/specs/uploads-fora-do-public.md`. Revisão adversarial: APROVADO
  COM RESSALVAS (residual de tenant das imagens = aceite consciente, fecha no C5.2; teste de não-imagem adicionado).
  **Residual conhecido:** a rota de imagem é auth-only (não isola por tenant) — fecha no C5.2. **Deploy:** recriar
  o container nginx (não só reload — bind-mount de arquivo único) + faxina `rm` dos leftovers órfãos
  (justificativas/clientes) + smoke do peticionamento logado. Arquivos a commitar: `nginx.conf`, `nginx.prod.conf`,
  `app/src/Pasta/Controller/PecaImagemController.php`, `app/tests/Pasta/Functional/PecaImagemControllerTest.php`,
  `docs/specs/uploads-fora-do-public.md`, `docs/specs/followups-seguranca-residual.md`, `docs/specs/PROGRESSO-PENDENCIAS.md`.
- **Próximo:** commit C5.1 → **C5.2 (pastas→var, fecha o residual)** / C5.3 (perfil→var, MÉDIO) / C5.4 (tarefas→var,
  refactor legado). C6 frestinha super-admin nos outros domínios BLOQUEADO (decisão de produto).

## Auditoria pós-remediação (jun/2026) — correção dos achados
Spec desta frente: `docs/specs/auditoria-pos-remediacao-multitenant.md` (29 achados rankeados, file:line + fix).
Ordem ALTO → MÉDIO → BAIXO; por achado: confirmar na fonte → testes cross-tenant → fix → mutação → /review.

➡️ **B5 COMPLETO (4 frentes); M5 ENTREGUE (não commitado).** RETOMAR nos demais achados da auditoria (M6–M8, B-series) — ver §"Demais (MÉDIO/BAIXO)". **Feitos e COMMITADOS:**
A1 (`20dfcb6`), A2 (`8e9f0a6`), M3 (`2eddbae`), M3.1 (`c68654f`), M3.2 (`5be4786`), M1 (`07da912`),
**M4** (`bd121c0`, Notificacao TenantAware + B2), **M2** (`06576d6`, AccessRequest TenantAware),
**B5 spec** (`04c5124`), **B5 f1** (`7fcb827`, listener da trava), **B5 f2** (`87f2fb5`, rename 5 rotas `{id}`→`{tenantId}`),
**B5 f3** (`911eeaa`, remendo `escoparFiltroNoTenant` em listUsers/downloadAnexo).
**B5 frente 4 / B3 ✅ ENTREGUE+REVISADA (APROVADA c/ ressalvas BAIXAS), NÃO commitada** — `ResourceAccess` TenantAware + migration
`Version20260630120000` **APLICADA no dev** (1 tenant/0 RAs → trivial; `schema:validate` OK). Fecha o IDOR/write cross-tenant residual da f3.
Suíte **893/893** (890 + 3). Working tree limpo (`.playwright-mcp/` no `.gitignore`; `sincronizacao-drive-bidirecional.md` se aparecer = WIP do DONO, NÃO commitar).

### 🎯 B5 — escopo do super-admin (DESTRAVADO via brainstorming) — em andamento
Spec: `docs/specs/super-admin-escopo-tenant.md`. Plano frente 1: `docs/superpowers/plans/2026-06-29-b5-frente1-listener-trava-tenant.md`.
**Decisão (dono):** super-admin = visão global SÓ nas rotas de plataforma (sem tenant na URL); nas rotas de
escritório fica PRESO ao `{tenantId}` da URL. Mecanismo "os dois": trava automática (listener) + remendo explícito.
- **Frente 1 ✅ COMMITADA (`7fcb827`):** `App\Shared\EventListener\TenantUrlScopeListener` (prio 4) pina o filtro no
  `{tenantId}`. Suíte 880/880.
- **Frente 2 ✅ ENTREGUE+REVISADA (APROVADA), NÃO commitada — padronizadas as 5 rotas legadas `{id}`→`{tenantId}` (a trava
  markerless agora as cobre).** As 5 rotas (`app_tenant_show`/`edit`/`delete`/`users`/`sedes`) viraram `{tenantId}`; as 5 actions
  ganharam `#[MapEntity(id: 'tenantId')]` (eram param-converter implícito por `{id}`); import `MapEntity` add. **23 callers** atualizados
  (`'id'`→`'tenantId'`, valor inalterado): 9 redirects no `TenantController` (`_fragment` preservado em tab-cargos/tab-lotacoes) + 14
  `path()` em 10 templates (`_sidebar` 3, `feriado/index`, `tenant/_delete_form`, `tenant/edit`, `tenant/edit_user_role`,
  `tenant/index`, `tenant/sedes` 3, `tenant/show`, `tenant/users`, `tenant_role/index`). Sidebar `currentRoute == 'app_tenant_xxx'`
  NÃO tocada (compara nome de rota). Teste `TenantRotasTenantIdControllerTest` (7: 5 de gerador RED→GREEN + render-net das 5 rotas+index
  + 404 do MapEntity + POST delete). **Verificação:** `debug:router` mostra `{tenantId}`, `lint:twig` OK (10), `php -l` OK, suíte
  **887/887**, mutação da rede confirmada (caller `'id'` → render-net RED), smoke browser das 8 telas + sidebar (URLs `{tenantId}` ok).
  Revisão adversarial (`feature-review-agent`, ALTO): **APROVADO**, 0 achado ALTO/MÉDIO; 3 observações BAIXAS pré-existentes (abaixo).
  **Observações (não imputáveis a esta frente — dívida pré-existente, follow-ups):** (a) `tenant/_delete_form.html.twig` é **órfão**
  (incluído em nenhum template → `app_tenant_delete` sem caller de UI; só o teste POST a exercita) — decisão pendente do dono: remover
  ou religar a uma futura gestão de tenants do super-admin; (b) `TenantController.php` sem `declare(strict_types=1)` (não corrigido de
  propósito = seria refactor oportunista numa frente de rename); (c) 404 do MapEntity testado só em `/users` (cosmético, actions idênticas).
- **Frente 3 ✅ ENTREGUE+REVISADA (APROVADA), NÃO commitada — remendo explícito `escoparFiltroNoTenant` (defesa-em-profundidade).**
  Adicionado em `listUsers` e `downloadAnexoJustificativa` (após o guard, antes da leitura TenantAware por id: labels Cliente/Pasta/Processo
  e `JustificativaPonto`), ambos com `EntityManagerInterface` injetado. **`removeResourceAccess` NÃO recebeu o helper** (achado:
  `ResourceAccess` implementa só `Auditavel`, **não `TenantAware`** → `escoparFiltroNoTenant` seria no-op; isolamento real = frente 4);
  só ganhou uma NOTA. Teste `TenantEscopoRemendoControllerTest` (3): os 2 do remendo **desligam a trava** (`TenantUrlScopeListener` removido
  do dispatcher + `disableReboot`) p/ ISOLAR o remendo — provam que ele sozinho escopa (super-admin: download de B via URL de A → 404 e
  positivo via B → 200; NUP de pasta de B não vaza na lista de A). 3º teste trava o guard atual do `removeResourceAccess`. Mutação 2× (neutralizar
  os 2 remendos → ambos RED; revert → GREEN). Achado de teste: `Pasta` normaliza o NUP p/ MAIÚSCULAS → agulha usa `$pasta->getNup()`.
  Suíte **890/890**. Revisão `feature-review-agent` (ALTO): enumerou as **20 rotas `{tenantId}`** e confirmou **zero gap real** além do
  `removeResourceAccess` (deferido) e `editSede`/`deleteSede` (Sede não-TenantAware, já com guard `$sede->getTenant()`). Ressalva única
  (RED não re-executável read-only) FECHADA pela mutação. Achado pré-existente fora do diff: ordem dos guards em `downloadAnexoJustificativa`
  (não vaza). **Arquivos a commitar:** `app/src/Controller/TenantController.php`, `app/tests/Tenant/Functional/TenantEscopoRemendoControllerTest.php`, docs.
- **Frente 4 / B3 ✅ ENTREGUE+REVISADA (APROVADA c/ ressalvas BAIXAS), NÃO commitada — `ResourceAccess` TenantAware.** Spec dedicada:
  `docs/specs/resource-access-isolamento-tenant.md`. `ResourceAccess implements TenantAware` + coluna `tenant` NOT NULL (derivada do recurso
  dono). Write-site único `approve` ganhou `setTenant($tenant)`; `removeResourceAccess` só NOTA (a trava escopa o `find($raId)` → fecha o
  IDOR/write cross-tenant residual da f3, inclusive p/ usuário multi-tenant); read-sites (`findForUserAndResource`/`findByUsers`/`find`) cobertos
  pelo filtro sem mudança de repo; `canAccessResource` escopado sem quebrar acesso legítimo (super-admin/`isSystem` curto-circuitam). **Unique
  `(user,type,id)` MANTIDA** (resource_id é PK global → tenant funcionalmente determinado; trocar enfraqueceria). Migration `Version20260630120000`
  (backfill por recurso cliente/pasta/processo→fallback tenant único→NOT NULL aborta; FK `FK_CE95C1AE9033212A`/IDX) **APLICADA no dev** isolada +
  schema teste sincronizado. Testes `ResourceAccessIsolamentoControllerTest` (IDOR fechado / lookup escopado / approve grava tenant) + ajuste no
  teste da f3 (helper recebe `Tenant`; desliga filtro antes do `find`). Mutação 2× (remover TenantAware → testes 1+2 RED). Suíte **893/893**. Smoke
  OK (users/edit-role/clientes 200). Revisão `feature-review-agent`: APROVADO c/ ressalvas BAIXAS (runbook de prod ENDEREÇADO; gap M2 fica inócuo,
  não fechado = follow-up M2; backup pré-deploy no runbook). Runbook `DEPLOY-PROD-multitenant.md` atualizado (lote auditoria M4/M2/B5-f4, #8–#10).

- **A1 ✅ Isolamento do diretório de usuários por tenant (ServiceDesk + Expediente) — ALTO.**
  `User` não é TenantAware → o `TenantFilter` não toca queries de User. Os dropdowns de técnico/
  responsável usavam `findBy(['isActive'=>true])` (sem tenant), vazando TODOS os usuários de TODOS
  os escritórios; e `atribuir` resolvia `responsavel_id` com `find()` cru (write + notificação
  cross-tenant). Fix: novo `UserRepository::findColaboradorAtivoPorIdETenant`; dropdowns →
  `findColaboradoresAtivosPorTenant($tenant)` em `ServiceDeskController:102/:230` (guard `null ? []`
  p/ super-admin sem sessão) e `ExpedienteController:203/:275` (tenant vem de `assertAccess`, não-nulo);
  `atribuir` valida o responsável contra `$chamado->getTenant()` (autoritativo) → 404 se não for
  colaborador ativo do escritório dono. **6º site achado na revisão:** `ChamadoType` (EntityType
  `responsavel`, hoje dead code por `is_admin:false`) escopado por tenant (opção `tenant`, fail-closed).
  7 testes (`ServiceDeskUsuariosIsolamentoControllerTest` 5 + `ExpedienteUsuariosIsolamentoControllerTest`
  2); mutação confirmada 2×. Suíte **836/836**. Revisão adversarial + re-revisão do delta: aprovadas.
  **Deferidos (sem leak):** `find()` :82 do filtro do dashboard; comentário stale em `NotificacaoService`.
  **Fora desta frente (achados separados):** M1 (CSRF atribuir/status), B5 (frestinha super-admin).
- **A2 ✅ CSRF no lançamento/edição manual de ponto (ALTO).** `RegistroPontoManualType` tinha
  `csrf_protection=>false` e `pontoAdd`/`pontoEdit` (`TenantController`) gravavam batidas sem CSRF de
  reserva (as irmãs `delete_ponto_`/`justificativa_*` já checavam). Fix (decisão do dono): token
  por-intenção STATEFUL explícito no controller — `isCsrfTokenValid('ponto_manual_add')` em `pontoAdd` e
  `isCsrfTokenValid('ponto_manual_edit')` só no POST de `pontoEdit`, após os guards de autz/tenant, 403 na
  falha (espelha `delete_ponto_<id>`). `_token` top-level renderizado no modal "Adicionar Batida"
  (`edit_user_role.html.twig`) e no `ponto_edit.html.twig`. `csrf_protection=>false` MANTIDO de propósito
  (com comentário) p/ não duplicar com o `submit` stateless. 4 testes (`PontoManualCsrfControllerTest`:
  add/edit sem token → 403 sem gravar; com token → 302 grava) — primeira cobertura functional desses POSTs;
  mutação 2× (neutralizar → sem-token vira 302 = RED). Suíte **840/840**. Revisão adversarial: APROVADA
  (varredura confirmou os 4 write-sites de `RegistroPonto` cobertos; nada pendurado).
- **M3 ✅ BAC horizontal intra-tenant no Kanban (membership nos sub-recursos).** Checklist/item/anexo/
  marcador/comentário carregados por id checavam só o TENANT do board, não a MEMBERSHIP (`temAcesso` =
  criador OU participante) → no mesmo escritório, um não-participante de board PRIVADO acessava/editava/
  excluía o sub-recurso só pelo id (anexo `servir` baixa o arquivo). Fix: somar `!...->temAcesso($user)`
  à guarda de **10 actions** (4 controllers): `KanbanChecklistController` (excluir/adicionarItem/
  toggleItem/excluirItem), `KanbanAnexoController` (servir/excluir), `KanbanMarcadorController` (editar/
  excluir), `KanbanComentarioController` (editar/excluir). Inclui os **2 de comentário** que a auditoria
  não listou (decisão do dono = uniformizar): **mudança de comportamento intencional** — admin do módulo
  não-participante deixa de moderar (editar/excluir) comentário em board que não participa (passa a 404).
  Enumeração via workflow (6 controllers, Opus) + reverificação no código literal. 10 testes
  (`KanbanMembershipControllerTest`: estranho isSystem não-participante → 404; dono criador → sucesso);
  mutação 10× (neutralizar → estranho vira 200 = RED). Suíte **850/850**. Revisão adversarial: aprovada.
  **Follow-ups apontados (fora do M3):** (a) `kanban_card_mover` — **✅ fechado em M3.2**; (b) `kanban_board_excluir` — **✅ fechado em M3.1**. Kanban agora uniforme: `temAcesso` no controller (404) em TODA action sobre board/sub-recurso por id.
- **M3.1 ✅ `kanban_board_excluir` exige participação (alinhamento do M3).** O controller só checava null →
  `ExcluirBoardUseCase` (criador OU `canAdminister('kanban')`) deixava um admin do módulo NÃO-participante
  excluir um board privado que ele nem podia ver (`view`/`editar` já usam `temAcesso`). Fix: somar
  `!$board->temAcesso($user)` à guarda do `excluir` (404), parelho a view/editar e ao comentário do M3.
  Teste `KanbanMembershipControllerTest::testBoardExcluir` (estranho admin não-participante → 404; dono
  criador → exclui); mutação confirmada (sem a guarda, o estranho exclui o board). Suíte **851/851**.
- **M3.2 ✅ `kanban_card_mover` devolve 404 (não 403) p/ não-participante (alinhamento do M3).** O controller
  só checava null e delegava ao `MoverCardUseCase`, que dava `AccessDeniedException` (403) — divergência de
  enumeração vs as outras 9 actions de card (404). Sem leak (o não-participante já era barrado), mas o 403
  revelava existência do card por status code. Fix: somar `!$card->getBoard()->temAcesso($user)` à guarda do
  `mover` (404), antes do CSRF/UseCase (o UseCase mantém o `temAcesso` como defesa-em-profundidade). Teste
  `KanbanMembershipControllerTest::testCardMover` (estranho → 404; dono → move); mutação (neutralizar →
  estranho volta a 403 = RED). Suíte **852/852**.
- **M1 ✅ CSRF em `ServiceDeskController::atribuir`/`status` (form HTML cru).** As duas ações POST de form
  HTML cru (não-Symfony-Form) liam o request sem CSRF (a C4 cobriu AJAX/JSON e forms Symfony; estas duas
  escaparam). Fix: token por-intenção stateful por-chamado — `isCsrfTokenValid('servicedesk_atribuir_<id>'/
  '..._status_<id>')` APÓS os guards tenant(404)/permissão(403), 403 na falha (idiom `delete_ponto_<id>`).
  `_token` nos **7 forms** de `show.html.twig` que disparam essas rotas (1 atribuir + 1 status dropdown +
  **5 de "Ações Rápidas"**). Testes: `ServiceDeskCsrfControllerTest` (atribuir/status sem token→403, com
  token→ok; + data-provider crawler cobrindo os **5 botões** de ação rápida renderizando o template real),
  ajustes em `ServiceDeskFluxoControllerTest` (3 POSTs de gestor) e `ServiceDeskUsuariosIsolamentoControllerTest`
  (2 atribuir do A1, que agora passam pelo CSRF antes da resolução). Mutação 2× + por-form. **Revisão
  adversarial REPROVOU a 1ª versão** (tokenizei só os 2 dropdowns e esqueci os 5 forms de Ações Rápidas →
  gestor levaria 403; suíte verde mascarava) → corrigido + crawler test que pega a classe da regressão →
  re-revisão APROVOU. Suíte **859/859**.
- **M4 ✅ `Notificacao` isolada por tenant — COMMITADO (`bd121c0`); migration `Version20260629120000` APLICADA no dev.**
  Spec dedicada: `docs/specs/notificacao-isolamento-tenant.md`. `Notificacao implements TenantAware` +
  coluna `tenant` NOT NULL; `setTenant` nos 2 métodos do `NotificacaoService` (`criar`/`criarNotificacao`,
  que ganharam param `Tenant`) cobre 100% das criações (zero `new Notificacao` fora do service); write-sites
  derivam o tenant da entidade (`$tarefa/$evento/$chamado/$justificativa->getTenant()`; Agenda 3 + ServiceDesk 4);
  bulk `marcarTodasComoLidas`/`excluirDoUsuario` escopados explicitamente (escapam o filtro) + `NotificacaoController`
  injeta `TenantContext` (fail-closed se sem tenant). **B2 junto:** `removerAntigas` removido (morto). Migration
  `Version20260629120000` (backfill tarefa→fallback tenant único→NOT NULL aborta; FK `FK_5ACD93869033212A`,
  índice `IDX_5ACD93869033212A`) ESCRITA, **schema test sincronizado**, mas **NÃO aplicada no dev** (dev=1 tenant/0
  notif → trivial). Testes `NotificacaoIsolamento{Repository,Controller}Test` (leitura/IDOR/dropdown/gestão/bulk
  cross-tenant) + 4 testes legados ajustados p/ `setTenant`. Mutação 2× (remover TenantAware; inverter escopo bulk).
  Suíte **869/869**. Revisão adversarial: APROVADA COM RESSALVAS (lacuna dropdown/gestão ENDEREÇADA; código morto
  `notificarTarefa*` e higiene de commit registrados). **PRÓXIMO:** aplicar migration no dev via `execute --up` após
  OK, depois commit (só os 14 arquivos da frente — NÃO o `sincronizacao-drive-bidirecional.md` nem `.playwright-mcp/`).
- **M4 (plano original, mantido para referência):**
  **Vazamento:** `Notificacao` (`src/Entity/Notificacao.php`) não tem `tenant`; todas as queries do sino
  (`NotificacaoRepository`) filtram só por `usuario` → usuário multi-tenant logado em B vê notif. geradas
  em A (título/URL de tarefa/chamado/evento/ponto). **Insight verificado:** TODA notif. nasce por
  `NotificacaoService::criar`/`criarNotificacao` (zero `new Notificacao` fora do service) → `setTenant`
  nesses 2 métodos cobre 100% das criações.
  - **DESIGN (padrão isolamento P0/P2):** `Notificacao implements TenantAware` + coluna `tenant` NOT NULL
    (o atributo PHP TEM de se chamar `tenant`). O `TenantFilter` auto-escopa TODAS as SELECTs (sino via
    `NotificacaoExtension`; `NotificacaoController::index/count/listaDropdown`) e o `find()` por id (fecha
    o IDOR do `marcarComoLida`). Só os bulk DQL escapam o filtro → escopo explícito.
  - **WRITE-SITES (passar `Tenant` aos 2 métodos do service + `setTenant`):** fontes do tenant —
    `notificarTarefa{Criada,Pendente,Concluida}`→`$tarefa->getTenant()`; `notificarJustificativaEnviada`/
    `notificarNovoChamado`→`$tenant` (já recebem); `notificarJustificativa{Aprovada,Rejeitada}`→
    `$justificativa->getTenant()`; `AgendaController:199/261/391`→`$evento->getTenant()`;
    `ServiceDeskController:500/511/525/550` (notificarNovaInteracao/Atribuicao/MudancaStatus)→
    `$chamado->getTenant()`. (Evento/Chamado/Tarefa/JustificativaPonto já são TenantAware.)
  - **BULK (escopo explícito por tenant da sessão):** `NotificacaoRepository::marcarTodasComoLidas` e
    `excluirDoUsuario` ganham `Tenant $tenant` + `andWhere('n.tenant=:tenant')`; idem no
    `NotificacaoService`; `NotificacaoController` injeta `TenantContext` e passa o tenant da sessão (sem
    isso, "marcar todas" em B zera as não-lidas de A). **Read-sites: SEM mudança** (filtro cobre).
  - **B2 (aprovado junto):** remover `NotificacaoRepository::removerAntigas` (bulk DELETE morto, sem
    chamador, sem tenant — footgun num TenantAware).
  - **MIGRATION (🔴 FREIO — aplicar SÓ no dev, ISOLADA, após confirmar com o dono):** `notificacao`
    +`tenant_id`: add nullable → backfill (1) `tarefa.tenant_id` p/ notif de tarefa; (2) fallback **tenant
    único** p/ o resto (prod=1 tenant → cobre tudo) → NOT NULL + FK + índice; **aborta** se sobrar null
    (multi-tenant não-derivável). Aplicar via `migrations:execute '<Version>' --up` (NUNCA `migrate` puro —
    2 migrations antigas de Ponto fora do ledger) + `schema:update --force --env=test`.
  - **TESTES cross-tenant:** usuário em A **e** B, 1 notif em cada; logado em B → sino/índice/contador só
    de B; `marcarTodasComoLidas` em B não toca A; IDOR de notif. de A logado em B → 404. Mutação: desligar
    filtro/escopo → vaza. **Depois:** suíte + `/review` + commit (código + migration + testes + PROGRESSO).
  - **Estado na interrupção:** dono APROVOU plano + estratégia da migration + remoção do `removerAntigas`.
    Faltou só confirmar se aplica a migration no dev direto ou mostra o SQL antes → próximo chat: escreve a
    migration, MOSTRA o SQL, aplica no dev após OK rápido. Investigação 100% feita (não re-investigar).
- **M5 ✅ Isolamento por tenant da imagem de peça (MÉDIO) — ENTREGUE+RE-REVISADA (APROVADA c/ ressalvas
  BAIXAS), NÃO commitada. Sem migration.** Spec `docs/specs/peca-imagem-isolamento-tenant.md`. O
  `PecaImagemController` (`GET /uploads/pastas/{nome}`) servia a imagem do editor a QUALQUER logado, sem
  tenant (resíduo do C5.1). Investigação (workflow read-only + reverificação): imagem é arquivo solto
  (`bin2hex(16)`) **plano** em `public/uploads/pastas`, sem entidade; o mesmo flat dir guarda HTMLs de peça
  e uploads de documento (procuração/RG/contrato — **inclusive PNG/JPEG**, `UploadPecaUseCase`), então o
  controller era caminho **paralelo não-protegido até imagens de documento**. **Decisão do dono: Opção A —
  subpasta por tenant, URL inalterada** (rejeitada a Opção B = entidade+migration+backfill). Fix: upload
  (`PeticionarController::uploadImagemEditor`) grava em `pastas/<tenantId>/` (fail-closed: tenant null →
  403); serve (`PecaImagemController`, agora injeta `TenantContext`) resolve só `pastas/<tenantSessão>/`
  (fail-closed null → 404; cross-tenant → 404; fecha também o paralelo p/ doc-images); export
  (`ExportarPecaTextoUseCase`) reescreve `.../uploads/pastas/` → subpasta do tenant do doc via
  `preg_replace_callback('#(?:\.{1,2}/)*/?uploads/pastas/#')` — trata URL absoluta E relativa do TinyMCE
  (o `str_replace` antigo **já quebrava** o caso relativo). Testes: `PecaImagemControllerTest` (owner 200 /
  cross-tenant 404 / flat-residue 404 / sem-tenant-super-admin 404 / inexistente / não-imagem) +
  `PeticionarControllerTest` (upload aterrissa em `<tenantId>/`, não no flat) + `ExportarPecaTextoUseCaseTest`
  (absoluto/relativo/multi-imagem/null via reflection). **Mutação 2× confirmada:** serve flat → owner+cross+flat
  RED; upload flat → `assertFileExists(<tenantId>/)` RED. Suíte **902/902**. Revisão `feature-review-agent`:
  REPROVOU 1ª (runbook ausente + teste cross-tenant não-discriminante + export relativo não-tratado +
  determinismo de disco) → endereçados → re-revisão do delta **APROVADO c/ ressalvas BAIXAS** (guard `$TENANT`
  no snippet add; over-match http externo = aceite-consciente). **Passo de DADOS em prod (não-migration):**
  mover imagens órfãs do editor p/ `pastas/<T>/` via critério DB-backed `{imagens} − {PastaDocumento.caminho_arquivo}`,
  ordem copy→deploy→cleanup — runbook em `DEPLOY-PROD-multitenant.md` (§Passos NÃO-migration). **Arquivos a
  commitar:** `PecaImagemController.php`, `PeticionarController.php`, `ExportarPecaTextoUseCase.php`,
  `tests/Pasta/Functional/{PecaImagem,Peticionar}ControllerTest.php`, `tests/Pasta/Unit/ExportarPecaTextoUseCaseTest.php`,
  `docs/specs/peca-imagem-isolamento-tenant.md`, `docs/specs/DEPLOY-PROD-multitenant.md`, `docs/specs/PROGRESSO-PENDENCIAS.md`.
- **Demais (MÉDIO/BAIXO):** M2, M6–M8, B1, B3–B10 conforme o doc da auditoria.
  - **M2 ✅ IMPLEMENTADO E REVISADO, NÃO COMMITADO (🔴 migration aguarda OK do dono).** Spec
    `docs/specs/access-request-isolamento-tenant.md`. `AccessRequest implements TenantAware` + coluna
    `tenant` NOT NULL; `submit` seta o tenant da sessão (guard null→400); `findPendingByTenant` escopa por
    `ar.tenant = :tenant` (substitui o JOIN no vínculo que vazava p/ todos os escritórios do usuário);
    `approve`/`deny` fecham IDOR via filtro (find→404) + guard `getTenant() === $tenant` (404); removida a dep
    `UserTenantRepository` do controller; **corrigido bug latente** (import `User` ausente no repo). Migration
    `Version20260629130000` (backfill vínculo→fallback→NOT NULL aborta; FK `FK_F3B2558A9033212A`/IDX
    `IDX_F3B2558A9033212A`) escrita + **schema test sincronizado** + **dev NÃO tocado** (dev=0 solicitações).
    Testes `AccessRequestIsolamento{Repository,Controller}Test` (7: findPendingByTenant/IDOR/submit-tenant/
    approve-deny-404/painel/submit-duplicado-per-tenant). Mutação 3× (filtro / ar.tenant / guard). Suíte
    **876/876**. Revisão: APROVADA COM RESSALVAS (strict_types legado, drift do índice, sugestão de teste —
    endereçada). **Follow-ups (B-series):** (a) índice parcial `uniq_access_request_pending` em drift (ledger
    sem banco) — recriar COM `tenant_id`; (b) ~~gap de autoridade residual: `submit` não valida posse do
    recurso~~ **✅ FECHADO (não commitado)** — `submit` E `approve` validam que o `resourceId` pertence ao
    tenant da sessão (helper `recursoPertenceAoTenant`, compara o tenant do recurso TenantAware explícito;
    404 se estrangeiro; cobre solicitação legada no approve). Testes `testSubmit/ApproveRejeitaRecursoDeOutroTenant`
    + 2 testes do M2 reescritos p/ recurso real (ids fake 4242/8888 quebrariam o check); mutação confirmada.
    Suíte **895/895**. Revisão `feature-review-agent`: aprovada (achados endereçados: comentário do código
    corrigido p/ "find por PK escapa via identity map"; vetor do approve fechado; spec do M2 atualizada).
  - ~~**M5** PecaImagemController auth-only~~ **✅ FECHADO** (entrada dedicada acima; subpasta por tenant).
    ~~**M6** F1 módulo~~ **✅ FECHADO (aceito por design, sem código)** — decisão do dono: modelo PARALELO
    (grant granular = autorização do item; módulo gateia só descoberta). `ResourceAccess` só concedido por
    admin e tenant-scoped → o grant é a autorização. Documentado em `AUTORIZACAO.md` §7 (reescrita)/§F1
    (rebaixada de crítica)/§5b/§F3 (Processo corrigido p/ wired). ~~**M7** migration Agenda aborta multi-tenant~~ **✅ FECHADO
    (aceito/verificado, sem código)** — abort = mesmo freio consciente dos outros 8 backfills (só num banco
    multi-tenant pré-existente, não ocorre); 2º tenant SEGURO (legenda criada sob demanda c/ setTenant, sem
    seed no bootstrap, agenda ok c/ zero). Documentado no runbook §Riscos. ~~**M8** uploads dev~~ **✅ FECHADO** —
    causa: container uid 1000 vs `public/uploads/*` uid 33/755 (test já OK via `var/uploads-test`); fix DEV =
    `chown -R 1000:1000 public/uploads` + criar `chamados` (graváveis, verificado); troubleshooting no root
    `CLAUDE.md` §Docker. **TODOS OS M1–M8 FECHADOS.**
  - **B5 🎯 DESTRAVADO — EM ANDAMENTO** (frente 1 commitada `7fcb827`; ver seção "🎯 B5" acima e
    `docs/specs/super-admin-escopo-tenant.md`). **B3** ResourceAccess sem tenant = **frente 4 do B5**.
  - **B-series ✅ (sweep 2026-06-30):** **B1** ✅ CORRIGIDO (único leak real — corrupção cross-tenant no CLI;
    2 `findOneBy` escopados por tenant + teste `DatajudIsolamentoTest` + mutação 2×); **B6** ✅ higiene
    (removido `canActOnResource` + termo fantasma `admin.tarefas.manage` do OR da sidebar); **B7** ✅ guard
    `canAccessModule('pastas')` no `DemandasController`; **B8** ✅ guard `canAccessModule('processos')` no
    `datajudSearch`; **B9** ✅ ACEITO (robustez migration SD, igual M7, já em prod); **B10** ✅ ACEITO (custo
    C3, app garante unicidade). **B4** ✅ CORRIGIDO (índice `idx_audit_tenant_created` em `audit_log`;
    migration `Version20260630130000` aplicada no dev; só perf). **Suíte 906/906. TODO O B-SERIES FECHADO.**

## Detalhamento por etapa

### E1 — Finalizar tema escuro ✅
Convertidas as últimas cores claras fixas → variáveis do Bootstrap. Regra aplicada:
badges com cor custom do marcador mantêm `color:#333` (legível sobre pastel em ambos os
temas); só o ramo de fallback "sem cor" usa `var(--bs-tertiary-bg)` / `var(--bs-body-color)`.

Arquivos alterados:
- `app/public/css/app.css:257` — hover logout `#fff0f0` → `var(--bs-secondary-bg)`.
- `app/templates/pasta/_tabela.html.twig` — badges desktop/mobile + popover JS (linha ~607,
  esta NÃO estava no levantamento original).
- `app/templates/expediente/_painel_marcador.html.twig:6` — badge fallback.
- `app/templates/expediente/index.html.twig` — badges em JS (popover + AJAX, 2 blocos).
- `app/templates/pasta/peticionar.html.twig` — toolbar + título do editor.
- `app/templates/_partials/modal_mover_marcador.html.twig` — `#eef2ff` → `rgba(13,110,253,.12)`
  (também fora do levantamento original; `#e9ecef` remanescente ali é sentinela de lógica, não cor).

Verificação: `lint:twig` OK nos 5 templates. **Smoke visual ainda não feito** (recomendado
abrir Expediente/Pasta/peticionar no tema escuro e conferir contraste dos badges).

### E2 — Bug notificação evento + specs ✅
- `AgendaController`: 3 literais (`'EVENTO_CRIADO'` x2, `'EVENTO_CANCELADO'` x1 — a 3ª, na
  `criarAjax`, não estava no levantamento) trocados pelas constantes
  `Notificacao::TIPO_EVENTO_CRIADO`/`..._CANCELADO`; adicionado `use App\Entity\Notificacao`.
- `docs/specs/tema-escuro.md`: cabeçalho → implementado (jun/2026).
- `docs/specs/notificacoes-link-justificativa-ponto.md`: tabela + não-objetivos corrigidos.
- Verificação: `php -l` OK; `phpunit tests/Notificacao` → 17/17.
- ⚠️ **Dívida de dados (não tratada):** notificações antigas gravadas com tipo
  `'EVENTO_CRIADO'` (maiúsculo) não casam com o mapa de ícone/categoria. São transitórias;
  não foi feita migration. Decidir depois se vale um UPDATE pontual.

### E3 — Notificação de novo chamado + conserto de bug crítico ✅
Spec: `docs/specs/servicedesk-notificacao-novo-chamado.md`. Decisão: feito cirúrgico no
legado (não migração). Entregue:
- `NotificacaoService::notificarNovoChamado(Chamado, Tenant, url)` — notifica gestores
  `admin.servicedesk.manage`, exclui o solicitante.
- `Notificacao`: `TIPO_SERVICEDESK_NOVO` (em `TIPOS_GESTAO` + ícone) e `TIPO_SERVICEDESK`
  (constante para os literais `'servicedesk'`).
- `ServiceDeskController::notificarNovoChamado()` implementado; literais padronizados.

🔴 **DESCOBERTA IMPORTANTE — ServiceDesk estava 100% quebrado (HTTP 500):** controller e
`show.html.twig` chamavam `ChamadoInteracao::setUsuario/getUsuario` e
`ChamadoAnexo::setUsuario`, métodos inexistentes (certos: `setAutor/getAutor` e
`setEnviadoPor`). Corrigidas TODAS as ocorrências. Abrir/interagir/atribuir/mudar status
davam 500 — agora funcionam. Também modernizado `ChamadoType` (deprecation `File`).

- Testes: `tests/Notificacao/Functional/NotificacaoServiceTest.php` (3 casos: gestor recebe,
  sem-permissão não recebe, solicitante excluído, **cross-tenant não vaza**, sem-gestores) +
  `tests/ServiceDesk/Functional/CriarChamadoControllerTest.php` (happy path HTTP).
- Verificação: suíte completa **705/705 OK**; revisão `feature-review-agent` endereçada
  (cross-tenant test e doc de limitação adicionados).
- ⚠️ Dívida deixada para E4: os caminhos interagir/atribuir/status só tiveram o conserto de
  método, **sem teste automatizado ainda** (entram no test net da E4). `Chamado` não tem
  tenant — persistir na migração.

### E4 — Migrar ServiceDesk → `src/ServiceDesk/` 🟡 EM ANDAMENTO
Spec: `docs/specs/servicedesk-migracao-dominio.md`.

**E4.1 ✅ feito** — spec da migração (contrato de comportamento de todas as rotas + achados)
+ rede de testes do comportamento atual em
`tests/ServiceDesk/Functional/ServiceDeskFluxoControllerTest.php` (show/acesso, interação,
atribuir, status, status inválido, negações). 9 testes (1 skipped = vazamento conhecido).

**H1 ✅ Hotfix de isolamento (decisão tomada: coluna tenant + hotfix antes da migração).**
Spec: `docs/specs/servicedesk-isolamento-tenant.md`. Entregue:
- `Chamado.tenant` (ManyToOne not null) + migration `Version20260625120342` (add nullable →
  backfill via solicitante → NOT NULL + FK/índice na convenção Doctrine).
- `ChamadoRepository`: 7 queries do dashboard agora filtram por tenant.
- `ServiceDeskController`: `novo()` grava tenant, `index()` passa tenant; guard
  `garantirChamadoDoTenant()` (404) fecha IDOR cross-tenant nas actions por ID
  (show/interacao/atribuir/status).
- `AppFixtures`: chamados de seed gravam o tenant do solicitante.
- Testes: `ChamadoRepositoryTest` (isolamento das queries) + casos cross-tenant 404 no
  `ServiceDeskFluxoControllerTest`. Suíte 715/715; `schema:validate` OK (dev+teste);
  `fixtures:load` OK. Revisão `feature-review-agent` endereçada (fixtures + spec + IDOR).

**H2 ✅ Download seguro de anexos** (`docs/specs/servicedesk-anexo-download-seguro.md`):
`chamados_uploads_dir` saiu do `public/` (→ `var/uploads/chamados`), rota controlada
`servicedesk_anexo` (tenant + permissão + posse), template via `path()`. Testes em
`AnexoDownloadControllerTest` (200 dono / 404 cross-tenant / 403 estranho).

⚠️ **Follow-up de segurança app-wide (aberto):** os demais módulos ainda guardam uploads em
`public/uploads/*` (`pastas`, `justificativas`, `clientes`, `perfil`). Mesmo padrão (fora do
public + rota controlada) deveria ser aplicado a eles — esforço próprio.

**Deploy H1/H2:** rodar a migration do tenant em prod; mover arquivos de
`public/uploads/chamados` → `var/uploads/chamados` (provável ~vazio).

Achados para o E4.2 (sem bloqueio): bug do label em `status()` (monta após `setStatus`) e
ausência de CSRF em `atribuir`/`status`.

Próximo passo: E4.2 (UseCases + correções de CSRF/label) — schema do tenant já resolvido.

### E5–E6
Ver plano. Não iniciadas.

---

## Como retomar

1. Ler este doc (tabela + detalhamento da etapa em 🟡).
2. Ler o plano em `.claude/plans/atualize-a-questao-do-humming-newell.md`.
3. Para E4/E5/E6, ler também a spec da migração em `docs/specs/` (criada na 1ª sub-etapa).
4. Continuar do "Próximo passo" da etapa em andamento.
