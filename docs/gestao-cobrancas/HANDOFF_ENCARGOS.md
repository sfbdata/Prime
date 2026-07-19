# Handoff — Encargos separados e configuráveis em cascata (Cobrança)

> Feature NOVA (pós-Ajuste 10). Risco **ALTO** (dinheiro no cálculo + migração). Módulo **em produção**.
> Fonte de verdade do *o quê/porquê*: **`docs/specs/cobranca-encargos-configuraveis-cascata.md`**.
> Estado vivo da execução (ledger, gitignored): **`.superpowers/sdd/progress-encargos.md`**.

## ⏱️ Estado atual (2026-07-19) — 🎉 FEATURE COMPLETA (F1→F6)

**Todas as 6 fases ENTREGUES E PROVADAS** na branch local **`cobranca-encargos-cascata`** (de `master`
`d19f652`). **Nada publicado** — push/merge/deploy seguem sendo do humano.
**Checklist de go-live: `docs/gestao-cobrancas/GO_LIVE_ENCARGOS.md`.**

| Commit | O quê |
|---|---|
| `93ce9e7` `ee51f92` | F1 — motor + colunas + resolvedor + migração (+ achados da revisão) |
| `2112e3e` `40d82e7` `2e7097f` | F2 — config na carteira + snapshot no caso + importador (+ migração que desarma a bomba do cron) |
| `b008b4d` `e14d638` `d805332` | F3 — cron de crescimento (+ alinhar critério de exigibilidade + achados da revisão) |
| `64b4961` `81bfcab` `f3d9dd9` `64ff658` | F4 — UI: colunas do PDF + %↔R$ + correções de layout/Total + achados da revisão |
| `83a08b2` | F5 — editar obrigação paga + aviso de reconciliação, com provas |

- `tests/Cobranca` **764/764** (baseline era 611) · **global 2128/2128**. Zero regressões.
- Migrações `Version20260719120000` e `Version20260719140000` aplicadas em **dev**.
- **Verificação independente da fórmula (F6): 4.317/4.317 linhas reais TOPLIFE ao centavo**, half-down do
  juros confirmado com 555 empates.
- Smoke visual real em todas as fases com UI (tela do objeto em 3 larguras, modais, aviso de reconciliação).

### ⚠️ Bomba do cron encontrada e DESARMADA na F2
Havia **3.271 obrigações não congeladas com R$ 155.209,73 em encargos**, e as carteiras estavam com taxa
**0**. Quando a F3 ligasse o cron (que recalcula as **não congeladas**), ele teria recalculado tudo com
taxa zero e **apagado esses R$ 155 mil**. A migração `Version20260719140000` congela o legado com encargos
> 0. Verificado: **0 em risco, 3.271 congeladas, soma idêntica**. A F3 ainda acrescentou um **freio de
redução** (`ReducaoDeEncargosBloqueadaException`): o cron nunca grava encargo menor sem `--permitir-reducao`.

### Duas pendências humanas antes do deploy (checklist §4)
1. **Confirmar o corte da carência de honorários** (`d > 30`) com a contabilidade — reproduz 100% dos
   dados, mas o boundary 29–32 não é observável (nenhuma obrigação real caiu nele). Só afeta futuras.
2. **Configurar as carteiras TOPLIFE em produção** (I: honorários 20% · II: 15%; ambas juros 1%/multa 2%/
   carência 30). Fórmula certa + config errada = valor errado. As importadas nascem congeladas, então não
   afeta o histórico; o risco é prospectivo.

## Em uma frase
Separar `encargosReconhecidos` (número único) em **juros / multa / correção** (+ honorários), com **%↔R$
editáveis**, **crescimento no tempo** (juros pró-rata diária), **cascata de config em 3 níveis**
(Carteira → Objeto/Caso → Obrigação), telas no estilo do PDF da contabilidade, e edição de obrigação/recebido
já pagos com histórico — tudo **configurável** (SaaS).

## O que já foi feito neste chat (preparação)
- **Verificação independente da fórmula** (subagente sem viés) contra **4.413 linhas reais** TOPLIFE I/II →
  **100% ao centavo**. Resultado autoritativo embutido na spec (§3 e Apêndice A).
- **Mapa do código** (subagente) dos pontos que a feature toca — resumido na spec (§4-§11).
- **Spec completa** escrita e commitada (risco ALTO → spec obrigatória).

## ⚠️ 3 correções que a verificação trouxe (o dono precisa confirmar na revisão)
A premissa do brainstorm estava **parcialmente errada**; os dados reais mostram:
1. **Multa = 2% do principal PURO** (fixa, não cresce) — não sobre principal+juros.
2. **Correção = 0** nesta operação (fica configurável, default 0).
3. Carência de **~30 dias** é **só de honorários**; juros/multa valem do **1º dia**.
→ A opção "Fixa/Progressiva" continua existindo (é SaaS), mas o **default TOPLIFE** segue os dados. Ver spec §2/§13.

## Fórmula autoritativa (default TOPLIFE, tudo configurável) — spec §3
- `juros = round_half_down(P * 1% * dias/30)` (simples, pró-rata dia, base principal; 0 se dias=0)
- `multa = round_half_up(P * 2%)` (base principal puro; 0 se dias=0)
- `correcao = 0` (default; base configurável)
- `honorarios = round_half_up((P+juros+multa+correcao) * taxaHon)` se dias>30, senão 0; taxaHon **20% (I) / 15% (II)** — por carteira
- `total = P + juros + multa + correcao + honorarios`

## Decisões de arquitetura já tomadas (spec §6/§13) — reversíveis na revisão
- **Materializado + cron** (não derivado on-the-fly): `valorExigivel()` continua **puro** (soma dos 3) → saldo/
  Dashboard/FIFO/Acordo **não mudam**. Cron diário recalcula as **não congeladas**; obrigação editada/paga/
  importada **congela** e para de crescer.
- Honorários **reusam** `FormaHonorarios`/`percentualHonorarios` (base composta + carência), **fora** do exigível.
- Rastreio de pagamento por **valor total** (não por categoria) — YAGNI.

## Fases (spec §14) — cada uma: implementar (feature-implementer/worktree) → revisar (feature-review-agent) → integrar → testar
1. **F1** Motor `CalculadoraEncargos` + colunas na obrigação + `ResolvedorConfigEncargos` + migração (sem UI). Prova: suíte de saldo intacta (INV-E1).
2. **F2** Config na carteira + cascata (snapshot no Caso, override no Objeto) + import lê correção/split/congela.
3. **F3** Cron `app:cobranca:atualizar-encargos` (só não congeladas; `$hoje` injetável).
4. **F4** UI (colunas do PDF) + %↔R$ no criar/editar + override no objeto + honorários base composta/carência.
5. **F5** Editar pago + reconciliar valor recebido (estende Editar/Corrigir) com histórico.
6. **F6** Verificação independente final contra extrato NOVO (half-down do juros, corte da carência) → só então go-live (humano).

## Invariantes (spec §12) — não afrouxar
- INV-E1 `valorExigivel = valorOriginal + juros + multa + correcao`; a soma **tem** de bater com o antigo `encargosReconhecidos` na migração.
- INV-E3 saldo **não** recebe `$hoje` (crescimento é via cron, não via exigível dinâmico).
- INV-E4 congelada nunca é tocada pelo cron.
- INV-E5 fórmula reprovada por subagente independente contra dados reais **antes do go-live**.

## Ambiente / gotchas (herdados do módulo)
- Tudo no container `jusprime_php_dev`; phpunit/cache pedem `-d memory_limit=512M`.
- Banco de teste `saas_test` = schema:create (não replay). Colunas novas entram no schema; suíte confirma. Nunca `doctrine:schema:update --force`.
- Migrations: colisão de número = **falha silenciosa** → conferir o último Version antes de criar.
- Planilhas reais (PII, gitignored) em `docs/gestao-cobrancas/*.xlsx` e `*.pdf`; ler .xlsx via Python stdlib (zipfile+xml) — script de referência no scratchpad da sessão de preparação.
- Dado de dev/prod atual é de **TESTE** (não real) → backfill por **reimport** é a via limpa (spec §10).
- Git: commit local OK; **push/merge/deploy = humano**. Um piloto de git por vez.

## Estado de verdade viva
- Ledger da feature: `.superpowers/sdd/progress-encargos.md` (gitignored; tarefa COMPLETE não se refaz).
  É lá que estão as decisões D1–D5, os contratos congelados e as provas de cada fase.
- Memória: `project_cobranca_encargos.md` (índice em `MEMORY.md`).

## Achados abertos que a próxima fase precisa considerar

1. **Ordem F3 × F5:** `EditarObrigacaoUseCase` hoje **não congela** a obrigação. Se o cron (F3) entrar
   antes da F5, **ele sobrescreve edição manual**. Ou a F3 já seta `encargosCongeladosEm` na edição, ou a
   F5 vem antes. Decidir na F3.
2. **N+1 na F3:** `ResolvedorConfigEncargos` navega `caso → objeto → carteira` (lazy-load, ~3 queries por
   obrigação). O cron precisa de **join fetch** para não fazer ~10k queries em 3.294 obrigações.
3. **Overflow do regime Composto** já tratado com `EncargosInexequiveisException` — **a F3 deve capturá-la
   POR OBRIGAÇÃO**, para um caso patológico não derrubar a rodada inteira. O teto de 100000 bp do DTO é
   sanidade de entrada, **não** garantia contra overflow (os dias de atraso não têm teto).
4. **Editar obrigação importada DESTRÓI o split** (F4/F5): `EditarObrigacaoUseCase` ainda usa a ponte
   deprecada `setEncargosReconhecidos()`, que joga tudo em `juros` e zera multa/correção. O total
   sobrevive (nenhum centavo se move), mas a informação que a feature existe para preservar some no
   primeiro "corrigir obrigação".
5. **Nível 3 não implementado** (F4): `RegistrarObrigacaoUseCase` não snapshota config na obrigação —
   fica `null` (= herda o Caso, que está congelado, então é determinístico). Spec §4.1 previa o snapshot.
6. **INV-E1 vale condicionalmente:** `novo = antigo + Σ(correção)`. Hoje Σ(K) = 0, medido em
   **4.412/4.412** linhas reais. Se a contabilidade ligar a coluna K, uma reimportação **aumenta o
   `valorExigivel` de obrigações existentes** sem reconciliação nem alerta.
7. **Borda pré-existente (não é regressão):** POST forjado omitindo um `EnumType` mapeia `null` em
   propriedade tipada não-nullable → 500. Vale para os campos novos **e** para `modo`/`formaHonorarios`,
   que já eram assim. O conserto (DTO nullable + `Assert\NotNull`) é transversal — tarefa própria.
8. **Dívida pré-existente (não é da feature):** 3 índices **parciais/funcionais** vivem só nas migrations
   e o `doctrine:schema:create` não os cria → recriar o banco de teste quebra
   `ImportarRelatorioCarteiraTest::testIndiceUnicoBloqueiaObrigacaoDuplicada` até rodar o `CREATE UNIQUE
   INDEX` à mão. Aconteceu nesta sessão. Vale um hook no bootstrap de teste, em tarefa separada.
