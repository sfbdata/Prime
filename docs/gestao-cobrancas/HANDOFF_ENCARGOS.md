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

## 🔨 RODADA PÓS-GO-LIVE (feedback do teste do dono, 2026-07-20)

O dono testou e pediu 4 ajustes. **A branch já tem o Ajuste 1; 2 e 3 são o próximo chat.**

### ✅ Ajuste 1 — Recálculo automático ao criar/editar (FEITO, commit `f33b538`)
Queixa: criou obrigação vencendo em 2026, editou para 2020, os juros não recalcularam. Agora a obrigação tem
dois estados: **automática** (recalcula na criação, na edição e no cron — cresce sozinha, estilo `TODAY()`) e
**travada** (o gestor digitou o valor à mão → fixo). Digitar um encargo trava e o motor completa os honorários
sobre a base digitada. `tests/Cobranca` **772/772**. *(Isto revisou a decisão da F4: agora digitar encargo
CONGELA, com honorários completados — mais coerente.)*

**Decisão sobre correção monetária:** correção real é por índice (IGP-M/IPCA), mas o sistema é de REGISTRO —
a correção fica **manual por obrigação** (o gestor pesquisa e digita no campo que já existe). **Manter a
"Correção (%)" da carteira em 0** (taxa fixa não é correção monetária). Não construir busca de índice.

### ✅ Ajuste 2 — Honorários editáveis no CASO e na OBRIGAÇÃO (FEITO, 2026-07-20)
Spec: `docs/specs/cobranca-encargos-ajuste2-honorarios-cascata.md`. Duas fatias, cada uma
implementer/worktree → revisão adversarial → cherry-pick → testes → SMOKE.

- **Fatia A (`30ec898`+`930bcea`, + fix `ded28bb`):** editar honorários do **caso âncora** (forma/%/base/
  carência) via modal "Editar honorários"; ao salvar, **recalcula na hora SÓ o honorário** das obrigações
  automáticas vivas do caso, deixando o **exigível (juros/multa/correção) INTACTO** — congeladas intactas
  (INV-E4). Revisão fechou 2 achados (validação forma×percentual, autor do evento).
- **Fatia B (`e86140f`):** campo de **honorário na obrigação** (registrar/editar) com par %↔R$ sobre a
  **base resolvida** (composta = valor+juros+multa+correção; ou principal). **Vazio = automático** (motor
  completa/recalcula, como hoje); **digitado = override + congela**. Honorário FORA do exigível (INV-E2).
  Ajuste 1 intacto.
- **Decisão de escopo:** "editar no objeto/caso" = editar o caso âncora (objeto não tem config própria); só
  honorários no caso (juros/multa/correção por caso = follow-up). `tests/Cobranca` **796/796**, global
  **2160/2160**.

**Sharp-edge a confirmar com o dono (não é bug):** ao editar uma obrigação **congelada** mexendo em
juros/multa/correção com o **honorário em branco**, o motor **recompõe** o honorário sobre a nova base
(descarta o honorário fixo anterior). O campo abre vazio; para manter o honorário antigo, o gestor redigita.

### ✅ Ajuste 3 — Card expansível da obrigação (FEITO, 2026-07-20) + faixa de encargos na linha
O dono escolheu **Expansível**. Commits `6db4c3c` + `5ca935f` (redesign) + **`5a3928c`** (correção do dono:
mostrar cada encargo NA LINHA). A linha da dívida virou **card compacto** (data · descrição · Total ·
chevron · ações). O redesign inicial escondia os encargos atrás do chevron; **o dono pediu para VER cada
encargo na linha**, então acrescentei uma **faixa de pílulas coloridas sempre visível** logo abaixo da
descrição: `ORIGINAL · JUROS · MULTA · CORREÇÃO · HONORÁRIOS` (rótulo + R$, cor por encargo, tema-aware,
flui e quebra sozinha — sem colunas apertadas, sem rolagem horizontal em 1600/1024/390). O **chevron/painel**
continua para o "extra" (o **%** de cada encargo, a base de incidência, o selo de congelado/atualizado).
Bug do smoke da 1024px (descrição quebrando letra a letra pela coluna estreita do cockpit) corrigido com
**container query**. `tests/Cobranca` **796/796**, global **2160/2160**. Ganchos de teste e `data-*` dos
modais preservados (a faixa usa `.jp-enc-*`, não `.col-*`, para não colidir com os testes que leem o painel).

### 🎯 Rodada pós-go-live: COMPLETA (Ajustes 1–3) + auditoria adversarial final
Branch `cobranca-encargos-cascata`, HEAD à frente de origin — **push/merge/deploy = humano** (nada publicado).
Todos os 3 ajustes entregues, testados e smoked. `tests/Cobranca` **796/796**, global **2160/2160**.

**Auditoria adversarial final (workflow multi-agente, 32 agentes) do diff inteiro da rodada** — achou e
**corrigiu** o que a revisão por-fatia não pegou:

- 🔴 **BLOQUEANTE corrigido (`ded28bb`) — a Fatia A reabria a bomba da F2.** O recálculo ao editar honorários
  do caso recompunha os QUATRO encargos com `calcular()`; como juros/multa/correção descem da CARTEIRA (o caso
  só snapshota a taxa de honorário), se a taxa da carteira tivesse sido baixada, editar honorários reduziria o
  **exigível** de todas as automáticas do caso num POST, **sem o freio de redução do cron** e **sem guard de
  alocado** (podia ir abaixo do já pago → saldo negativo). **Fix:** o recálculo materializa **só o honorário**,
  deixando juros/multa/correção intactos (quem mexe no exigível, com freio, é só o cron). Provado por 2 testes
  novos (bomba F2 fechada; parcela de acordo/substituída não tocadas).
- 🟠 **IMPORTANTE corrigido (`ec90063`) — card e espelho mentiam para base Principal.** O card derivava o `%`
  de honorário e a nota "sobre a base composta" fixos, e o espelho %↔R$ do honorário convertia sempre sobre a
  composta. Para uma carteira/caso com `baseHonorarios = Principal`, o `%` exibido e a nota ficavam factualmente
  errados, e o espelho convertia um `%` digitado sobre a base errada (entrada de dinheiro incorreta). **Fix:**
  a base resolvida foi exposta (`ObrigacaoOutput`/`CasoDetalheOutput`) e o card + o espelho passam a respeitar
  Principal|Composta. Provado no smoke (caso Principal → "10% · sobre o principal").
- 🟡 **MENOR corrigido (`ec90063`):** o `%` do honorário no espelho ficava stale ao mudar juros/multa/correção
  pelo campo de **%** (atribuição `.value` não dispara `input`) — agora re-sincroniza os demais pares.

**Decisões de PRODUTO pendentes do dono (NÃO são bugs — comportamento da spec):**
1. **Editar obrigação CONGELADA mexendo em j/m/c com o honorário em branco recompõe o honorário** (descarta o
   fixo anterior; o campo abre vazio). Ramo manual do `EditarObrigacaoUseCase` (`honorarios ?? motor`). Para
   manter o honorário antigo, o gestor redigita. Confirmar se é a UX desejada.
2. **Digitar SÓ honorário = 0 (com j/m/c em branco) no registro congela a obrigação inteira em juros zero** —
   não há como pedir "honorário zero, juros automático". É a semântica D-A2-5 ("0 = congela"). Raro; confirmar.
3. **[PRÉ-EXISTENTE, Ajuste 1] `EditarObrigacaoUseCase`, ramo automático:** editar uma automática recompõe
   juros/multa/correção para hoje **sem freio de redução** (só o guard de alocado protege o já pago). Se a taxa
   da carteira caiu, editar (ex.: só a descrição) reduz o exigível daquela obrigação em silêncio. Blast radius
   = 1 obrigação/ação (a Fatia A, em lote, foi o que se corrigiu acima). Decisão unificada do freio no exigível
   dos caminhos manuais fica para o humano — mudar o ramo automático afetaria a feature "editar vencimento
   recalcula" do Ajuste 1, então não se mexeu sem a sua decisão.

### ⏭️ PRÓXIMO CHAT — ajustes visuais do card ainda em aberto (o dono quer continuar afinando)
Estado: HEAD **`5a3928c`** na branch `cobranca-encargos-cascata` (16 commits à frente de origin, **nada
pushado/mergeado/deployado**). `tests/Cobranca` **796/796**, global **2160/2160**, árvore limpa, dev limpo.

O dono aprovou "ver cada encargo na linha" (feito em `5a3928c`), mas o VISUAL ainda é gosto dele — perguntei
e ele quer continuar num chat novo. Itens abertos (só Twig/CSS, risco BAIXO; arquivos:
`app/templates/cobranca/objeto/_partials/_divida.html.twig` = macro `resumoEncargos` + faixa; e
`app/public/css/cobrancas.css` = `.jp-obr-encargos`/`.jp-obr-enc`/`.jp-enc-*` + grid-area `resumo`):

1. **Encargos zerados na faixa:** hoje mostra `MULTA R$ 0,00 · CORREÇÃO R$ 0,00 · HONORÁRIOS R$ 0,00` mesmo
   quando são zero (ruído nas obrigações de condomínio). Opção: mostrar só os encargos > 0 (mantendo Original
   e Total sempre). Perguntar ao dono.
2. **Chevron/painel expansível (`.jp-obr-detalhe` + `.jp-obr-toggle`):** agora que os VALORES estão na linha,
   o painel virou meio redundante (repete valores + mostra o %). Opções: (a) manter como está; (b) remover o
   chevron e jogar o `%` pra dentro de cada pílula da faixa; (c) manter o chevron mas o painel passa a mostrar
   SÓ o extra (% + base + selo), sem repetir os valores. Decisão do dono.
3. **Densidade:** a faixa em toda linha, com 150+ obrigações/objeto, aumenta a altura por linha. Se ficar
   alto demais, deixar a faixa mais compacta (fonte/padding menores) ou opcional. Smoke em 3 larguras
   (1600/1024/390) já OK, sem rolagem horizontal do card.

**Como retomar:** carregar a skill `workflow`, ler esta seção + a memória `project_cobranca_encargos.md` +
o ledger `.superpowers/sdd/progress-encargos.md`; confirmar git (HEAD `5a3928c`, branch limpa); dev em
http://localhost:8080 (login farlei.rocha@gmail.com / Prime123!; gotcha `#modalAlertaPonto` intercepta
cliques — remover via browser_evaluate; **cache do navegador engana** — navegar com `?nocache=N` e forçar
reload do `cobrancas.css`; **cache do Twig no dev** — rodar `cache:clear --env=dev` após editar template).
Objeto de teste do dono: carteira TOPLIFE I, objeto **117** (caso âncora **116**, 161 obrigações congeladas).

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
