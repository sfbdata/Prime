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
