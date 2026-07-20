# Spec — Encargos "ao vivo" (sem cron, sem congelamento manual)

> **Data:** 2026-07-20 · **Risco:** ALTO (dinheiro no cálculo + reversão de arquitetura) · **Branch alvo:** `cobranca-encargos-cascata`
> **Origem:** feedback do dono no teste — o **cron** de atualização e o **congelamento** confundem. Ele quer o
> comportamento de uma **planilha de Excel**: o encargo é sempre `(vencimento → hoje) × taxa`, calculado na hora.
> **Substitui a decisão de arquitetura "materializado + cron"** da spec `cobranca-encargos-configuraveis-cascata.md`
> (§6/§13, INV-E3). A **fórmula não muda** — só *quando* ela roda.

## 1. Motivação

Hoje os encargos são **materializados** (guardados em colunas) e um **cron** noturno reatualiza as obrigações
"não congeladas". Para proteger valores digitados/importados/pagos, existe o **congelamento** (`encargosCongeladosEm`),
setado ao editar, registrar (com valor à mão), importar e na migração do legado. Isso trouxe: a bomba F2, o freio de
redução, o "digitou → congela" (Ajustes 1 e 2) e um modelo mental difícil.

O dono quer o oposto: **nada guardado para obrigação em aberto**; o valor é **calculado na hora**, sempre, de
`vencimento` até `hoje`. Editar não congela — muda a taxa e segue calculando. O relógio só para quando a dívida é
**paga** ou **substituída por acordo**.

## 2. Prova empírica (por que a fórmula NÃO muda)

Comparação dos **mesmos boletos** em duas datas reais (08/07 → 20/07, +12 dias), duas carteiras:

| | Multa mudou nos 12 dias | Juros mudou | Fórmula atual reproduz a planilha NOVA |
|---|---|---|---|
| **TOPLIFE I** (2719 casados) | **0 / 1964** | 1964 / 1964 | juros 1963/1964 · multa 1964/1964 · honor 1964/1964 · **total 1963/1964** |
| **TOPLIFE II** (416 casados) | **0 / 412** | 412 / 412 | **412/412 em tudo (100%)** |

**Conclusão comprovada:** só o **juros** cresce (pró-rata diária, `dias/30`, meio-para-baixo). **Multa é fixa** (2%
do principal, uma vez). **Correção = 0**. **Honorários** = 20% (I) / 15% (II) sobre a base composta após ~30 dias,
crescendo só porque a base (juros) cresce. O único caso de 1964 é um empate de meio centavo já documentado.
→ O motor `CalculadoraEncargos` **já produz exatamente isto** e é mantido intacto. (Script de verificação e as 4
planilhas ficam em `docs/gestao-cobrancas/` — PII, gitignored.)

## 3. Decisões do dono (todas confirmadas)

- **D1.** Cálculo **ao vivo, nada guardado** para obrigação em aberto (avulsa OU parcela de acordo).
- **D2.** Relógio **para só ao liquidar**: dívida **paga** (quita) congela na **data do pagamento**; **substituída
  por acordo** congela na **data do acordo**.
- **D3.** **Editar NÃO congela.** Mudar valor/vencimento/taxa recalcula com os novos inputs e segue vivo. Digitar
  um valor de encargo **deriva a % mensal** daquele valor e segue calculando (o valor volta a crescer com o tempo).
- **D4.** **Parcela de acordo cresce** se atrasar (é obrigação viva como qualquer outra).
- **D5.** **Fórmula mantida** (a provada no §2): juros cresce, multa fixa, correção 0, honorários sobre a composta.
- **D6.** **Sai o cron** (`app:cobranca:atualizar-encargos`) e **sai o congelamento manual** (edição/registro/import).

## 4. Modelo — três estados

| Estado | Quando | Encargo | Cresce? |
|---|---|---|---|
| **Viva** | em aberto (avulsa ou parcela de acordo), com saldo a receber | `CalculadoraEncargos.calcular(P, vencimento, config, hoje)` | **sim, ao vivo** |
| **Liquidada** | `alocado ≥ exigível` no momento do pagamento (quitada) | snapshot na **data da liquidação** (`liquidadaEm`) | não |
| **Substituída** | trocada pelas parcelas de um acordo vigente | snapshot na **data do acordo** | não |

- **Pagamento parcial** mantém a obrigação **Viva**: juros seguem sobre o **principal original** (INV-E1), e
  `restante = exigível vivo − alocado` (cresce). Só a quitação total liquida.
- **Corrigir pagamento** que reabre uma Liquidada (alocado volta a ser < exigível) **descongela**: `liquidadaEm = null`
  e a obrigação volta a Viva.
- A obrigação **Substituída** já sai do total em aberto (`doCasoExigiveis` a exclui) — o snapshot é só histórico.

## 5. Fórmula e data de referência

Fórmula **inalterada** (spec cascata §3, provada no §2). O que muda é a **data de referência** por obrigação:

- **Viva** → `hoje` (injetado via `ClockInterface`, nunca `new \DateTimeImmutable()` no caminho do dinheiro).
- **Liquidada** → `liquidadaEm` (determinístico, não cresce).
- **Substituída** → `acordo.dataAcordo`.

Half-down do juros e half-up de multa/correção/honorários seguem como estão.

## 6. Arquitetura

### 6.1 Serviço de hidratação (`EncargosVivos`)

Novo serviço de domínio, puro-ish (sem persistir):

```
EncargosVivos(
    ClockInterface $clock,
    ResolvedorConfigEncargos $resolvedor,
    CalculadoraEncargos $calculadora,
)

// Preenche EM MEMÓRIA os encargos de cada obrigação para a data de referência do seu estado.
// NÃO faz flush. Config resolvida 1× por caso (evita N+1 — override é por-caso hoje).
hidratarCaso(CasoCobranca $caso, iterable<Obrigacao> $obrigacoes): void
```

- Para cada obrigação **Viva**: `calcular(P, vencimento, config, clock.now())` → `definirEncargos(...)` **em memória**.
- **Liquidada / Substituída**: não toca (os valores já estão no snapshot persistido).
- Config: `resolverDoCaso(caso)` uma vez; overrides por-obrigação (honorário, taxa digitada — ver §7) aplicados por cima.

### 6.2 Ponto único de carregamento (`CarregadorObrigacoesVivas`)

Envolve `ObrigacaoRepository` + `EncargosVivos`: todo caminho que hoje lê obrigações para exibir/somar passa a
carregar **já hidratado**. Consumidores que migram (todos em PHP, nunca SQL sobre encargos):

- `MontarDetalheCasoUseCase` (tela do objeto/caso) · `MontarDetalheAcordoUseCase` (acordo)
- `CalculadoraSaldo` (saldo) · `AutoAlocadorFifo` (alocação de pagamento)
- `MontarDashboardCobrancaUseCase` (dashboard) · `AcordoCriarType` (remanescente no form)
- `ObrigacaoOutput.fromEntity` (DTO de exibição — lê os getters já hidratados)

`valorExigivel()` **não muda de assinatura** — passa a ler os valores hidratados em memória. Consistência garantida:
exibição, saldo e alocação leem os **mesmos** números vivos dentro da requisição.

### 6.3 Snapshot na liquidação (o único "congelar", automático)

- `RegistrarPagamentoUseCase`: ao alocar um pagamento que leva `alocado ≥ exigível vivo`, **materializa** os encargos
  na data do pagamento (`definirEncargos(..., dataPagamento)` + `liquidadaEm = dataPagamento`) e **persiste**. A partir
  daí a obrigação é Liquidada (não recalcula).
- `CriarAcordoUseCase`: ao substituir obrigações, materializa cada substituída na `dataAcordo` + marca o snapshot.
- `CorrigirPagamentoUseCase`: se a correção reabre a obrigação, limpa `liquidadaEm` (volta a Viva).

### 6.4 O que é removido

- Command `AtualizarEncargosCommand` + seu agendamento (cron). *(Manter o arquivo por 1 fase como no-op/deprecado
  para não quebrar o schedule; remover na fase final.)*
- Congelamento em `EditarObrigacaoUseCase`, `RegistrarObrigacaoUseCase`, `ImportarRelatorioCarteiraUseCase` (o
  "digitou/importou → congela" dos Ajustes 1, 2 e da F2).
- `ReducaoDeEncargosBloqueadaException` / freio de redução (era proteção do cron).

### 6.5 Colunas guardadas — destino

- **Viva:** as colunas `juros/multa/correcao/honorarios/encargosAtualizadosEm` viram **cache ignorado** (sobrescritas
  em memória no load, nunca flushed). *(Opcional: zerá-las numa migração de limpeza — não é pré-requisito.)*
- **Liquidada/Substituída:** as colunas guardam o **snapshot** definitivo. `encargosCongeladosEm` é **reaproveitado**
  como marcador do estado congelado (setado só automaticamente na liquidação/substituição).

## 7. Override manual (D3) — "editar muda a taxa, não congela"

O caminho de edição de encargo deixa de **congelar** e passa a **ajustar a taxa** da obrigação, mantendo o cálculo vivo:

- Digitar um **valor** de juros → deriva a **% mensal** implícita (`taxa = valor·30 / (P·dias)`) e guarda a **taxa**
  (não o valor). O valor volta a ser derivado a cada dia (cresce). `CalculadoraEncargos::taxaDeValor` já existe para
  o caso flat (multa/honorário); o juros precisa da variante com `dias`.
- Multa/honorário digitados → taxa flat via `taxaDeValor` (já existe).
- **Nenhuma edição seta `encargosCongeladosEm`.** A obrigação continua Viva.

> **Ponto de produto a confirmar na revisão da spec:** guardar a **taxa por-obrigação** (override) exige um campo de
> taxa na obrigação (hoje a config é por-caso). Alternativa mais simples p/ a 1ª entrega: edição de encargo **só** via
> a taxa do **caso/carteira** (sem override por-obrigação), e o override por-obrigação fica como follow-up. Ver §11.

## 8. Invariantes (revisadas)

- **INV-V1.** Obrigação Viva: `exigível = P + juros(hoje) + multa + correcao`, com juros/multa/correcao vivos. Nada de
  encargo de Viva é persistido.
- **INV-V2.** Liquidada/Substituída nunca recalculam: leem o snapshot na sua data de corte.
- **INV-V3 (reverte INV-E3).** Saldo/exigível **são função de `hoje`** (via hidratação). `hoje` é sempre injetado
  (`ClockInterface`) — determinístico e testável.
- **INV-V4.** `alocado` e a fórmula do exigível seguem por **valor total** (não por categoria) — INV-E1/spec cascata.
- **INV-V5.** A fórmula viva reproduz a planilha real **ao centavo** (§2) — reprovada por verificação independente a
  cada mudança no motor (não deve mudar).

## 9. Verificação e testes

- **Determinismo:** `ClockInterface` com relógio fixo nos testes → `hoje` estável. Sem isso, um teste "ao vivo"
  oscila com o dia da execução.
- **Prova ao centavo:** re-rodar o script do §2 contra a planilha NOVA após a implementação (a fórmula não muda, mas
  o *caminho vivo* precisa dar o mesmo número). Alvo: TOPLIFE II 412/412, TOPLIFE I ≥ 1963/1964.
- **Reescrita de testes (grande):** muitos testes hoje semeiam encargos guardados (`definirEncargos(...)`) e afirmam
  esses valores. No modelo vivo, o valor exibido vem de `vencimento + config + hoje`. Cada teste desses migra para:
  (a) semear `vencimento`/config que **produzam** o valor esperado sob um relógio fixo, ou (b) testar Liquidada
  (snapshot). Estimar e isolar por arquivo. Suíte-alvo: `tests/Cobranca` verde + global verde.
- **Suítes sensíveis a reconferir:** saldo (`CalculadoraSaldoTest`), FIFO (`AutoAlocadorFifoTest`), acordo
  (`CriarAcordo*`, `MontarDetalheAcordo*`), dashboard (`MontarDashboard*`), controller do objeto
  (`ObjetoShowControllerTest`), pagamento (`RegistrarPagamento*`, `CorrigirPagamento*`).

## 10. Riscos e mitigações

1. **Config vira load-bearing (bomba F2 ressurge no display).** Sem valor guardado de rede, taxa errada = número
   errado na tela e no saldo. **Mitigação:** configurar as carteiras (juros 1%, multa 2%, honorários 20%/15%,
   carência 30) é **pré-requisito** de teste e de deploy; adicionar um smoke/guard que sinalize carteira sem taxa.
2. **Performance do dashboard/saldo.** Hidratar ao vivo percorre todas as obrigações exigíveis do tenant.
   **Mitigação:** config resolvida 1× por caso; aritmética inteira barata por obrigação; `alocado` já vem batelado
   (`somasPorObrigacaoDosCasos`). Validar no dataset real (~3.294 obrigações) antes do go-live.
3. **Reescrita de testes ampla.** **Mitigação:** `ClockInterface`, migração por arquivo, fases.
4. **Reabertura de Liquidada por correção de pagamento.** **Mitigação:** `CorrigirPagamentoUseCase` limpa
   `liquidadaEm` explicitamente; teste dedicado (paga → corrige p/ menor → volta a crescer).
5. **Meia-migração gera divergência exibição×saldo.** **Mitigação:** a fase que troca os caminhos de leitura troca
   **todos juntos** (§12, F2). Nunca exibição viva com saldo guardado.

## 11. Fora de escopo / decisões de produto pendentes

- **Override de taxa por-obrigação (§7).** 1ª entrega pode restringir edição de encargo à taxa do caso/carteira;
  override por-obrigação (campo de taxa na obrigação) fica como follow-up **se** o dono confirmar que precisa editar
  encargo de UMA obrigação isolada sem mexer no caso. **Confirmar na revisão.**
- **Correção monetária por índice** (IGP-M/IPCA): segue fora — correção continua taxa configurável (default 0).
- **Limpeza das colunas de Viva** (migração que zera o cache): opcional, não bloqueia.

## 12. Fases de implementação (detalhe vai para o plano — writing-plans)

- **F1 — Infra (sem mudança de comportamento).** `ClockInterface` no motor/serviços; `EncargosVivos` +
  `CarregadorObrigacoesVivas`; testes do serviço com relógio fixo. Nada troca de caminho ainda.
- **F2 — Virar a leitura para o vivo (a fase grande).** Todos os consumidores (§6.2) passam a ler obrigações
  hidratadas — **juntos**, para exibição e saldo baterem. Reescrever os testes afetados. Prova ao centavo (§9).
- **F3 — Snapshot na liquidação.** `liquidadaEm` + materialização automática em pagamento total e em acordo;
  reabertura em corrigir pagamento. Testes de estado.
- **F4 — Remover o velho.** Cron (schedule + command), congelamento manual (edição/registro/import), freio de
  redução. Ajustar/remover testes desses caminhos.
- **F5 — Verificação final.** Prova ao centavo contra a planilha NOVA; `tests/Cobranca` + global verdes; smoke real
  (objeto 117 e um caso com pagamento/acordo) em claro/escuro.

Cada fase: implementar → `feature-review-agent` (contra esta spec) → corrigir → testes direcionados → estabilizar.
Nada de push/merge/deploy (do humano). Carteiras configuradas antes de qualquer smoke que valide números.
