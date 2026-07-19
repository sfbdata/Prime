# Spec — Encargos separados e configuráveis em cascata (juros/multa/correção/honorários)

> Módulo `App\Cobranca` — **EM PRODUÇÃO** (bluejus.com.br). Risco **ALTO** (dinheiro na UI + no cálculo + migração de dados).
> Status: **rascunho autônomo para revisão humana** (escrito durante a noite, sem revisão interativa).
> Autor do rascunho: orquestrador (Claude). Verificação da fórmula: subagente independente (apêndice A).
> Data: 2026-07-19.

## 0. Diretriz de execução (pedido explícito do dono)

O dono autorizou execução **totalmente autônoma**, tomando **as melhores decisões**, usando **subagentes** e
técnicas para **manter o contexto do chat principal enxuto**. Portanto:

- A implementação segue o ciclo do projeto (investigar → spec → **humano aprova** → implementar → revisar), mas
  as decisões de escopo/design abaixo já estão tomadas e justificadas; o humano só precisa **confirmar ou vetar**.
- Toda fatia de escrita é delegada a `feature-implementer` em worktree isolada; toda revisão a
  `feature-review-agent` (read-only); a verificação de dinheiro contra dados reais é de **subagente independente**.
- **Nada de push/merge/deploy** — isso é sempre do humano. O entregável autônomo é: código commitado localmente,
  testes verdes, revisão feita, e um HANDOFF do que foi decidido.
- Onde esta spec deixa uma decisão "a confirmar", o implementador adota o **default recomendado** e segue; o
  humano reverte na revisão se quiser. Não travar esperando resposta.

---

## 1. Objetivo

Hoje uma obrigação guarda `valorOriginal` (principal) + `encargosReconhecidos` — **um número único** que mistura
juros e multa. O dono quer:

1. **Separar** os encargos em **juros, multa e correção** (três valores independentes), mais **honorários**.
2. Para cada um, **mostrar a % e o valor em R$**, e **editar nos dois sentidos** (% recalcula R$; R$ recalcula %).
3. A **linha da obrigação** exibe as colunas como o PDF/planilha da contabilidade:
   *Valor · Juros · Multa · Correção · Honorários · Total*.
4. As taxas **crescem com o tempo** (juros pró-rata diária; honorários acompanham) — sem depender de reimportar.
5. As configurações têm **3 níveis em cascata**: Carteira → Objeto (Caso) → Obrigação.
6. Poder **editar obrigação e valor recebido a qualquer momento, inclusive já pagos** (registro/negociação
   pessoal), sempre com **histórico**.
7. Ser **configurável ("democrático", é SaaS)**: cada escritório define suas taxas e regras.

**Princípio-guia (dado pelo dono):** este sistema é de **registro detalhado e pessoal**; quem faz o dinheiro de
verdade (e gera boleto) é a **contabilidade**, com sistema próprio. Logo: **flexibilidade com rastro** vale mais
que rigidez contábil, e os números do sistema devem **reproduzir a contabilidade por construção**.

---

## 2. ⚠️ Descobertas da verificação independente (LEIA ANTES DE APROVAR)

Um subagente independente reverteu a fórmula a partir de **4.413 linhas reais** das planilhas TOPLIFE I e II e
provou **100,00% de acerto ao centavo** nas dívidas de mensalidade (3.865/3.865 na I, 452/452 na II). Detalhe e
prova no **Apêndice A**. Três achados **contrariam** o que foi conversado no brainstorm — **confirme na revisão**:

| Você acreditava (do brainstorm) | O que os dados reais provam |
|---|---|
| Multa e correção incidem sobre **principal + juros** (base composta) | **Multa = 2% do principal PURO**, fixa, não cresce. Quem incide sobre a base composta (P+juros+multa) são os **honorários**. |
| Correção é uma taxa a definir | **Correção = 0** em 100% das linhas (não usada nesta operação). Fica como campo **configurável, default 0**. |
| Tolerância de atraso zera **juros/multa** dentro de X dias | Juros e multa valem **desde o 1º dia**. A carência de **~30 dias** existe, mas **só para honorários**. |

**Consequência de design:** a opção "Fixa vs Progressiva" que você pediu **continua existindo** (é SaaS, tem de
ser configurável), mas o **default TOPLIFE** passa a ser: multa **Fixa** (principal), correção **Fixa** (0),
**honorários Progressivos** (base composta) com **carência de 30 dias**. Você pode configurar diferente por
carteira quando quiser.

---

## 3. Fórmulas autoritativas (default TOPLIFE, todas configuráveis)

Notação: `P` = principal (centavos), `d` = dias de atraso (`hoje − vencimento`, ≥ 0). Tudo em **centavos inteiros**.

```
Se d == 0:
    juros = multa = correcao = honorarios = 0 ;  total = P
Se d >= 1:
    juros      = round_half_down( P * taxaJurosMensalBp * d / (30 * 10000) )   # simples, pró-rata dia (mês=30)
    multa      = round_half_up  ( P * taxaMultaBp / 10000 )                    # base = principal (config.)
    correcao   = round_half_up  ( baseCorrecao * taxaCorrecaoBp / 10000 )      # default taxa 0
    honorarios = (d > carenciaHonorariosDias)
                   ? round_half_up( (P + juros + multa + correcao) * taxaHonBp / 10000 )
                   : 0
    total      = P + juros + multa + correcao + honorarios
```

Parâmetros (basis points; 100 bp = 1%). Defaults comprovados nos dados:

| Parâmetro | Default TOPLIFE | Observações |
|---|---|---|
| `taxaJurosMensalBp` | **100** (1% a.m.) | pró-rata diária ÷30, **simples**, base **principal**, arredonda **half-down** |
| `taxaMultaBp` | **200** (2%) | base **principal puro**, aplicação **única** (não cresce), half-up |
| `taxaCorrecaoBp` | **0** | base configurável (principal | composta), default 0 |
| `taxaHonBp` | **2000 (I) / 1500 (II)** | base **composta** (P+juros+multa+correção), half-up — **por carteira** |
| `carenciaHonorariosDias` | **30** | honorários só a partir de `d > 30`. Corte exato (29/30/31) não é observável nos dados → **confirmar com a contabilidade** (provável "mês seguinte ao vencimento") |
| base multa/correção | **principal** | opção "Fixa (principal)" vs "Progressiva (composta)" — democrática |
| regime juros | **simples** | opção simples vs composto — democrática (default simples) |
| arredondamento juros | **half-down** | confirmado empiricamente; **revalidar contra novo extrato antes do go-live** (Apêndice A, §incertezas) |

> **Classes especiais** ("1.4 Juros", "1.5 Multas", "1.15 Honorário advocatício", "1.6 Descontos"): são
> lançamentos de encargo/acordo já fechados, **não** mensalidades. O importador deve trazê-las como **valores
> fixos** (congeladas), sem recalcular pela fórmula. Ver §8 (congelamento) e §9 (import).

---

## 4. Modelo de dados

### 4.1 Configuração (a cascata) — mesmos campos em 3 níveis
Conjunto de configuração de encargos (`ConfigEncargos`, um *embeddable*/grupo de colunas repetido nos 3 níveis):

- `taxaJurosMensalBp` (int) · `regimeJuros` (enum simples|composto)
- `taxaMultaBp` (int) · `baseMulta` (enum principal|composta)
- `taxaCorrecaoBp` (int) · `baseCorrecao` (enum principal|composta)
- `taxaHonBp` (int) · `baseHonorarios` (enum) — **integra com o `FormaHonorarios` já existente** (ver §7)
- `carenciaHonorariosDias` (int)
- `toleranciaJurosMultaDias` (int, default 0) — carência de juros/multa (default 0 = valem desde o 1º dia)

**Nível 1 — Carteira** (`cobranca_carteira`): ganha as colunas acima (já tem `formaHonorarios`,
`percentualHonorarios`, `toleranciaAtrasoDias` — este último passa a alimentar `carenciaHonorariosDias`/
`toleranciaJurosMultaDias`; ver §7 sobre reaproveitamento vs colunas novas).

**Nível 2 — Objeto/Caso** (`cobranca_caso`): **snapshot** da config no `AbrirCasoUseCase` (hoje já snapshota
forma+% de honorários). Colunas **nullable**: `null` = "herda a carteira"; preenchido = "override deste objeto".

**Nível 3 — Obrigação** (`cobranca_obrigacao`): mesma config, **nullable** = herda o Caso. Preenchida ao criar
(snapshot do Caso resolvido) e **editável a qualquer momento**.

**Resolução de precedência** (serviço novo `ResolvedorConfigEncargos`): Obrigação → Caso → Carteira, campo a campo
(um override pode preencher só a taxa de juros e herdar o resto). Retorna uma `ConfigEncargos` "efetiva".

### 4.2 Valores materializados na Obrigação (`cobranca_obrigacao`)
Substituir `encargosReconhecidos` (int único) por **três colunas** + metadados:

- `juros` (int, cents) · `multa` (int, cents) · `correcao` (int, cents) — **materializados** (ver §6).
- `honorarios` (int, cents) — materializado (é derivado, mas persistido para o saldo/relatório).
- `encargosCongeladosEm` (nullable datetime) — se preenchido, os valores acima **não** são recalculados (obrigação
  editada à mão, paga, negociada, ou lançamento de classe especial). `null` = recalculável pela fórmula.
- `encargosAtualizadosEm` (datetime) — data de referência da última materialização (para exibir "atualizado em").

`valorExigivel()` passa a ser `valorOriginal + juros + multa + correcao` (**honorários NÃO entram no exigível** —
§18.5 do modelo atual: honorários são separados da dívida do credor). **A soma dos 3 == o antigo
`encargosReconhecidos`** → `CalculadoraSaldo`, Dashboard, FIFO e Acordo **não mudam** (invariante crítica, §12).

> Decisão: **honorários fora do `valorExigivel`** (mantém a regra atual de que honorário não é dívida do credor).
> O honorário aparece na linha e no total exibido, mas o *saldo exigível* (o que alimenta acordos/pagamentos)
> continua sendo principal + juros + multa + correção. Confirmar na revisão.

---

## 5. Cascata no tempo (semântica)

- **Carteira e Objeto = "cópia no nascimento"** (como honorários hoje): snapshot no momento de criar. Mudar o
  padrão da carteira/objeto **depois** só afeta os **novos** — **não** recalcula casos/obrigações existentes
  (preserva a regra §18.3). Um objeto pode sobrepor a carteira; vale para as **novas** obrigações dele.
- **Obrigação = editável a qualquer momento**, inclusive **paga**. Editar → registra evento no histórico
  (antes/depois) e **congela** (`encargosCongeladosEm = agora`) aquela obrigação, que **para de crescer**.
- **Valor recebido editável** para reconciliar quando uma taxa muda (estende `CorrigirPagamentoUseCase`,
  também com histórico).

---

## 6. Motor de cálculo — decisão de arquitetura

**Problema:** hoje `encargosReconhecidos` é **estático** (invariável 20: "sem recalcular nada automaticamente").
Taxas que crescem no tempo (juros muda **todo dia**) quebram isso. Duas saídas:

- **(A) Derivado on-the-fly:** `valorExigivel($hoje)` recalcula sempre. **Rejeitado:** `$hoje` teria de entrar em
  `CalculadoraSaldo::saldoExigivel/derivarSaldos`, `AutoAlocadorFifo`, `MontarDetalheAcordo`, `AcordoCriarType`,
  batch do Dashboard — respinga em todo o maquinário de saldo (invasivo e arriscado num módulo em produção).
- **(B) Materializado + refresh (RECOMENDADO):** os 3 campos são **persistidos**; `valorExigivel()` continua
  **puro** (soma dos campos). Um **cron diário** recalcula as obrigações **não congeladas** para "hoje" e
  persiste. Entre execuções, o valor fica ≤ 1 dia defasado — imperceptível (juros ~0,033%/dia) e aceitável num
  sistema de **registro** (a contabilidade é a fonte de verdade). Ganha o crescimento **sem tocar** no saldo.

**Serviço novo `CalculadoraEncargos`** (final, puro, sem I/O): dado `valorOriginal`, `vencimento`,
`ConfigEncargos efetiva`, `dataReferencia` → devolve `{juros, multa, correcao, honorarios}` em centavos, seguindo
§3. Injetável/testável isoladamente (round-trip % ↔ R$ coberto por teste).

**Quando materializa:**
1. Ao **criar** obrigação (manual: computa para hoje; import: usa os valores da planilha e **congela**).
2. Ao **editar** obrigação (à mão → congela).
3. **Cron diário** `app:cobranca:atualizar-encargos` (só as **não congeladas**, `encargosCongeladosEm IS NULL`).
4. **Exibição**: o Output DTO pode computar "ao vivo" para hoje (função pura, sem persistir) para o usuário sempre
   ver o valor do dia; o materializado (cron) é o que alimenta saldo/Dashboard. *(Decisão: começar simples —
   exibir o materializado com "atualizado em"; tornar a exibição ao vivo é enhancement opcional. Confirmar.)*

---

## 7. Honorários — integração com o que já existe

O módulo já tem `FormaHonorarios` (AcrescidoDivida / RetidoRecuperado / CobradoSeparado / SemPercentual) +
`percentualHonorarios`, snapshot no Caso, e `CalculadoraHonorarios`. O honorário do PDF (20%/15%, somado ao total,
carência >30d) **é** `AcrescidoDivida` com **base composta** e **carência**. Portanto:

- **Reusar** `FormaHonorarios` + `percentualHonorarios` (não criar paralelo). Acrescentar: **base composta**
  (P+juros+multa+correção) e **carência** (`carenciaHonorariosDias`) ao cálculo projetado.
- `CalculadoraHonorarios::projetados` passa a receber a base composta e respeitar a carência.
- **Não** mexer em `ratearPagamento`/`brutoParaRecuperar` além do necessário (o "Receber" já usa o bruto). O
  prefill do "Receber" deve considerar a nova base/carência — cobrir por teste round-trip.

---

## 8. Edição com histórico + congelamento (estende o que existe)

- **`EditarObrigacaoUseCase`**: já registra `ObrigacaoEditada` com snapshot antes/depois (json). Estender o
  snapshot para os 3 campos + config; ao editar valores/config à mão, **setar `encargosCongeladosEm`**. Guards
  atuais (caso encerrado, acordo vigente, valor < alocado) passam a somar os 3 campos. Permitir editar **paga**
  (a infra já permite; garantir que o guard `< totalAlocado` use `valorOriginal + juros + multa + correcao`).
- **`CorrigirPagamentoUseCase`**: já reescreve composição sem estorno com evento `PagamentoCorrigido`. Permitir
  ajustar o **valor recebido** para reconciliar quando uma taxa muda.
- Usar o enum **`TipoEventoHistorico::ValorAtualizadoReconhecido`** (já existe, ocioso) para o evento de
  recálculo/edição de encargos.
- **Descongelar** (opcional, fase tardia): ação explícita "voltar a recalcular automaticamente" que limpa
  `encargosCongeladosEm` — com registro no histórico. *(YAGNI inicial: só congela; descongelar é enhancement.)*

---

## 9. Importador (`TopLifeInadimplenciaAdapter` + `BoletoImportavel` + `ImportarRelatorioCarteiraUseCase`)

- Ler também a **coluna K (Correção)** — hoje **descartada** (só soma Juros+Multa em `encargos`).
- `BoletoImportavel`: trocar `encargosCentavos` (único) por `juros/multa/correcao` (+ honorários se vier).
- Persistir os 3 separados; **congelar** o importado (`encargosCongeladosEm = dataDoRelatorio`) — os números da
  contabilidade são a verdade; o cron não deve sobrescrevê-los. Reimportar **atualiza** (idempotente por NN) e
  re-congela na nova data.
- Classes especiais (1.4/1.5/1.6/1.15): importar como **valores fixos congelados** (não recalcular).

---

## 10. Migração de dados

`Version<AAAAMMDDHHMMSS>` (DDL literal PostgreSQL; conferir colisão de número — falha silenciosa conhecida):

1. **Add colunas** em `cobranca_obrigacao` (`juros`, `multa`, `correcao`, `honorarios` default 0;
   `encargos_congelados_em`, `encargos_atualizados_em` nullable) e a config nos 3 níveis (carteira/caso/obrigação).
2. **Backfill dos existentes:** o antigo `encargos_reconhecidos` foi importado/digitado (estático). Duas opções:
   - **(recomendada) recompute-as-migration:** setar a config nas carteiras (defaults TOPLIFE) e **rodar o novo
     motor** para materializar `juros/multa/correcao` das obrigações **não editadas à mão**; congelar as editadas
     preservando o valor atual em `multa`/`juros` conforme melhor aproximação. *(Como o dado de dev/prod atual é
     de TESTE — memória —, o mais limpo é **reimportar** as planilhas, que têm as colunas separadas.)*
   - fallback: jogar todo `encargos_reconhecidos` em `juros` e congelar (preserva o saldo exato, perde o split).
3. **Manter `encargos_reconhecidos`** por 1 release como coluna-sombra (não remover no mesmo deploy) para
   rollback seguro; remover depois.
4. Banco de **teste** é `schema:create` (não replay) → as colunas novas entram no schema; rodar a suíte confirma.

---

## 11. UI

- **Linha da obrigação** (`objeto/_partials/_divida.html.twig`): passar de "Valor | O que é | ações" para as
  colunas do PDF — **Original · Juros · Multa · Correção · Honorários · Total** — com a **% ao lado de cada R$**
  (tooltip mostra a taxa configurada e "atualizado em"). Responsivo (colapsar em telas estreitas).
- **Criar/editar obrigação** (`_acoes_modais.html.twig` + JS em `objeto/show.html.twig`): campos de juros/multa/
  correção com **% ↔ R$** (editar um recalcula o outro, base = principal, em JS + revalidação no servidor) +
  forma de honorários + bases + carência + tolerância **só daquela obrigação**. Preserva os padrões do B5
  (reidratação) e do reset-on-close já existentes.
- **No objeto** (cabeçalho/config): mostrar as taxas/forma/carência **herdadas da carteira** com um "editar só
  para este objeto" (override nível 2). Deixar claro visualmente o que é herdado vs sobreposto.
- **Form da carteira** (`carteira/_campos_config.html.twig`): novos campos de taxa (juros/multa/correção/
  honorários/carência/tolerância), com os inputs "%" já no padrão do template.

---

## 12. Invariantes e riscos (não afrouxar)

- **INV-E1:** `valorExigivel() = valorOriginal + juros + multa + correcao`. A soma dos 3 **tem** de igualar o
  antigo `encargosReconhecidos` na migração, senão saldos existentes mudam. Teste de consistência obrigatório.
- **INV-E2:** honorários **fora** do `valorExigivel` (dívida do credor). *(a confirmar §4.2)*
- **INV-E3:** `CalculadoraSaldo`/Dashboard/FIFO/Acordo **não recebem `$hoje`** — o crescimento é via
  materialização (cron), não via `valorExigivel` dinâmico.
- **INV-E4:** obrigação **congelada** (`encargosCongeladosEm != null`) nunca é tocada pelo cron.
- **INV-E5:** o cálculo é **provado contra dados reais** por subagente independente **antes do go-live** (Apêndice
  A + revalidação com extrato novo por causa do arredondamento half-down do juros e do corte exato da carência).
- **Risco:** editar obrigação paga muda o total → o **valor recebido** pode ficar sobrando/faltando; a
  reconciliação (§8) e o histórico cobrem isso, mas exige teste do fluxo pago→editado→reconciliado.
- **Risco:** `Pagamento.valorEncargos`/`AlocacaoPagamento.valor` agregam encargos num número só. Rastrear
  pagamento **por categoria** (juros vs multa) seria mudança estrutural no alocador — **fora do escopo** (YAGNI);
  manter a alocação por valor total. Confirmar.

---

## 13. Decisões tomadas (autonomia) e a confirmar

**Tomadas (default do implementador, reversíveis na revisão):**
1. Arquitetura **materializada + cron** (§6-B), `valorExigivel` puro.
2. Multa **fixa sobre principal**, correção **0**, honorários **progressivos** com carência 30d (§2/§3) — porque
   os **dados reais** provam isso; a opção Fixa/Progressiva fica **configurável** (democrático).
3. Tolerância zera **honorários** (carência 30d), **não** juros/multa (que valem do 1º dia) — contraria o
   brainstorm, mas segue os dados. Campo `toleranciaJurosMultaDias` existe (default 0) para quem quiser.
4. Honorários **reusam** `FormaHonorarios`/`percentualHonorarios` (não criar paralelo), com base composta+carência.
5. Rastreio de pagamento **por valor total** (não por categoria de encargo) — YAGNI.

**A confirmar pelo humano (não travam a implementação; adotar o default e seguir):**
- Honorários **fora** do `valorExigivel` (INV-E2)?
- Corte exato da carência de honorários (29/30/31 dias / "mês seguinte")? — validar com a contabilidade.
- Backfill por **reimport** (recomendado, dado é de teste) vs recompute vs fallback (§10.2)?
- Exibição **ao vivo** vs materializada com "atualizado em" (§6.4)?

---

## 14. Fatiamento (fases) — cada fase é fatia independente, testada e revisada

O ciclo por fatia: `feature-implementer` (worktree) → `feature-review-agent` (read-only) → orquestrador integra
(cherry-pick individual) → testes direcionados → suíte + tenant-safety. **Ordem:**

1. **F1 — Motor + dados (base):** entidades/colunas (obrigação 3 campos + congelamento + config nos 3 níveis),
   `CalculadoraEncargos` (puro, com testes round-trip e prova contra as linhas reais do Apêndice A),
   `ResolvedorConfigEncargos`, migração. `valorExigivel` = soma dos 3. **Sem UI ainda.** Prova: suíte de saldo
   inalterada (INV-E1).
2. **F2 — Config na carteira + cascata:** DTOs/Forms/UseCases de carteira ganham as taxas; snapshot no
   `AbrirCasoUseCase`; override no objeto. Import lê correção + split + congela.
3. **F3 — Cron de crescimento:** `app:cobranca:atualizar-encargos` (não congeladas), idempotente, tenant-safe;
   teste de que congelada não muda e não-congelada cresce por `$hoje` injetável.
4. **F4 — UI da obrigação (colunas PDF) + % ↔ R$** no criar/editar, override no objeto; honorários com
   base composta+carência.
5. **F5 — Edição de pago + reconciliação do recebido** (estende Editar/Corrigir), histórico completo.
6. **F6 — Verificação independente final + go-live checklist:** subagente independente reprova a fórmula contra um
   **extrato novo** da contabilidade (half-down do juros, corte da carência); só então liberar deploy (humano).

Cada fase entra **atrás de flag/estado seguro** e não quebra a anterior. Migração e cron não vão a prod sem o
humano (deploy = rebuild via `deploy-prod-tls.sh`).

---

## Apêndice A — Relatório da verificação independente da fórmula

Subagente independente (read-only), sobre **4.413 linhas reais** (3.951 TOPLIFE I + 462 II), centavos inteiros.
**100,00% ao centavo** nas dívidas de mensalidade (classe 1.1 Condomínio + 1.14 Energia): 3.865/3.865 (I) e
452/452 (II).

**Fórmulas (d = dias de atraso, P = principal em centavos):**
- `juros = round_half_down(P * 0.01 * d / 30)` — 1% a.m., pró-rata diária (mês=30), **simples**, base principal, zero se d==0.
- `multa = round_half_up(P * 0.02)` — 2% do **principal puro**, única, não cresce; zero se d==0.
- `correcao = 0` — coluna K sempre 0 nas 4.413 linhas.
- `honorarios = round_half_up((P+juros+multa+correcao) * taxaHon)` **se d > ~30**, senão 0. `taxaHon` = **0,20 (I) / 0,15 (II)**.
- `total = P + juros + multa + correcao + honorarios` (honorários **entram** no Total do relatório).

**Provas (erro = 0 em todas):**
```
TOPLIFE II (hon 15%): P=170,00 d=56d  → Jur 3,17 Mul 3,40 Hon 26,49 Tot 203,06
TOPLIFE II          : P=170,00 d=25d  → Jur 1,42 Mul 3,40 Hon  0,00 Tot 174,82  (<=30d sem honorário)
TOPLIFE I  (hon 20%): P=170,00 d=240d → Jur 13,60 Mul 3,40 Hon 37,40 Tot 224,40
TOPLIFE I           : P=50,00  d=2889d→ Jur 48,15 Mul 1,00 Hon 19,83 Tot 118,98  (juros linear ~96% ⇒ simples)
```

**Diferença entre carteiras:** só a alíquota de honorários (20% I × 15% II) — **parâmetro por carteira**.

**Incertezas honestas (revalidar antes do go-live):**
1. Arredondamento **half-down do juros**: confirmado empiricamente (99,975% I / 100% II), mas baseado em casos de
   exato .5 centavo — revalidar contra extrato novo.
2. Corte da **carência de honorários**: ">30 dias" fecha 100%, mas não há linha com atraso 29–32d, então 29/30/31
   não é diretamente observável — confirmar com a contabilidade.
3. **Correção = 0**: pode ser que a operação não use correção monetária; deixar configurável (default 0).
4. **Classes especiais** (1.4/1.5/1.6/1.15): lógica própria; importar como valores fechados (congeladas), não recalcular.
