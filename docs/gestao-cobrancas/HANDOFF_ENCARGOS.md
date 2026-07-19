# Handoff — Encargos separados e configuráveis em cascata (Cobrança)

> Feature NOVA (pós-Ajuste 10). Risco **ALTO** (dinheiro no cálculo + migração). Módulo **em produção**.
> **Nada implementado ainda** — este chat só PREPAROU: spec + docs + memória + prompt de arranque.
> Fonte de verdade do *o quê/porquê*: **`docs/specs/cobranca-encargos-configuraveis-cascata.md`**.
> Prompt para o chat de implementação: **`docs/gestao-cobrancas/NEW_CHAT_PROMPT_ENCARGOS.md`**.

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
- Ledger da feature: criar `.superpowers/sdd/progress-encargos.md` no chat de implementação (tarefa COMPLETE não se refaz).
- Memória: `project_cobranca_encargos.md` (índice em `MEMORY.md`).
