# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: **2026-07-14 — RODADA DE AJUSTES no módulo (já em prod).** Itens 1 e 4 FECHADOS+commitados. **Item 2 (objeto=caso unificado) ✅ COMPLETO: Fatias 1–7 commitadas.** **Item 3 (tentativa→registro de contato) ✅ COMMITADO `6c95985`** (enums CanalContato/ResultadoContato; evento `ContatoRealizado` reusado; `ocorridoEm`=dataContato; sem migração; feature-review LIMPO). **Item 5 (editar/excluir obrigação) — ✅ COMPLETO** (Fatia A Editar `d101244` + Fatia B Excluir `7b6b471`; spec `docs/specs/cobranca-ajuste5-editar-excluir-obrigacao.md`; aposentou "Reconhecer valor"; trava vigente-aware; hard delete com guards; sem migração; feature-review LIMPO nas duas fatias; `tests/Cobranca` 425/425, global 1740/1740). Próximos: itens **6** (pagamento FIFO — MÉDIO, SPEC), **7** (acordo inteligente — MÉDIO/ALTO, SPEC própria), **8** (parcelas na aba — BAIXO, depende do 7). Módulo segue no ar; nada desta rodada foi deployado ainda.

**Pegadas de smoke no DEV (inócuas, dev-only):** obrigação 5090 (objeto 117) editada R$50→R$55; eventos de histórico de contato/edição/exclusão em objetos 296/297. Nada em prod.

---

## 🎯 RODADA DE AJUSTES 2026-07-13 — LEIA PRIMEIRO

**Fonte de verdade da rodada:** [`docs/gestao-cobrancas/AJUSTES_BACKLOG.md`](AJUSTES_BACKLOG.md) — os 8 ajustes com a **ideia final de cada um já fechada com o humano** (decisões, riscos, escopo). Ler ANTES de tocar em código.

**CADÊNCIA acordada com o humano (IMPORTANTE — não pular):**
`implementar → MOSTRAR o resultado (smoke visual quando dá) → o humano APROVA → SÓ ENTÃO rodar suíte + /review + corrigir + commit atômico → próximo item`.
Ou seja: **não rode a suíte completa nem o /review antes do humano aprovar o resultado visual do item.**

**Ordem de execução:** `1 → 4 → 3 → 5 → 6 → 7 → 8 → 2` — **mas o humano ANTECIPOU o item 2** e ele está sendo feito em fatias (ver abaixo).

### Estado do Git (reconferir sempre)
- **`master` = `278ac2e`(+hotfixes até `61a1450`);** branch `gestao-cobrancas` local, não pushada. Deploy/push/merge são do humano.
- **HEAD = `2a4ea7c`** (+ commit de docs a seguir). Commits desta rodada, em ordem: `d0e4eb5`(backlog) · `854cade`+`35d8d12`(item 1) · `ea4f86a`(item 4) · `65f89ac`(spec item 2) · `ba5592b`(plano item 2 + sub-decisão G) · `fe536eb`(item2 fatia 1) · `be936a6`(item2 fatia 2) · `4b8dfd8`(spec item2 revisada: criar objeto pede nome) · `8118137`(item2 fatia 3) · `3522495`(item2 fatia 4) · `74904f0`(item2 fatia 6) · `d9a9d4f`(docs) · `b6b9029`(item2 fatia 5) · `79e5923`(docs) · `2a4ea7c`(item2 fatia 7).
- **Working tree LIMPO.** tests/Cobranca 403/403, global 1717/1717. HEAD após item 3 = `6c95985` (+ commit de docs a seguir).

### Item 1 — ✅ CONCLUÍDO E COMMITADO (`854cade`, `35d8d12`)
Tooltips (popover `?` no hover em modo/forma de honorários) + campo de percentual (input-group `%`, aceita vírgula pt-BR gravando decimal, oculta/desabilita em "sem percentual"), nos modais de CRIAR e EDITAR carteira. Review aprovado + smoke OK.

### Item 4 — ✅ CONCLUÍDO E COMMITADO (`ea4f86a`)
Remoção completa da "Revisão de pessoa cobrada". Spec: [`docs/specs/cobranca-ajuste4-remover-revisao.md`](../specs/cobranca-ajuste4-remover-revisao.md). Migration `Version20260713120000` (`DROP TABLE IF EXISTS`; down recria) aplicada no dev E no test. `TipoEventoHistorico::RevisaoVinculo` PRESERVADO (legado — não reintroduzir a remoção). feature-review LIMPO. **Deploy futuro:** `DROP TABLE` em prod só com `SELECT count(*)` = 0 (ou só resolvidas) — confirmar antes.

### Item 2 — 🔨 EM ANDAMENTO (objeto e caso unificados na experiência)
Spec: [`docs/specs/cobranca-ajuste2-objeto-caso-unificado.md`](../specs/cobranca-ajuste2-objeto-caso-unificado.md) · Plano: [`PLANO_AJUSTE2_OBJETO_UNIFICADO.md`](PLANO_AJUSTE2_OBJETO_UNIFICADO.md). Abordagem A: `CasoCobranca` vira âncora invisível (1/objeto), SEM migração de dados. Cada fatia: TDD → smoke real no navegador → feature-review LIMPO → commit.
- **✅ Fatia 1 (`fe536eb`)** leitura: DTOs `ObjetoDetalheOutput`(embrulha `CasoDetalheOutput`)/`VinculoPessoaOutput` + `MontarDetalheObjetoUseCase` + `CasoCobrancaRepository::casoAncoraDoObjeto`(ativo mais recente, guarda >1, fallback encerrado) + `VinculoPessoaObjetoRepository::todosDoObjetoComPessoa`(fetch-join).
- **✅ Fatia 2 (`be936a6`)** página `cobranca_objeto_show` (`/cobrancas/objetos/{id}`): template `objeto/show.html.twig` (corpo do caso movido pra cá + aba Pessoas); serviço `MontadorModaisCaso` (extraído dos 3 helpers do `CasoController`, DRY); links da carteira apontam pro objeto (`objetoId` no `CasoResumoOutput`). **`caso_show` AINDA RENDERIZA** (redirect é a Fatia 5).
- **✅ Fatia 3 (`8118137`)** criar objeto pede só o NOME do cobrado → `CriarObjetoComCobrancaUseCase` (novo) orquestra Pessoa enxuta+Objeto+Caso+Vínculo; **`CriarObjetoUseCase` cru INTACTO pro import**; "Abrir caso" REMOVIDO (rota `cobranca_caso_abrir`/form/teste; `AbrirCasoUseCase` fica); "Novo objeto" no header de "Casos da carteira". SEM migration (pessoa cobrada segue obrigatória).
- **✅ Fatia 4 (`3522495`)** "Nova pessoa" na aba Pessoas do objeto (cadastra+vincula via `CriarPessoaVinculadaAoObjetoUseCase`; rota `cobranca_objeto_pessoa_criar`).
- **✅ Fatia 6 (`74904f0`)** card "Objetos" REMOVIDO da carteira; "Vincular existente" + "Encerrar vínculo"(x por linha) relocados pra aba Pessoas do objeto; `PessoaController::vincular/encerrar` redirecionam pro objeto; rota global `cobranca_pessoa_criar` + form `CriarPessoaType` órfão REMOVIDOS; `VinculoPessoaOutput.vinculoId` novo; `CarteiraController` enxugado; `CadastroPessoaVinculoControllerTest` reescrito.
- **✅ Fatia 5 (`b6b9029`)** as mutações de TODOS os controllers (`CasoController` encerrar/alterarPessoa/judicializar/tentativa + `Obrigacao`/`Pagamento`/`Acordo`/`Documento`/`Liquidacao`/`AcaoCobranca`) redirecionam pro objeto via novo helper `objetoIdDoCaso(?CasoCobranca): int` no trait `AutorizacaoCobranca`; `caso_show` vira **redirect 302** pro objeto **mantendo `findOneByIdDoTenant`+404 cross-tenant**; deps ociosas (`MontarDetalheCasoUseCase`/`MontadorModaisCaso`) removidas do `CasoController`; rota-nome `cobranca_caso_show` e rotas POST permanecem. 13 testes ajustados (GET-token e assert de `/casos/{id}`→`/objetos/{objetoId}`). Sem `SecaoController` (mutações de seção vivem no `DocumentoCobrancaController`). feature-review LIMPO; smoke real OK (deep-link 296→297 + mutação volta ao objeto). Sem migration.
- **✅ Fatia 7 (`2a4ea7c`)** "Casos" fora do subnav (`_subnav.html.twig` → Painel·Carteiras·Alertas); `caso/show.html.twig` morto DELETADO (grep zero refs); copy "caso"→"cobrança/objeto" em `carteira/show`/`dashboard/index`/`alertas/index`; métrica "Casos" redundante removida da carteira (fica só "Objetos"); Central de Alertas passa a linkar DIRETO ao objeto (`objetoId` novo em `CasoComAlertasOutput`, sem hop 302). Teste `testNavegacaoSoCarteiraObjeto` + assert do href no `testAlertasRender`. feature-review LIMPO; smoke real OK (subnav sem Casos, alertas→/objetos/297). Sem migration.

### ✅ ITEM 2 COMPLETO (Fatias 1–7). Follow-ups pós-prod (decisão do humano — NÃO feitos):
1. **Aposentar de vez a rota `cobranca_caso_index` + `caso/index.html.twig`/`_resultado.html.twig`** (a lista "Casos", hoje órfã do menu mas ainda acessível por URL; `_resultado` ainda linka `caso_show` e ainda diz "caso(s)" — intocado de propósito p/ não quebrar `testCasoIndexXhrFragmento`). A spec já lista isso como follow-up pós-validação em prod.
2. **Card "Cobranças judicializadas" do dashboard** (`dashboard/index.html.twig:92`) ainda aponta pra `cobranca_caso_index?status=judicializado` — único link vivo de navegação pra lista de casos. Permitido pela invariante (rota = deep-link), mas repontar/neutralizar quando a lista for aposentada (segue o follow-up 1).
- **Gotcha smoke Playwright dev:** modal `#modalAlertaPonto` intercepta cliques → remover via `browser_evaluate` antes de interagir. Login dev: `farlei.rocha@gmail.com`/`Prime123!`, `localhost:8080`. Objetos de teste criados no smoke: 296/297/carteira 3.

### Itens seguintes do backlog (ideias fechadas; ainda não iniciados)
**3** tentativa→registro de contato · **5** editar+excluir obrigação · **6** pagamento auto-alocação FIFO · **7** acordo inteligente (**SPEC própria**) · **8** parcelas na aba Obrigações.

### Follow-ups antigos (não bloqueiam)
1. Teste CommandTester do `app:cobranca:importar`. 2. N+1 de autorização `user_tenant` (transversal, MÉDIO — `PLANO_OTIMIZACAO_QUERIES.md` §1.1).

## Estado do módulo em prod (contexto — 2026-07-11)
- Módulo 100% em produção e populado (194 casos / 3270 obrigações no tenant 1). `master` deployado; import via CLI `app:cobranca:importar`. Detalhes na memória `[[project_gestao_cobrancas]]` e no `DEPLOY_RUNBOOK.md`.

_(Histórico da implementação Etapas 0–9 preservado abaixo.)_

## Commits desta sessão (sobre `790fe95`)
- `c80b4e5` — **spec da Etapa 9** (`docs/specs/cobranca-etapa9-dashboard-alertas.md`, risco MÉDIO), alvo da revisão.
- `3cd426a` — **implementação Etapa 9** (Dashboard + Central de Alertas). Detalhe completo no `EXECUTION_STATUS.md` (seção "Etapa 9 — o que foi entregue").
- *(pendente neste commit)* atualização dos docs vivos (EXECUTION_STATUS + este handoff + NEW_CHAT_PROMPT).

## O que a Etapa 9 entregou (resumo)
Camada visual final de LEITURA, visão do ESCRITÓRIO (não per-caso):
- `CasoCobrancaRepository::doTenant(Tenant, ?Carteira)` — agregação tenant-scoped; **não** soma saldo em SQL.
- `MontarDashboardCobrancaUseCase` (financeiro/operacional/resultado) + `MontarCentralAlertasUseCase` (reusa `AlertasCobranca`, agrupa por carteira). 4 Output DTOs readonly.
- `DashboardController` fino: `GET /cobrancas/painel` (`cobranca_dashboard`) + `GET /cobrancas/alertas` (`cobranca_alertas`). Gate módulo; filtro carteira anti-IDOR→404; período defensivo. Sem escrita → sem CSRF.
- Templates `dashboard/index` + `alertas/index`; `_subnav` com **Painel**/**Alertas**; CSS tema-aware.
- 25 testes (10 dashboard UseCase DB, 4 central UseCase DB, 11 controller HTTP). Revisão adversarial + tenant-safety SEM bloqueante.

## Padrão da Etapa 9 (agregação de leitura tenant-wide — se precisar estender)
- **Saldo/honorários/alertas são derivados** e vivem nos serviços (`CalculadoraSaldo`/`CalculadoraHonorarios`/`AlertasCobranca`). Agregar tenant-wide = **iterar casos + reusar os serviços**, NUNCA `SUM` de saldo em SQL (reimplementaria a regra de "exigível" e divergiria). Padrão herdado de `MontarVisaoCarteiraUseCase`.
- `doTenant` inclui casos encerrados de propósito (o "valor total recuperado" precisa deles; encerrado zera saldo/alertas por derivação).
- Honorários realizados por forma (§18): `acrescido_divida` usa `Pagamento.valorHonorarios` (já separado); `retido`/`cobrado_separado` usa `CalculadoraHonorarios::realizadosSobreRecuperacao`; `sem_percentual` 0.
- `CalculadoraHonorarios` e `AlertasCobranca` são `final` → **não mockáveis**: os testes de UseCase são DB-backed (KernelTestCase + Foundry + serviços reais montados no setUp), não unit com mocks.

## PRÓXIMA AÇÃO — Preparo de deploy/homologação (NÃO é mais implementação)
> **Checklist final consolidado em `docs/gestao-cobrancas/RELEASE_CHECKLIST.md`** (pré-merge / pré-deploy /
> pós-deploy, smoke manual, riscos, rollback, comandos seguros de validação). Use-o como guia do humano.

1. **Data-migration de permissões `cobrancas` + `resources.cobranca.*` para PRODUÇÃO** (dev/test já têm via fixture). É o ÚNICO item de banco que falta para prod usar o módulo. Gerar migration idempotente que insere as Permissions e concede aos papéis system.
2. **Semear grafo realista no dev + smoke manual no navegador** (dev não tem dados de Cobrança — módulo novo, ausente do dump de prod): validar drag/upload XHR de documentos (8C-B), fluxo visual de importação (8C-A), file-manager, e as telas novas **Painel** (`/cobrancas/painel`) e **Alertas** (`/cobrancas/alertas`). Os testes funcionais já provam a renderização real no container (smoke de render OK) — falta o smoke de interação JS.
3. **Deploy** via `deploy-prod-tls.sh` (rebuild) — só no fim. **Nenhuma migration nova nas Etapas 8–9**; só a data-migration de permissões do item 1.
4. **Integração da branch** (decisão do humano): `gestao-cobrancas` tem o DJEN `b044c0c` na base (inofensivo) + caronas (metas/Datajud). Mergear no master DEPOIS do DJEN.

## ✅ CONCLUÍDO — Otimização de queries (N+1) em TODAS as telas (perf) — 2026-07-11
> Plano completo e medições antes/depois: **`docs/gestao-cobrancas/PLANO_OTIMIZACAO_QUERIES.md`** (§1.1 + §6).
> Fases **P0–P4 TODAS CONCLUÍDAS**, revisadas (SEM bloqueante) e commitadas isoladamente sobre `eba6a70`:
> `091315c` (P0) → `153fc24` (P1) → `991f7ac` (P2) → `7909d96` (P3) → `51b23c2` (P4). `tests/Cobranca`
> **409/409**; suíte global **1690/1690**.
- **P0** — primitivas reutilizáveis `CalculadoraSaldo::saldosDosCasos`/`derivarSaldosDosCasos` +
  `AlertasCobranca::alertasDosCasos`/`montarAlertas` (regra única compartilhada); Dashboard refatorado (dedup, 42→42).
- **P1 Central de Alertas 1592→44** (fetch-join `doTenant`) · **P2 Visão da Carteira 876→221†** (saldo batch +
  fetch-join `daCarteira` + fix N+1 vínculo→pessoa) · **P3 Lista de Casos 199→41** (saldo batch + fetch-join
  `findByFilters`) · **P4** Lista de Carteiras 40→38 (fetch-join cliente) + Detalhe 96→92 (dedupe `alertasComContexto`).
- **As queries de Cobrança de toda tela agora são O(1)+O(#datasets)** (não O(#casos)); testes de consistência batch==per-caso.
- **† ÚNICO resíduo aberto = N+1 de AUTORIZAÇÃO `user_tenant`** (`PermissionChecker::hasPermission` e
  `TenantContext` re-consultam `UserTenant` por chamada, sem memoização por request). **PRÉ-EXISTENTE,
  transversal a todo o app, MÉDIO risco (docs/AUTORIZACAO.md), FORA do escopo desta frente** — follow-up
  SEPARADO destacado no PLANO §1.1 (decisão do humano; memoizar derrubaria Visão da Carteira e Detalhe ao piso ~44).

## Follow-ups conhecidos (não bloqueiam; decisão do humano)
- **Perf O(casos) do dashboard (Etapa 9):** a agregação itera todos os casos do tenant e reusa os serviços por caso (~6–8 queries/caso; `saldoExigivel`/`doCasoExigiveis` acabam repetidos). Aceito p/ MVP (volume moderado; limitável por carteira/período). Redução futura = método agregado que reúna exigível+movimentos por caso numa passada (não materializar saldo).
- **Coletor de temporários órfãos de importação** (8C-A): preview 2× sem confirmar deixa o 1º `import-tmp/<tenantId>/<token>.xlsx` órfão. MENOR (disco). Follow-up: comando de limpeza por idade.
- **Smoke de navegador (JS) pendente** (8C + 9): ver item 2 do preparo de deploy.
- **FIFO de alocação de pagamento** (#8, adiado); cobertura positiva granular de `gerenciar` (aceito p/ MVP); NITs teóricos herdados aceitos.

## Decisões mantidas (NÃO alterar)
- **E7:** linha só-encargos rejeitada com motivo, sem obrigação principal-zero. A UI só EXIBE o motivo.
- **Documento vive no Caso, nunca na Pasta (INV-25).** Ao judicializar, permanece.
- **Caso encerrado**: bloqueia mutação OPERACIONAL/financeira (guard no servidor — 8B). Documentos permanecem gerenciáveis.
- **Saldo/honorários/alertas SEMPRE derivados por serviço**, nunca coluna nem `SUM` em SQL (invariável 20; §18/§19; invariável 28). O dashboard (E9) respeita isso: itera casos e reusa os serviços.
- Dinheiro = int centavos; saída via `|centavos`; entrada via `CentavosType`.
- Autorização: módulo em TODA rota; capacidade via `hasPermission` nas mutações; leitura (8A/9) exige só o módulo; `isSystem`/`ROLE_SUPER_ADMIN` = bypass (por design).
- **Etapa 9 (SPEC §5 da spec da etapa):** "pagamentos a verificar"=alerta ObrigacaoVencida; liquidação recupera sem gerar honorário; taxa = recuperado/(recuperado+em aberto); encerrado só no recuperado.

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD `3cd426a` ou posterior, working tree limpo.
2. Subir containers de dev se preciso; `php -d memory_limit=512M bin/phpunit tests/Cobranca` = 398/398; global 1679/1679.
3. Ler este handoff + EXECUTION_STATUS (seção Etapa 9) + a spec `docs/specs/cobranca-etapa9-dashboard-alertas.md`.
4. Carregar skill `workflow`. **A implementação acabou** — retomar pelo **preparo de deploy** (item 1: data-migration de permissões p/ prod) OU aguardar decisão do humano sobre integração da branch. Não iniciar novo domínio Financeiro (§19/§24 — fora do MVP).
