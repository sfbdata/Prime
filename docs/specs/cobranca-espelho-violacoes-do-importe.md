# Espelho da contabilidade — as violações do importe

Frente que tira do importador as **opiniões** que ele embute sobre a dívida. A regra que manda:
*o sistema reflete EXATAMENTE os dados da contabilidade* (decisão do dono, 12/08, registrada em
`cobranca-espelho-da-contabilidade.md` §1 e §16.3).

Duas delimitações do dono (17/08), que dão o nome certo ao defeito:

1. **O sistema MOSTRA; a gerência JULGA.** Ao achar divergência, a pergunta não é "qual é a regra
   certa?" — é "o sistema está reproduzindo o número dela, ou está julgando?". Se julga, o conserto é
   **tirar o julgamento**, não trocá-lo por um melhor. Espere apagar código, não escrever.
2. **DADO é dela; INTERFACE é do dono.** Valor gravado (principal, juros, multa, honorário, data de
   acordo, se está liquidada) tem de ser o dela. Como aquilo é somado, agrupado, rotulado e mostrado
   é produto — **não mexer**.

Exceção legítima já aceita: o recálculo ao vivo entre um relatório e o próximo (`EncargosVivos`) é
**projeção**, não julgamento. Não desmontar.

---

## Fatia 1 — a medição da violação #3 (data do acordo derivada)

**Status: FEITA em 17/08/2026. Só consulta (MCP `jusprime-prod`, somente leitura). Zero código.**

Esta fatia veio primeiro porque uma regra que **inventa data** não dá erro — dá número plausível.
Precisava ser medida antes de qualquer linha escrita.

### 1.1 O que o código faz

Três importadores criam `Acordo`. Dois **derivam** a data; um **lê** a data da planilha:

| importador | o que grava em `dataAcordo` |
|---|---|
| `ImportarRelatorioCarteiraUseCase:384` | `dataAcordoPadrao()` — 1º dia do mês da competência |
| `ImportarReceitasUseCase:461` | `dataAcordoPadrao()` — idem |
| `ImportarAcordosDetalhadosUseCase:506` | `$aba->dataBase` — a coluna **"Data base"** da planilha |

O terceiro **se recusa a criar acordo sem essa coluna** (`:478-483`), e o motivo escrito no próprio
código é a acusação mais limpa que esta frente tem contra os outros dois:

> *"é a data em que os juros das dívidas renegociadas param de correr, **e ela não se adivinha**."*

Os outros dois adivinham.

### 1.2 O mecanismo que faz o chute vencer — e é estrutural, não acidental

O `Acordo` é **compartilhado**: os três importadores o resolvem por `(carteira, numeroExterno)`.
E `setDataAcordo($aba->dataBase)` existe **só no ramo de criação** (`:498-506`). O ramo que encontra
acordo já existente (`:390-407`) atualiza parcelas e status, e **nunca reescreve a data**.

Consequência: **quem cria primeiro fixa a data para sempre.** Quando a inadimplência ou as receitas
criam o acordo antes, o chute entra — e o relatório que **tem** a data verdadeira já não consegue
corrigi-lo. O dado certo existe na fonte, chega ao sistema, e é descartado.

### 1.3 O tamanho — medido em produção, 17/08

A assinatura do chute é `data_acordo` cair no **dia 1** do mês.

| carteira | acordos | dia 1 (chute) | data real |
|---|---|---|---|
| TOP LIFE I | 325 | **311** | 14 |
| TOP LIFE II | 37 | **35** | 2 |
| AMLI BR 060 | 33 | **29** | 4 |
| **total** | **395** | **375 (94,9%)** | **20** |

O acaso explicaria ~13 (1/30). São 375. E os 20 com data real **todos** têm
`valor_total_negociado` preenchido — a marca do importador de acordos detalhados, o único que
sempre grava esse campo. Os dois fatos casam com o mecanismo de §1.2.

### 1.4 🔴 O chute MOVE DINHEIRO — o comentário do código está errado

`ImportarReceitasUseCase:458` afirma: *"Não move dinheiro: `dataAcordo` aqui é descritiva — só o
`CriarAcordoUseCase` a usa para materializar encargos, e ele não passa por este caminho."*

**A afirmação é verdadeira sobre aquele importador e falsa sobre o sistema.** Existe um segundo
consumidor, escrito depois: `ImportarAcordosDetalhadosUseCase::materializarNaDataDoAcordo`
(`:1056-1071`) chama `definirEncargos(..., $acordo->getDataAcordo())` nas obrigações que o acordo
substitui. Ele lê a data que **já estava lá** — inclusive a chutada.

Medido em produção:

| carteira | obrigações substituídas | materializadas na data do acordo | dessas, sobre data **chutada** | encargos gravados |
|---|---|---|---|---|
| TOP LIFE I | 3.604 | 3.604 (100%) | 3.448 | R$ 199.841,70 |
| TOP LIFE II | 99 | 99 (100%) | 90 | R$ 1.967,40 |
| AMLI BR 060 | 78 | 78 (100%) | 73 | R$ 1.455,97 |
| **total** | **3.781** | **3.781 (100%)** | **3.611** | **R$ 203.265,07** |

### 1.5 🔴 A prova irrefutável: 256 dívidas com encargo ZERO por data impossível

Em **256** obrigações substituídas a data do acordo é **anterior ao vencimento da própria dívida que
ele renegocia** — todas com data chutada. E **todas as 256 têm R$ 0,00** de juros + multa + correção
+ honorário.

| carteira | obrigações | principal | dias em que o acordo precede o vencimento (média) | pior caso |
|---|---|---|---|---|
| TOP LIFE I | 229 | R$ 47.094,86 | 124 | **679 dias** |
| AMLI BR 060 | 15 | R$ 2.575,50 | 9 | 9 |
| TOP LIFE II | 12 | R$ 2.040,00 | 13 | 26 |
| **total** | **256** | **R$ 51.710,36** | — | — |

107 delas precedem o vencimento **por mais de um mês**.

A calculadora está certa: dada uma data anterior ao vencimento, o encargo é mesmo zero. **O defeito é
a data.** Com a "Data base" verdadeira — necessariamente posterior — essas dívidas teriam encargo.

Config das três carteiras: **tolerância juros/multa = 0 dias**, multa 2%, juros 1%/mês, carência de
honorários 30 dias, honorários 15% (TL2/AMLI) e 20% (TL1).

Como a tolerância é **zero**, qualquer data real posterior ao vencimento dispara no mínimo a multa de
2%. Piso da subcobrança: **≈ R$ 1.034,21** (2% × R$ 51.710,36), mais juros de 1%/mês, mais 15–20% de
honorário onde passar dos 30 dias. ⚠️ **Isto é estimativa, não medição** — o número exato exige as
datas reais, que estão na planilha de acordos, não no banco.

### 1.6 O veredito sobre a hipótese que motivou esta fatia

A hipótese era: *"a #1 e a #3 podem ser o mesmo defeito visto de dois lados — se a data que gravamos
está errada, o zero de honorário pode ser sintoma, não causa."*

**Refutada como identidade, confirmada como direção.** Não são o mesmo defeito:

- A **#1** zera o honorário da **parcela de acordo** por atribuição direta (`honorariosBp = 0`
  gravado à mão). É independente da data — corrigir a data não conserta um único caso da #1.
- A **#3** erra a data das **obrigações substituídas** (as dívidas antigas renegociadas), população
  diferente, e tem impacto próprio e independente.

As duas subcobram, e se somam. **A #3 não é sintoma da #1: é uma quarta perna da mesma mesa.**

Sinal complementar, **sugestivo e não conclusivo** (as populações diferem em tamanho e origem):
honorário zero em 614 de 3.611 substituídas sob data chutada (17,0%) contra 11 de 170 sob data real
(6,5%).

### 1.7 O que esta medição muda no plano da frente

1. **A #3 sobe de prioridade.** Entrou como "nunca medido"; sai com R$ 203.265,07 de encargo
   assentado sobre data inventada e 256 dívidas zeradas. Não é mais a última da fila.
2. **O conserto é apagar, como o dono previu.** `dataAcordoPadrao()` deve **sumir** dos dois
   importadores. O que a fonte não dá, o sistema não inventa — é a regra que o importador de acordos
   detalhados já aplica (`:478-483`). Nenhuma regra nova entra no lugar.
3. **Falta uma decisão que é do dono, não minha** (§1.8).
4. **Há um segundo conserto, estrutural**, que nenhuma das 5 violações listadas cobria: o ramo de
   atualização de `ImportarAcordosDetalhadosUseCase` precisa **corrigir a data** de acordo
   pré-existente quando a planilha traz "Data base". Sem isso, os 375 acordos já gravados continuam
   errados mesmo depois de `dataAcordoPadrao()` morrer.
5. **A #3 também estava duplicada em dois importadores**, como a #1 e a #5 — consertar um só deixa o
   defeito vivo pelo outro.

### 1.8 ⏳ Aberto para o dono — não decidir sozinho

Tirar `dataAcordoPadrao()` faz o importador **recusar** criar acordo quando a fonte não traz data,
exatamente como o de acordos detalhados já recusa. Isso é o espelho correto, mas tem preço: parcelas
de acordo que hoje entram passariam a ficar de fora até o relatório de acordos chegar.

A pergunta para o dono é de produto, e as duas respostas são defensáveis:

- **(a) recusar** — espelho puro; o acordo só nasce com a data verdadeira. Custo: dívida que hoje
  entra fica esperando.
- **(b) criar sem data**, marcado como pendente, e a data chega depois pelo relatório de acordos.
  Custo: exige `dataAcordo` anulável (hoje é `NOT NULL`) — migration numa coluna que 395 linhas usam.

Há ainda a pergunta do passivo: **os 375 acordos já gravados e as 256 dívidas zeradas são
reprocessados?** É dinheiro que sobe, e reconciliação de dado histórico em produção é decisão do dono
— o mesmo padrão da decisão #8 (R$ 7.227,62).

### 1.9 Como reproduzir esta medição

Via MCP `jusprime-prod` (SELECT apenas). Assinatura do chute:

```sql
-- §1.3 — quantos acordos caem no dia 1
SELECT car.nome, COUNT(*) AS acordos,
       COUNT(*) FILTER (WHERE EXTRACT(DAY FROM a.data_acordo) = 1) AS chute
FROM cobranca_acordo a
JOIN cobranca_caso c ON c.id = a.caso_id
JOIN cobranca_objeto o ON o.id = c.objeto_id
JOIN cobranca_carteira car ON car.id = o.carteira_id
GROUP BY 1;

-- §1.5 — as 256 com data impossível e encargo zero
SELECT COUNT(*), SUM(ob.valor_original),
       SUM(ob.juros+ob.multa+ob.correcao+ob.honorarios) AS encargos
FROM cobranca_obrigacao ob
JOIN cobranca_acordo a ON a.id = ob.acordo_substituto_id
WHERE a.data_acordo < ob.vencimento_original
  AND EXTRACT(DAY FROM a.data_acordo) = 1;
```

⚠️ A cadeia é `obrigacao → caso → objeto → carteira`; `carteira_id` mora no **objeto**. E
`definirEncargos()` grava em `encargos_atualizados_em`, **não** em `encargos_congelados_em` — esta
última só é preenchida na liquidação (medido: 8.786 = exatamente as liquidadas). Consultar a coluna
errada devolve zero linhas e faz parecer que a materialização nunca rodou.
