# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-10 — **Etapa 9 CONCLUÍDA** (Dashboard + Central de Alertas). Com isso as **Etapas 0–9 estão COMPLETAS**: a implementação da feature Gestão de Cobranças está FECHADA. Só resta o **preparo de deploy/homologação** (não bloqueia; decisão do humano).

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD:** `3cd426a`. Cadeia da Etapa 9 sobre `790fe95`: `c80b4e5` (spec) → `3cd426a` (implementação).
- **Etapa:** **9 ✅ COMPLETA. Etapas 0–9 COMPLETAS.** Próximo = **preparo de deploy** (ver abaixo), não mais implementação.
- **Suíte:** `tests/Cobranca` **398/398**; GLOBAL **1679/1679** (medido no HEAD `3cd426a`).
- **Working tree:** limpo após o commit de docs (untracked só `.claude/worktrees/` + os `.xlsx` reais TOPLIFE gitignorados).
- **Migrations:** **nenhuma nas Etapas 8–9** (só camada HTTP/visual). A ÚNICA pendência de banco p/ prod é a data-migration de permissões `cobrancas` (item de deploy, abaixo).

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
