# PLANO — Otimização de queries (N+1) em TODAS as páginas de Cobrança

> Pendência aberta para outro chat continuar. Objetivo: eliminar o N+1 de leitura em todas as telas do
> módulo `App\Cobranca`, reutilizando o padrão **batch-load** já aplicado no Dashboard (commit `2a9315c`).
> **A regra de saldo/alerta NÃO muda** — só a forma de carregar os dados (uma vez, em lote, e computar em
> memória). Criado em 2026-07-10 após o fix do Dashboard.

## 0. Contexto — o que já foi feito (Dashboard, referência)

O Dashboard (`MontarDashboardCobrancaUseCase`) iterava ~194 casos chamando os serviços por-caso
(~14 queries/caso ≈ **2731 queries**, 1,26 s de SQL). Foi reescrito para carregar tudo do tenant em lote
(~7 queries) e computar em memória. Medido no dev: **2731 → ~7 queries do dashboard**, 1986 ms → 336 ms,
54 → 18 MiB. Números idênticos; revisão adversarial SEM bloqueante; teste de consistência batch==per-caso.

**Infra REUTILIZÁVEL já criada** (usar nas próximas páginas — NÃO recriar):
- `CalculadoraSaldo::derivarSaldos(exigiveis[], alocadoPorObrigacao, totalLiquidado, hoje): {exigivel, vencido}`
  — núcleo PURO da regra de saldo (mesma dos métodos por-caso, que ficaram intactos).
- `ObrigacaoRepository::exigiveisDosCasos(casoIds, tenant): Obrigacao[]` — filtro de exigível idêntico a `doCasoExigiveis`, em lote.
- `AlocacaoPagamentoRepository::somasPorObrigacaoDosCasos(casoIds, tenant): array<obrigacaoId,int>`.
- `LiquidacaoRepository::dosCasos(casoIds, tenant): Liquidacao[]`.
- `PagamentoRepository::dosCasos(casoIds, tenant): Pagamento[]`.
- `ProximaAcaoRepository::ativasDosCasos(casoIds, tenant): array<casoId, ProximaAcao>`.
- `RevisaoPessoaCobradaRepository::casoIdsComPendente(casoIds, tenant): int[]`.

**Limite conhecido:** as cargas usam `caso IN (:casoIds)` — teto de bind params do Postgres (~65535) só
com dezenas de milhares de casos; ponto de evolução (chunk / materialização), fora do MVP.

## 1. Inventário do N+1 por página (medir sempre no profiler antes/depois)

| Página / UseCase | Rota | Padrão do N+1 | Escala | Prioridade |
|---|---|---|---|---|
| **Dashboard** `MontarDashboardCobrancaUseCase` | `/cobrancas/painel` | ✅ **RESOLVIDO** (batch) | tenant | — |
| **Central de Alertas** `MontarCentralAlertasUseCase` | `/cobrancas/alertas` | `alertasDoCaso` por caso (~6 q/caso) | **tenant inteiro** | **P1 (maior)** |
| **Visão da Carteira** `MontarVisaoCarteiraUseCase` | `/cobrancas/carteiras/{id}` | loop `daCarteira` chamando `saldoExigivel`+`saldoVencido` por caso | por carteira (ex.: TOPLIFE I = 81 casos → ~486 q) | **P2** |
| **Lista de Casos** `ListarCasosUseCase` | `/cobrancas/casos` | saldo por caso da página **+** hidratação lazy de `objeto`/`pessoa`/`carteira` no `CasoResumoOutput` | página (20 casos → ~180 q) | **P3** |
| **Lista de Carteiras** `ListarCarteirasUseCase` | `/cobrancas` | `CarteiraResumoOutput` acessa `cliente->getNomeExibicao()` (proxy PF/PJ) → 1–2 q/carteira | página (20) | **P4 (menor)** |
| **Detalhe do Caso** `MontarDetalheCasoUseCase` | `/cobrancas/casos/{id}` | 1 caso (sem N+1 de lista); `saldoExigivel` recalculado dentro de `alertasDoCaso` (redundância) | 1 caso | **P4 (menor, dedupe)** |
| Importação preview | `/carteiras/{id}/importar/prever` | lê arquivo (não é N+1 de DB) | — | — |

### 1.1 Medições reais (profiler do dev, tenant 1 = TOPLIFE I+II: 2 carteiras / 194 casos / 3270 obrigações / 0 encerrados)

> Baseline capturado em 2026-07-11 no navegador (super-admin farlei), contador "Database Queries" da toolbar.
> A coluna DEPOIS é preenchida ao fim de cada fase.

| Página | Rota | ANTES | DEPOIS | Fase |
|---|---|---:|---:|---|
| Dashboard | `/cobrancas/painel` | 42 | **42** (sem regressão após P0) | ✅ (já era batch) |
| Central de Alertas | `/cobrancas/alertas` | **1592** | — | P1 |
| Visão da Carteira (TOPLIFE I, 81 casos) | `/cobrancas/carteiras/1` | **876** | — | P2 |
| Lista de Casos (20/página) | `/cobrancas/casos` | **199** | — | P3 |
| Lista de Carteiras (2) | `/cobrancas` | 40 | — | P4 |
| Detalhe do Caso | `/cobrancas/casos/101` | 96 | — | P4 (dedupe) |

## 2. Passo P0 — extrair primitivas batch reutilizáveis (fazer PRIMEIRO)

> **✅ P0.1 + P0.2 CONCLUÍDOS (2026-07-11).** Extraídas as primitivas de saldo/alerta e o Dashboard foi
> refatorado para reusar `derivarSaldosDosCasos` (dedup da orquestração inline) SEM regressão de query
> (42→42). Teste de equivalência `CobrancaBatchConsistenciaTest` (saldo + alertas batem caso-a-caso com os
> métodos por-caso, cenário com alocação+liquidação). Revisão adversarial SEM bloqueante. `tests/Cobranca`
> 404/404. **P0.3 (fetch-joins nas listagens) foi movido para as fases que os consomem (P1/P2/P3/P4)** — cada
> fetch-join só tem efeito verificável na página que hidrata o DTO correspondente, então é aplicado e medido
> lá. Achado MENOR aceito: `alertasDosCasos` recarrega `exigiveis` ao chamar `saldosDosCasos` (O(1), sem
> N+1) — reavaliar a fiação na P1.

Hoje a orquestração batch está **inline** no `MontarDashboardCobrancaUseCase`. Antes de replicar, extrair
para serviços, para que Alertas/Carteira/Caso reusem sem duplicar:

1. **`CalculadoraSaldo::saldosDosCasos(CasoCobranca[] $casos, Tenant $tenant, ?\DateTimeImmutable $hoje = null): array<int, array{exigivel:int, vencido:int}>`**
   - Carrega `exigiveisDosCasos` + `somasPorObrigacaoDosCasos` + `dosCasos` (liquidações) para os ids dos casos,
     agrupa e chama `derivarSaldos` por caso. Devolve mapa `casoId => {exigivel, vencido}`.
   - Refatorar o Dashboard para usar isto (dedup da lógica inline) — mantendo o teste de consistência verde.
2. **`AlertasCobranca::alertasDosCasos(CasoCobranca[] $casos, Tenant $tenant, ?\DateTimeImmutable $hoje = null): array<int, AlertaCobranca[]>`**
   - Versão em lote de `alertasDoCaso`: carrega exigíveis + ações pendentes (`ativasDosCasos`) + revisões
     (`casoIdsComPendente`) + saldos (via `saldosDosCasos`) e deriva os 5 alertas por caso — **mesma regra**
     de `alertasDoCaso` (que fica intacto para o detalhe do caso). Encerrado → `[]`.
3. **Fetch-join nas listagens** (mata a hidratação lazy, não o saldo): em `CasoCobrancaRepository::findByFilters`
   e `daCarteira`, trocar os `innerJoin` de `objeto`/`pessoaCobradaAtual` por **`addSelect`** (fetch join) e
   `leftJoin+addSelect` de `objeto.carteira`; em `CarteiraRepository::findByFilters`, fetch-join do `cliente`
   (+ subtipo PF/PJ se a herança exigir). Assim `CasoResumoOutput`/`CarteiraResumoOutput` não disparam query.

> Invariante de qualidade (todas as fases): **nenhuma regra de saldo/alerta duplicada** — as primitivas
> reusam `derivarSaldos`/os filtros de exigível; toda página que trocar per-caso→lote ganha um **teste de
> consistência** (batch == Σ per-caso, com alocação+liquidação num caso ativo) espelhando o do Dashboard.

## 3. Fases (uma por página; cada uma = implementar → testar → medir → revisar → commit)

### P1 — Central de Alertas (`MontarCentralAlertasUseCase`) — MAIOR GANHO
- Trocar o loop `alertasDoCaso` por `AlertasCobranca::alertasDosCasos($casos, $tenant, $hoje)`.
- Agrupar por carteira + montar `resumoPorTipo` a partir do mapa (sem novas queries).
- Cuidado: o nome da carteira por caso vem de `caso.getObjeto().getCarteira().getNome()` (proxy) → fetch-join
  `objeto`+`carteira` no `CasoCobrancaRepository::doTenant` para não reintroduzir N+1 de hidratação.
- Teste: consistência (mesma lista de casos-com-alerta e `resumoPorTipo` do caminho per-caso).

### P2 — Visão da Carteira (`MontarVisaoCarteiraUseCase`)
- Trocar o loop por `CalculadoraSaldo::saldosDosCasos($casos, $tenant)`; `saldoConsolidado` = Σ exigível do mapa.
- `daCarteira` com fetch-join de `objeto`/`pessoa` (P0.3) para o `CasoResumoOutput`.
- Teste: `saldoConsolidado` e a lista de saldos batem com o per-caso.

### P3 — Lista de Casos (`ListarCasosUseCase`)
- Trocar o loop por `saldosDosCasos` sobre os casos da PÁGINA (o `tenant` já é fixo).
- `findByFilters` com fetch-join (P0.3) — mata a hidratação lazy de objeto/pessoa/carteira.
- Teste: os saldos da página batem com o per-caso; paginação/ordenção/facetas intactas.

### P4 — Menores
- **Carteira index** (`ListarCarteirasUseCase` / `CarteiraRepository::findByFilters`): fetch-join do `cliente`.
- **Detalhe do Caso** (`MontarDetalheCasoUseCase`): calcular `saldoExigivel` uma vez e injetar em `alertasDoCaso`
  (evitar o recálculo interno) — requer uma sobrecarga de `alertasDoCaso` que aceite o saldo já calculado, OU
  aceitar como está (1 caso só; ganho pequeno). Avaliar custo/benefício.

## 4. Protocolo por fase (obrigatório)
1. Medir queries ANTES no profiler do dev (com os dados TOPLIFE já semeados no tenant 1 — ver RELEASE_CHECKLIST §4.5).
2. Implementar reusando as primitivas (P0); **não** duplicar regra de saldo/alerta.
3. **Teste de consistência** batch==per-caso (com alocação+liquidação em caso ativo) + manter os testes de número exato.
4. `tests/Cobranca` verde + suíte global verde.
5. Medir DEPOIS no profiler (registrar o antes/depois no doc).
6. Revisão adversarial read-only (`feature-review-agent`) — foco: equivalência semântica + tenant-safety + lazy-load escondido.
7. Commit isolado por fase.

## 5. Riscos / invariantes
- **Multi-tenant:** todos os métodos de repo em lote filtram `tenant` explícito; os `casoIds` vêm de queries já tenant-scoped.
- **Sem duplicar regra** (SPEC): saldo/alerta continuam derivados pelos serviços; o batch só muda a fonte dos dados.
- **Métodos por-caso intactos** (detalhe do caso depende deles) — o batch é aditivo.
- **Lazy-load escondido:** ao montar Output DTOs a partir de entidades, checar se algum getter de associação
  (objeto/pessoa/carteira/cliente/acordoOrigem) dispara query; usar fetch-join ou `getId()` no proxy.
- **`IN (:casoIds)`:** teto ~65535 params; hoje irrelevante, registrar se algum tenant crescer muito.

## 6. Definição de pronto (feature toda)
Todas as telas de Cobrança com contagem de queries O(1)+O(#datasets) por request (não O(#casos)), com testes de
consistência, medições antes/depois registradas aqui, e revisão SEM bloqueante por fase. Ao concluir, marcar o
follow-up de perf como 100% resolvido no `EXECUTION_STATUS.md`/`RELEASE_CHECKLIST.md`.
