# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: **2026-07-13 — RODADA DE AJUSTES no módulo (já em prod).** Item 1 fechado+commitado; Item 4 IMPLEMENTADO mas NÃO commitado (aguardando verificação). Módulo segue no ar; nada desta rodada foi deployado ainda.

---

## 🎯 RODADA DE AJUSTES 2026-07-13 — LEIA PRIMEIRO

**Fonte de verdade da rodada:** [`docs/gestao-cobrancas/AJUSTES_BACKLOG.md`](AJUSTES_BACKLOG.md) — os 8 ajustes com a **ideia final de cada um já fechada com o humano** (decisões, riscos, escopo). Ler ANTES de tocar em código.

**CADÊNCIA acordada com o humano (IMPORTANTE — não pular):**
`implementar → MOSTRAR o resultado (smoke visual quando dá) → o humano APROVA → SÓ ENTÃO rodar suíte + /review + corrigir + commit atômico → próximo item`.
Ou seja: **não rode a suíte completa nem o /review antes do humano aprovar o resultado visual do item.**

**Ordem de execução:** `1 → 4 → 3 → 5 → 6 → 7 → 8 → 2`.

### Estado do Git (reconferir sempre)
- **`master` = `278ac2e`(+hotfixes até `61a1450`);** branch `gestao-cobrancas` local, não pushada. Deploy/push/merge são do humano.
- **HEAD = `35d8d12`.** Commits desta rodada: `d0e4eb5` (backlog), `854cade` + `35d8d12` (item 1).
- **Working tree SUJO com o item 4 inteiro (não commitado):** ~36 arquivos (deletados os do backend de Revisão; modificados alertas/dashboard/detalhe/templates/purga/testes; novos `migrations/Version20260713120000.php`, `tests/Cobranca/Functional/AcaoMutacaoControllerTest.php`, `docs/specs/cobranca-ajuste4-remover-revisao.md`). Rodar `git status` para a lista exata.

### Item 1 — ✅ CONCLUÍDO E COMMITADO (`854cade`, `35d8d12`)
Tooltips (popover `?` no hover em modo/forma de honorários) + campo de percentual (input-group `%`, aceita vírgula pt-BR gravando decimal, oculta/desabilita em "sem percentual"), nos modais de CRIAR e EDITAR carteira. `tests/Cobranca` 411/411. Review aprovado + smoke no navegador OK.

### Item 4 — 🔨 IMPLEMENTADO, FALTA VERIFICAR/COMMITAR
Remoção completa da "Revisão de pessoa cobrada". Spec: [`docs/specs/cobranca-ajuste4-remover-revisao.md`](../specs/cobranca-ajuste4-remover-revisao.md).
- **Já feito:** apagado backend (controller/2 UseCases/entity/repo/StatusRevisao/2 forms/3 DTOs/2 exceptions/factory); removida dos serviços/telas (`AlertasCobranca`, `TipoAlerta`, Dashboard UseCase+DTO, Detalhe UseCase+DTO, templates dashboard/caso-show/_acoes_modais, purga); ajustados 8 testes; renomeado `AcaoRevisao…`→`AcaoMutacaoControllerTest`.
- **PRESERVADO de propósito:** `TipoEventoHistorico::RevisaoVinculo` (há eventos `revisao_vinculo` gravados; removê-lo quebra hidratação) — marcado como legado no enum. **NÃO reintroduzir a remoção dele.**
- **Migration `Version20260713120000`** (`DROP TABLE IF EXISTS`; down recria) **JÁ APLICADA NO DEV** (a tabela tinha 1 revisão resolvida). Em prod ainda NÃO.
- **Smoke no navegador FEITO (dev):** card "Revisões" sumiu do dashboard (`/cobrancas/painel`); botão/banner/modais de Revisão sumiram da página do caso; páginas abrem OK; `cache:clear` sem erro de DI.
- **FALTA (aguardando aval do humano — já mostrei o resultado):** `php -d memory_limit=512M bin/phpunit tests/Cobranca` + suíte global → `/review` → corrigir → **commit atômico do item 4**.
- **Deploy (futuro):** o `DROP TABLE` em prod só é seguro com `SELECT count(*) FROM cobranca_revisao_pessoa_cobrada` = 0 (ou só resolvidas) — confirmar com o humano antes.

### Itens seguintes (ideias fechadas no backlog; ainda não iniciados)
**3** tentativa→registro de contato (canal Tel/Whats/Email/SMS + data default agora + resultado) · **5** editar+excluir obrigação (guardas+auditoria) · **6** pagamento auto-alocação FIFO + manual opcional + split dívida/honorários ao vivo · **7** acordo inteligente (total negociável+entrada+periodicidade+recálculo ao vivo+abrir/editar; **SPEC própria**) · **8** parcelas na aba Obrigações (dropdown+link) · **2** objeto=caso na UI (esconder "caso", página do objeto, cards de objeto; **SPEC**; premissa a validar: Modo Único).

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
