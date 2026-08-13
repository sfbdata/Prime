# Handoff — conserto da dupla contagem (frente `cobranca-dupla-contagem`)

**Escrito em 2026-08-13.** Risco **ALTO**: a frente altera dinheiro gravado em produção.
⛔ **A importação em produção segue BLOQUEADA pelo dono.** Nada aqui autoriza importar.

---

## 1. A premissa que manda em tudo

Decisão do dono, 12/08 (registrada na §16.3 de `docs/specs/cobranca-espelho-da-contabilidade.md`):

> *"Para o sistema a gente tem que refletir a contabilidade, estando certo ou errado. Por mais que
> pareça um erro da contabilidade, isso tem que aparecer no sistema."*

**Consequência prática para quem for continuar:** não conserte segundo a régua que parece certa;
conserte para reproduzir a régua **da contabilidade**, e **prove com os números dela**. Foi assim que
o conserto já feito foi validado (§4 abaixo).

## 2. Estado do git — leia antes de tocar em qualquer coisa

| onde | commit | situação |
|---|---|---|
| `origin/master` | `1b741826` | **publicado** |
| **em PRODUÇÃO** | `9a7c59b1` | ⚠️ **o deploy parou aqui** — prod está 3 commits atrás |
| frente `cobranca-dupla-contagem` | `cf60fedb` | 2 commits próprios, **não publicados** |

⚠️ **A régua do encargo gravado que está rodando em produção tem a assinatura ERRADA.** A correção
(`d8881b02`) está publicada mas **não deployada**. Enquanto não houver deploy,
`app:cobranca:espelho:encargos` em prod devolve **22 dívidas / R$ 997,20**; o número certo é
**21 / R$ 1.167,58**.

A frente está **empilhada no `master`** de propósito (declarado em `docs/frentes-ativas.md`): ela
depende da régua corrigida, que é como o conserto se prova. **O deploy será os dois juntos.**

```bash
scripts/frente-testar.sh cobranca-dupla-contagem   # 3.605 verdes em cf60fedb
```

## 3. Os números de produção que valem (medidos 13/08)

| | |
|---|---:|
| dívidas com dupla contagem **já gravada** | **21** |
| dinheiro duplicado | **R$ 1.167,58** |
| — do qual é **saldo cobrado a mais** (multa) | R$ 804,83 |
| — do qual **não** entra no saldo (honorário) | R$ 362,75 |
| quanto a **próxima importação** acrescentaria | R$ 7.323,89 |
| congeladas entre as 21 | **0** (ainda dá para consertar sem sequela) |

A lista nominal das 21 (unidade, NN, id, valores) foi entregue ao dono **fora do repositório**, por
ter PII. Reproduzível a qualquer momento pelo comando `espelho:encargos --detalhar` **depois do deploy**.

🔴 **O risco com prazo:** obrigação congelada nunca é re-hidratada. Se alguma das 21 for liquidada ou
substituída por acordo antes do conserto, o valor inflado **congela e vira permanente**.

## 4. O que JÁ está feito nesta frente

### `29e1fd8d` — a importação parou de duplicar

- `BoletoImportavel` ganhou **INV-E5**: os encargos agora vêm em duas versões lado a lado — a que soma
  o Valor das linhas `1.4`/`1.5`/`1.15` por cima (certa no **boleto comum**) e a que só lê as colunas
  I/J/L (certa na **parcela de acordo**).
- `TopLifeInadimplenciaAdapter` acumula as duas **em paralelo**. 🔑 **Não derive uma da outra por
  subtração:** na linha `1.15` o adapter **troca** a coluna L pelo Valor em vez de somar, então
  subtrair não devolve a coluna descartada.
- `ImportarRelatorioCarteiraUseCase::materializarEncargosImportados()` escolhe pelo ramo
  (`$boleto->acordo !== null`). É o **único** ponto que grava; tem 2 chamadores (`:236` nova, `:260`
  reimportada).
- Testes: o caso real do NN 74789 e a não-regressão do boleto comum. **Provados reintroduzindo o
  defeito.**

⚠️ **O helper `boletoComAcordo()` dos testes fixava todos os encargos em zero** — por isso a dupla
contagem era invisível para a suíte inteira. Agora aceita as duas versões. Se você criar caso novo de
acordo, **passe encargo**, senão o teste não prova nada.

### `cf60fedb` — a prova de que o conserto espelha a contabilidade

O dono perguntou: *"isso é o entendimento da contabilidade?"* Medido no lote de 12/08, parcelas de
acordo da TL1 — a multa que a contabilidade lança em cada linha é **2% do Valor daquela linha**:

| classe | linhas | multa == 2% do Valor da linha |
|---|---:|---:|
| `1.1` | 259 | 259 |
| `1.14` | 114 | 114 |
| **`1.5` multas** | 23 | **23** |
| **`1.4` juros** | 21 | **21** |
| **`1.15` honorário** | 23 | **23** |

**Ela cobra multa sobre a linha de multa.** Logo, para ela aquilo é **principal**. Os R$ 309,62 que o
sistema gravava no NN 74789 não existem em coluna nenhuma da planilha.

## 5. O QUE FALTA — a reconciliação das 21

**Nada disso está começado.** É a única tarefa aberta da frente.

### O que precisa fazer

Um comando de console que ache as 21 pela **assinatura** e corrija o encargo gravado.

| requisito | por quê |
|---|---|
| **simula por padrão**; só escreve com `--aplicar` explícito | escreve dinheiro em produção |
| reusa `ConferenciaDeEncargos::duplaContagemPorCampo()` | a assinatura é regra de dinheiro; **não escreva uma segunda cópia** (D10) |
| **pula obrigação congelada** | congelada não é re-hidratada; mexer nela é outra decisão |
| registra `EventoHistorico` por caso | `EditarConfiguracaoCasoUseCase:121-141` é o molde |
| reporta **por campo** (multa × honorário) | honorário não entra no `valorExigivel()`; somar os dois apresenta como saldo o que não é |
| roda dentro de `wrapInTransaction` | molde: `ImportarAcordosDetalhadosUseCase:123` |

### O que gravar no lugar

O encargo **das colunas** — o mesmo que a importação corrigida gravaria. Ele sai do espelho: as somas
por boleto já estão em `ConferenciaDeEncargos::somasDoRelatorio()`.

### Armadilhas medidas

- ⚠️ **`AuditLog` sai degradado em CLI.** `Obrigacao` é `Auditavel`, então qualquer flush já grava o
  changeset — **mas** `actorUserId`, `ipAddress`, `route` ficam `null` e o `tenantId` vem do
  `TenantContext`, que **não está populado num comando**. Se o rastro importa, popule ou registre pelo
  `EventoHistorico`.
- ⚠️ **`ImportarRelatorioCarteiraUseCase` não registra `EventoHistorico` nenhum** — hoje a importação
  só deixa o `AuditLog` automático. Não copie essa omissão.
- ⚠️ Comentário morto em `ImportarRelatorioCarteiraUseCase:258-259` diz *"RE-CONGELA na data nova"* —
  **não congela**. Não acredite nele.
- ⚠️ `espelho:calibrar` e `espelho:encargos` precisam de `-d memory_limit=512M` em prod. O
  `calibrar` já estourou 128M com cache frio, morrendo **antes** da TOP LIFE I — a carteira grande é
  justamente a que fica sem número.

## 6. Três lições desta sessão que custaram caro

1. **Medição vale pelo recorte que cobre.** Reportei "zero dupla contagem" a partir de uma consulta que
   testava **só o campo juros**. O defeito estava na **multa**. Reportar sem o recorte é reportar outra
   coisa.
2. **Não rode peça de medição de dinheiro em produção antes da revisão.** A régua rodou, o número foi
   ao dono, e teve de ser corrigido **duas vezes** (0 → 22 → 21).
3. **Achado de revisão medido no dev não vale para prod.** A mesma revisão mediu um falso positivo em
   **9,1%** no dev; em produção era **0,3%** — 30× menor. O defeito era real, a consequência não.

## 7. Fora do escopo desta frente (não faça junto)

- ⛔ **Abrir a porta do adapter** (defeito 1). Hoje **não** corrige subcobrança: os 17 boletos já estão
  no sistema pelo importador de acordos. Sozinha, ela só faz cobrar a maior.
- ⛔ **Reverter a decisão #8** (honorário na parcela de acordo). Faz a dívida de **103 devedores
  SUBIR**, R$ 7.227,62. Precisa de spec própria e do dono. E **nunca reporte o líquido**: sobe
  R$ 7.227,62 e desce R$ 7.323,89, quase se cancelam, e o líquido esconde as duas pontas.
- ⏳ **Relatório de gerência** (se os acordos já tinham honorário embutido) — o dono quer, mas *depois*.
- 📌 **Classificação do boleto comum.** A contabilidade trata a linha `1.4` como principal; nós a
  jogamos no encargo. Total igual, classificação diferente. Vale **R$ 6,28** hoje. Mexer nisso muda
  `valorOriginal` de boleto comum — raio de explosão muito maior.
