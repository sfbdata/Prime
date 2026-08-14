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
| **em PRODUÇÃO** | `1b741826` | ✅ deploy feito em 13/08 — prod já tem a assinatura corrigida |
| frente `cobranca-dupla-contagem` | ver `git log` | commits próprios, **não publicados** |

⚠️ **A régua em produção ainda lê o LOTE ERRADO.** A correção da assinatura (`d8881b02`) já está
deployada, mas o INV-CE6 (comparar contra o lote que escreveu a obrigação, e não contra o último
carregado) está **nesta frente, não publicado**. Enquanto não houver deploy,
`app:cobranca:espelho:encargos` em prod **subconta** — ver §3.

A frente sai de `d8881b02` — ⚠️ **ela NÃO contém `1b741826`** (o commit de `docs/frentes-ativas.md`),
então "empilhada no master" é impreciso: a base é o commit da régua, não o topo. Sem conflito de
arquivo entre os dois. Ela depende da régua corrigida, que é como o conserto se prova —
**o deploy será os dois juntos.**

```bash
scripts/frente-testar.sh cobranca-dupla-contagem   # 3.616 verdes
```

## 3. Os números de produção — e por que o total se moveu TRÊS vezes

🔴 **Os "21 / R$ 1.167,58" estão SUPERADOS.** Eles saíram da régua comparando contra o **último** lote
do espelho (12/08), que **não é** o que escreveu o banco (a importação de 11/08). Como a assinatura é
igualdade exata e as colunas de juros e honorário andam todo dia, contra o lote errado ela falha em
silêncio e **subconta**. Detalhe completo na §17.7 da spec do espelho.

| | contra 12/08 (o que a régua lia) | contra 11/08 (o que escreveu) |
|---|---:|---:|
| dívidas | 21 | **≤ 25** |
| juros duplicado | R$ 0,00 | **R$ 624,72** |
| multa duplicada | R$ 804,83 | **R$ 804,83** ← não se move |
| honorário duplicado | R$ 362,75 | R$ 1.756,39 |
| **saldo cobrado a mais** (juros + multa) | R$ 804,83 | **R$ 1.429,55** |
| congeladas | **0** | **0** (ainda dá para consertar sem sequela) |

**As três mudanças do total têm causas DIFERENTES — não é erro em cima de erro:**

1. escopo da consulta (só testava juros) → §17.6
2. forma da assinatura (o honorário não é simétrico) → §17.6
3. **lote comparado** (último × o que escreveu) → §17.7

E repare no sinal mais forte a favor do recorte novo: **a multa não se moveu em nenhuma das três**,
porque é 2% fixo e não anda entre emissões. O que se move é exatamente o que deveria se mover.

⚠️ **`≤ 25` é TETO, não fechamento.** Veio de SQL manual que não aplica o INV-CE4 (coerência vence
assinatura). **A lista que vale é a que a régua corrigida produzir depois do deploy** — decisão do
dono, 13/08. Não reconcilie a partir de consulta manual.

⚠️ **Ao reportar, separe sempre:** multa e juros entram no `valorExigivel()`, honorário **não**. O
número que interessa ao devedor é multa + juros.

### A lista nominal — use `--duplicadas`, NUNCA `--detalhar`

⚠️ **Correção de um erro deste handoff.** A versão anterior dizia que a lista da reconciliação era
"reproduzível por `espelho:encargos --detalhar`". **Não era.** O `--detalhar` mostra as *piores
diferenças contra a nossa fórmula*: está **cortado em 20** e ordenado por essa diferença, que é outra
pergunta. Medido pela revisão no dev: o 20º item já estava em R$ 91,72, enquanto o duplicado típico é
da ordem de R$ 38 por dívida — as dívidas a corrigir **tendiam a ficar de fora** da lista.

⚠️ **Desde a fatia dos quatro relatórios (INV-Q10), estes comandos RECUSAM rodar sem `APP_DEBUG=0`**
e saem com código **69**. Não é defeito: com o debug ligado, o log do Doctrine imprime os parâmetros
das consultas — nome, CPF, e-mail e telefone por extenso — numa saída que é colada em chat. Os
comandos abaixo já vêm com a variável.

```bash
# a lista da reconciliação: completa, ordenada pelo duplicado, com o lote de cada linha
APP_DEBUG=0 php -d memory_limit=512M bin/console app:cobranca:espelho:encargos --tenant-id=<id> --duplicadas

# para salvar: o `mkdir -p` NÃO é opcional — o diretório é gitignorado, logo não existe no checkout
# nem na VPS, e sem ele o redirect falha justamente com a lista de PII na tela (que é como uma saída
# improvisada foi parar no repositório em 03/08)
mkdir -p docs/gestao-cobrancas/listas-reconciliacao
APP_DEBUG=0 php -d memory_limit=512M bin/console app:cobranca:espelho:encargos --tenant-id=<id> --duplicadas \
  > docs/gestao-cobrancas/listas-reconciliacao/tl1.txt
```

Ela traz por dívida: `id`, unidade, referência, competência, **lote usado (id + emissão)**, e os totais
**separados** — o que sai do saldo do devedor (juros + multa + correção) e o que sai fora dele
(honorário). **Contém PII** (unidade + número do boleto identificam o devedor): é saída de terminal
para o dono. Se precisar salvar em arquivo, use o destino já gitignorado
`docs/gestao-cobrancas/listas-reconciliacao/` — em 03/08 uma saída derivada das planilhas ficou
versionável justamente por não haver onde pôr.

🔴 **O risco com prazo:** obrigação congelada nunca é re-hidratada. Se alguma das afetadas for liquidada ou
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

## 5. A reconciliação — ✅ ESCRITA e revisada; falta RODAR em produção

Corrige no banco o encargo que a importação contou duas vezes. Peças:
`ReconciliarDuplaContagemUseCase` · `ReconciliarDuplaContagemCommand` ·
`app:cobranca:reconciliar-dupla-contagem`. Spec completa: **§17.11** do espelho.

### ⚠️ O que ela conserta, e o que ela NÃO conserta

**Ela conserta o DADO, não a cobrança na tela.** As dívidas afetadas são todas **não-congeladas**, e
`EncargosVivos::hidratar()` recalcula os quatro encargos pela nossa fórmula em toda leitura hidratada —
então o valor inflado **já não é o que o devedor vê**. O que ele contamina é o **gravado**: SQL, MCP,
relatório agregado e a conferência pós-importação.

🔴 **A urgência não é a cobrança, é a permanência.** Obrigação congelada nunca é re-hidratada. Se
qualquer uma das afetadas for liquidada ou substituída antes do conserto, o inflado **congela e vira
definitivo**.

*(Este parágrafo existe porque o número chegou a ser reportado ao dono como "sai do saldo do devedor",
o que superdimensionava o efeito. Corrigido em 13/08.)*

### As travas do comando

| trava | como funciona |
|---|---|
| **simula por padrão** | sem `--aplicar` nada é gravado; `prever()` e `confirmar()` percorrem o mesmo código |
| **autor obrigatório** | `--aplicar` exige `--usuario-id` de **membro do escritório** (`UserTenant`) |
| **pula congelada** | e reporta o valor inflado que FICA — pular não é no-op |
| **preserva o snapshot** | `encargosAtualizadosEm` não é recarimbado: a correção remove soma, não recalcula |
| **rastro conferido** | todo caso corrigido tem `EventoHistorico` com o lote usado; a checagem é **dentro da transação**, antes do flush |
| **trava da lista — OPCIONAL** | `--esperado-dividas` + `--esperado-total` abortam (código `68`) se o universo mudou desde a aprovação |

⚠️ **A trava da lista NÃO é obrigatória** (decisão do dono, 13/08): o risco que ela cobre é pequeno
enquanto a importação está bloqueada, e exigi-la custaria uma etapa manual em toda execução. A
simulação imprime as duas linhas prontas — a simples e a travada — para quem quiser usar. Informar só
uma das duas opções é **recusado**: meia trava faz quem operou achar que travou.

### Códigos de saída

`0` ok · `64` erro de invocação · `65` contas não fecham / rastro incompleto · `66` nada a fazer ·
`67` sobrou inflação (houve dívida pulada) · `68` a lista mudou desde a aprovação.

🔴 **`66`, `67` e `68` significam coisas DIFERENTES na régua e aqui.** Um wrapper não pode tratar
código sem saber qual comando rodou.

### 🔑 O QUE FALTA: rodar em produção — e quem roda é o DONO

**Nenhuma sessão tem acesso à VPS.** O Claude monta os comandos; o dono executa e cola a saída de volta.
A sequência combinada:

1. **dono:** merge + deploy;
2. **dono:** roda a SIMULAÇÃO e cola a saída;
3. **Claude:** confere a saída contra o que a régua reportou e diz se está tudo certo;
4. **dono:** roda o `--aplicar`;
5. **dono:** roda a régua de novo e cola, para confirmar que zerou.

⚠️ **No passo 5 a régua vai classificar as corrigidas como `divergente`, e isso é ESPERADO** — o
gravado passou a ser o número da contabilidade, que a nossa fórmula naquela data não reproduz. Elas
saem de "dupla contagem" e caem em "divergente" até a próxima hidratação. **Sem saber disso, alguém lê
a verificação como falha e desconserta.**

### O que gravar no lugar (INV-CE9) — a armadilha do honorário

O encargo **das colunas**, que é o que a importação corrigida grava — por isso reimportar depois não
mexe em nada. A régua entrega pronto em `corrigidoPorCampo`; a reconciliação **não** re-deriva.

🔑 **ATENÇÃO: no honorário NÃO é `gravado − duplicado`.**

| campo | grava |
|---|---|
| juros | `Σ` coluna I — coincide com `gravado − duplicado` |
| multa | `Σ` coluna J — coincide |
| **honorário** | **`Σ` coluna L de TODAS as linhas** — **não** coincide |
| correção | **não toca** — nenhuma classe de linha vira correção |

O adapter, na linha `1.15`, **troca** a coluna L pelo Valor em vez de somar
(`TopLifeInadimplenciaAdapter:200`). Subtrair devolveria `Σ_{≠1.15} L` e perderia a coluna L da própria
linha de honorário. Consequência: **o dinheiro que sai do banco ≠ o que a régua chama de "duplicado"**
no honorário. Reporte os dois separados.

### Medido em produção antes da primeira execução (13/08)

- **25 dívidas**, R$ 3.185,94 duplicado — a lista que o dono aprovou;
- **0 congeladas** → nenhuma seria pulada;
- **0 com pagamento alocado** → nenhuma ficaria superalocada pela baixa do exigível.
  ⚠️ O comando **não tem guarda** contra superalocação. O raio hoje é nulo; se ele for reusado num
  universo diferente, **medir de novo antes**.

### O que a revisão adversarial já fechou

Quatro rodadas na régua e duas na reconciliação. Os achados que mudaram código de verdade:

- a régua lia o **lote errado** (o último, não o que escreveu a dívida) e **subcontava em silêncio**;
- o balde `injulgável` comia a checagem de coerência, que não depende de lote;
- veredito e código de saída eram **cegos** ao balde: a régua imprimia caixa verde sobre cobertura de 0,4%;
- a lista da reconciliação não tinha teste que a segurasse (truncar/desordenar/lote errado passavam verdes);
- o comando que escreve dinheiro **não tinha teste nenhum**;
- dois testes eram **vacuosos** — passavam com a implementação errada;
- a checagem do rastro rodava **depois do commit** e afirmava "transação revertida" sobre dado gravado.

### Armadilhas medidas (não repita)
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
- ⚠️ **O `espelho:encargos` tem SEIS códigos de saída — o `0` e cinco de não-sucesso:**

  | código | significa |
  |---:|---|
  | `0` | conferiu **tudo** e está limpo |
  | `64` | erro de invocação (tenant não existe) |
  | `65` | 🔴 **defeito da ferramenta** — os baldes não fecham, o relatório não vale |
  | `66` | nenhuma carteira foi conferida (critério não casou, ou nenhuma tem lote) |
  | `67` | cobertura incompleta — rodou, nada duplicado, **e não conferiu tudo** |
  | `68` | 🔴 **achou dupla contagem** |

  Os códigos ficam na faixa `6x` de propósito: **`1` é o que o Symfony devolve para exceção não
  capturada** (e esta régua lança `LogicException` para lote sem `dadosAte`), e **`2` é
  `Command::INVALID`**, já usado com esse sentido por 10 comandos irmãos do mesmo diretório. Usar
  qualquer um dos dois faria um wrapper ler outra coisa.
- ⚠️ **Depois da reconciliação as dívidas corrigidas vão aparecer como `divergente` na régua, e isso é
  ESPERADO.** A régua compara o gravado com a NOSSA fórmula na data do snapshot; depois do conserto o
  gravado passa a ser o número da **contabilidade**, que a nossa fórmula naquela data não reproduz.
  Elas saem de "dupla contagem" e caem em "divergente" até a próxima hidratação. Sem saber disso,
  alguém lê a verificação como falha e "desconserta". *(Instrução do dono, 13/08.)*

## 5.1 💸 Dívida técnica aberta: a obrigação não sabe qual relatório a escreveu

Não existe FK de `cobranca_obrigacao` para `cobranca_relatorio_importado`. O casamento do INV-CE6 é
por **data**, e isso é suposição ("a importação roda no dia da emissão"), não invariante. A correção
durável é gravar a FK na importação. É **mudança de schema** e o dono decidiu que fica **fora desta
frente** (13/08). Detalhe e raio medido: §17.7 da spec do espelho.

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
