# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: **2026-07-15 — rodada 1–8 fechada + ✅ AJUSTE 9 ("acordo sobre acordo") ENTREGUE.** Specs: `docs/specs/cobranca-ajuste9-bloquear-acordo-sobre-acordo.md` (INV-I a INV-M; D1–D6) · `cobranca-ajuste7-acordo-inteligente.md` (D1–D8; INV-A a INV-H) + as dos ajustes 2/4/5/6. Detalhe por item no AJUSTES_BACKLOG.
>
> **HEAD = `1a2a2df`** (ajuste 9; spec em `4478a88`) — branch `gestao-cobrancas` local, **NÃO pushada**. Commits do item 7: `361cb53`(F1) · `2c3ed78`(F2) · `a2be47f`(F3) · `bb10f90`(F4). Item 8: `9201fc2`. **Migration `Version20260714130000`** (aditiva) aplicada só em **dev+test** — prod é do humano no deploy. **O ajuste 9 NÃO tem migration.** `tests/Cobranca` **539/539**, global **1854/1854**. **Nada da rodada foi pushado/deployado.**
>
> **➡️ PRÓXIMO PASSO = DECISÃO DO HUMANO, não implementação.** As opções: (a) o humano **testa** no dev; (b) **integrar** `gestao-cobrancas` no master + **deploy** (a rodada tem 1 migration aditiva + o DROP do ajuste 4, que exige `SELECT count(*)` antes em prod — ver AJUSTES_BACKLOG §4 e RELEASE_CHECKLIST); (c) atacar um dos follow-ups abaixo. **NÃO iniciar implementação nova sem o humano escolher.**
>
> **✅ AJUSTE 9 ENTREGUE (2026-07-15, commit `1a2a2df`) — fecha o follow-up "acordo sobre acordo".**
> **Decisão de produto do humano:** renegociar a renegociação **NÃO é fluxo do negócio** → bloquear na CRIAÇÃO. Para refazer um acordo: **rompa o atual** (as originais voltam ao saldo por derivação) e acorde sobre elas.
> **A investigação achou um vetor PIOR que o registrado na spec do item 7 §13:** romper/cancelar um acordo A cujas parcelas um acordo B vigente renegociou **DUPLICA a dívida no saldo** (as originais de A voltam ao exigível E as parcelas de B continuam). A spec do item 7 só analisou o vetor de EDIÇÃO. Detalhe: spec do ajuste 9 §2.1.
> **Entregue:** `doCasoSubstituiveis` (só dívida original; reusa `doCasoExigiveis`, **INV-J: o saldo NÃO mudou**) · guard no `CriarAcordoUseCase` + `ObrigacaoNaoEhDividaOriginalException` · guard no romper **E** no cancelar + `AcordoComParcelasRenegociadasException` (só alcança dado legado — é alarme) · UI (só originais + texto do caminho + aviso na lista vazia). **Modal de PAGAMENTO intocado (INV-K).** Sem migration.
> **⚠️ NÃO "CONSERTE" A ASSIMETRIA (spec §5.1.1):** o **render** (`MontadorModaisCaso::deMutacao`) filtra as substituíveis, mas o **POST** (`AcordoController::criar`) valida contra as **exigíveis**. É deliberado: igualar as listas faz o ChoiceType barrar antes, deixando guard e `catch` **inalcançáveis** — foi o BLOQUEANTE da revisão.
>
> **🔴 LIÇÕES DO AJUSTE 9 (as mais duras até aqui):** (1) o teste functional do `criar` **passava pelo motivo errado** — form inválido também dá 302 p/ a mesma URL sem criar acordo; provado por mutação: removendo o `catch`, seguia VERDE. Guard novo exige teste que assere a **MENSAGEM**, não só o redirect. (2) o `catch` chegou a ficar **sem o `use`** da exception (resolveria p/ o namespace do Controller, nunca capturaria → 500) e **539 testes passaram verdes**: *seguro inalcançável não é seguro, é código morto que apodrece*. (3) Reusar exception por preguiça mente pro gestor: `ObrigacaoDeAcordoException` diz "acordo **vigente**" e estava sendo lançada p/ parcela de acordo **rompido**. (4) Doc stale é pior que doc ausente — o docblock do teste sobreviveu à mudança de desenho e ensinava a reintroduzir o bloqueante (pego na re-revisão).
>
> **🔴 LIÇÕES DO ITEM 7 (não repetir):** (1) a revisão adversarial pegou um **bug financeiro real** na F4 — o editor apagava parcela de A que um acordo B vigente guardava como dívida original (hard delete → dívida sumia se B fosse rompido), alcançável **só pela UI**; (2) ao corrigir, entraram 2 regressões (exception fora do `catch` → 500; guard antes do `linhaMudouParcela` → acordo ineditável) porque os testes do guard eram **só unit** — **guard novo exige functional do caminho HTTP**; (3) na F3, sem `#[ORM\OrderBy]` as parcelas saíam na ordem da heap e um UPDATE as embaralharia; (4) `readOnly` ≠ `disabled` (disabled não é submetido); (5) o JS `parseCentavos` é **pt-BR** (ponto=milhar) — escrever valor com `.toFixed(2)` cru vira erro de 100×. Tudo provado por **mutação**, não por relato.
>
> **✅ FOLLOW-UP "acordo sobre acordo" na CRIAÇÃO — FECHADO pelo ajuste 9** (era: `doCasoExigiveis` oferece parcelas de acordo vigente e `CriarAcordoUseCase` nunca olha `acordoOrigem`). Ver o bloco do ajuste 9 acima.
>
> **✅ Item 8 (último) ENTREGUE:** aba Obrigações agrupada — acordo vigente vira linha-resumo + accordion das parcelas + "Abrir acordo"; substituídas saem da lista. Partição no `MontarDetalheCasoUseCase::agruparPorAcordo()`; **a ORDEM dos testes importa** (substituída ANTES de parcela — senão acordo-sobre-acordo infla o total do grupo contra o saldo). Fetch-join no `doCaso` matou um N+1 pré-existente. Detalhe no AJUSTES_BACKLOG §8.
>
> **⏳ FOLLOW-UPS ABERTOS (nenhum bloqueia; decisão do humano):** ~~(1) "acordo sobre acordo"~~ **FECHADO pelo ajuste 9**. (2) **N+1 residual** em `AcordoOutput::fromEntity` (2 COUNT por acordo nas coleções lazy) — pré-existente, bounded por #acordos. (3) **N+1 de autorização `user_tenant`** (transversal ao app, MÉDIO — `PLANO_OTIMIZACAO_QUERIES.md` §1.1). (4) Cosmético: linhas do editor de acordo empilhadas (Descrição/Valor/Vencimento) — mais altas que o ideal. (5) Teste CommandTester do `app:cobranca:importar`. (6) Follow-ups do item 2: aposentar a rota/lista `cobranca_caso_index` (órfã) + o card "judicializadas" do dashboard que aponta pra ela. (7) **Do ajuste 9 (§8):** detalhe do acordo A conta parcelas substituídas como se valessem (`MontarDetalheAcordoUseCase:45-59`; só dado legado); obrigação **quitada** segue oferecida como substituível (pré-existente); 2 dívidas aceitas na revisão (`asub.tenant` não filtrado — fail-safe; botão de submit ativo com lista vazia — cosmético).

**Pegadas de smoke no DEV (inócuas, dev-only):** obrigação 5090 (objeto 117) editada R$50→R$55; eventos de histórico de contato/edição/exclusão em objetos 296/297. Item 7: smoke do gerador no objeto 107 **sem persistir**. **Ajuste 9: acordo `4` CRIADO de verdade no caso 101 / objeto 102** (ativo, R$ 510,00, 3 parcelas `7537-7539`, substituiu as originais `4251-4253` = competências 03–05/2025) — serve de dado pronto p/ clicar. Nada em prod.

**Dados úteis do ajuste 9 no dev:** objeto **102** (caso 101) tem acordo vigente + 15 originais acordáveis → é onde se vê o bloqueio funcionando. Query de diagnóstico (spec §7) retorna **0 linhas** em dev; **prod é dado de TESTE** (humano, 2026-07-15), então não há plano de limpeza.

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
