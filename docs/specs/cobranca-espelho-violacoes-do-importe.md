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

### 1.9 Como reproduzir esta medição (ver também §2.9)

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

---

## Fatia 2 — a decisão (B) e a auditoria dos pontos de chamada

**Decisão do dono, 17/08:** gravar o acordo **mesmo sem data**.

> "Se existe o acordo e o sistema é o espelho, se não tiver data, então a lógica é que, se no sistema
> da contabilidade houver algum acordo sem data, o nosso sistema tem que gravar esse acordo sem data.
> Simples."

Recusar a criar é o sistema julgando que um registro incompleto não merece existir — a mesma espécie
de opinião que esta frente veio remover. A opção (A) foi descartada: era preferência por custo de
implementação, e custo não vence princípio.

### 2.1 A (B) e a 6ª violação saem JUNTAS — separá-las piora o estado atual

Dois casos que a implementação trata como um fluxo só:

1. a contabilidade tem o acordo e ele **genuinamente não tem data** → grava sem data, fim;
2. o acordo **tem** data, mas o relatório lido naquele instante (receitas / inadimplência) **não
   carrega o campo** — a data existe, está no relatório de acordos.

No caso 2, gravar em branco e parar ali não é espelho: é copiar um relatório incompleto e ignorar o
outro. Como o ramo de atualização nunca reescreve `dataAcordo` (§1.2), o campo **nasceria em branco e
ficaria em branco para sempre**. Entregar a (B) sem a 6ª violação é entregar um estado pior que o
atual.

**Comportamento-alvo, sem opinião embutida:** o acordo é registrado assim que aparece, com o que a
fonte deu; o que falta fica **em branco e visível**, nunca inventado; quando chega o relatório que tem
o dado, ele **preenche**; se nunca chegar, continua em branco — quem julga é a gerência.

### 2.2 🔴 A armadilha silenciosa: `null|date` no Twig imprime HOJE

Verificado no container em 17/08, não deduzido:

```
Twig  null|date('d/m/Y')  => [17/08/2026]      <-- a data de hoje
Twig  data real           => [15/03/2020]
```

O filtro `date` do Twig converte `null` para `"now"`. Os **4 templates** que formatam a data passariam
a **inventar uma data na tela**, sem erro, sem aviso — a mesma violação que esta frente remove do
importador, reaparecendo na view. É o achado mais perigoso da auditoria justamente porque é mudo.

| template | linha |
|---|---|
| `cobranca/acordo/show.html.twig` | 3 (title) e 13 (`<h1>`) |
| `cobranca/objeto/_partials/_movimentos.html.twig` | 143 |
| `cobranca/objeto/_partials/_divida.html.twig` | 325 |

Os quatro exigem guarda explícita (`{% if %}` com rótulo de vazio). **Nenhum teste atual pega isso** —
a suíte lê HTML e um `17/08/2026` é HTML perfeitamente válido.

### 2.3 O que quebra — inventário dos pontos de chamada

Tipagem não-anulável se propaga da entidade até a tela. Tudo abaixo é `\DateTimeImmutable` estrito:

| # | ponto | o que quebra | conserto |
|---|---|---|---|
| 1 | `Acordo::$dataAcordo` (`:50`) | `#[ORM\Column(type:'date_immutable')]` **NOT NULL** + propriedade não-anulável | coluna anulável + `?\DateTimeImmutable` |
| 2 | `Acordo::__construct` (`:123`) | **`$this->dataAcordo = new \DateTimeImmutable()`** — a entidade **inventa `now()`** por padrão | remover o default; nascer nula |
| 3 | `Acordo::getDataAcordo()` (`:225`) | retorno `\DateTimeImmutable` | `?\DateTimeImmutable` |
| 4 | `Acordo::setDataAcordo()` (`:230`) | parâmetro não-anulável | aceitar `?` |
| 5 | `AcordoOutput::$dataAcordo` (`:21`,`:54`) | readonly não-anulável | `?` |
| 6 | `AcordoDetalheOutput::$dataAcordo` (`:33`) | idem | `?` |
| 7 | `GrupoAcordoObrigacoesOutput::$dataAcordo` (`:32`) | idem | `?` |
| 8 | `MontarDetalheAcordoUseCase:99` · `MontarDetalheCasoUseCase:473` | repasse | segue após 5–7 |
| 9 | `AcordoRepository::doCaso:95` | `orderBy('a.dataAcordo','DESC')` — no PostgreSQL `DESC` põe **NULL primeiro**: acordo sem data lidera a lista | ordenação explícita |
| 10 | **`ImportarAcordosDetalhadosUseCase::materializarNaDataDoAcordo` (`:1056-1071`)** | calcula encargo **na** `dataAcordo` — ver §2.4 | §2.4 |
| 11 | `CriarAcordoUseCase:142,144,160` | usa `$input->dataAcordo` para materializar e para o vencimento da entrada | §2.5 |
| 12 | 4 templates | §2.2 | guarda explícita |
| 13 | `AcordoFactory` + ~14 arquivos de teste | fixam data não-nula | caso novo "sem data" |

**O item 2 é uma sexta violação que ninguém tinha listado:** a própria entidade inventa `now()` no
construtor. Hoje isso é mascarado porque todo caminho sobrescreve a data logo em seguida — mas é
exatamente a mesma opinião ("todo acordo tem data, e na dúvida é hoje") gravada uma camada abaixo.

### 2.4 🔑 A resposta à pergunta do dono: sem data, o que a tela mostra de encargo?

`materializarNaDataDoAcordo` é chamado em **3 pontos** (`:773`, `:874`, `:1023`) e grava, nas
obrigações que o acordo substitui, o encargo **calculado na data do acordo**. Com data nula não há o
que calcular. E aqui há uma armadilha medida:

- `encargosCongelados()` é `encargosCongeladosEm !== null`, e essa coluna **só é preenchida na
  liquidação** (medido: 8.786 = exatamente as liquidadas). A obrigação substituída **não é congelada**;
- ela some da tela do exigível porque a *query* a exclui, **não** porque está congelada;
- logo, ela **não é re-hidratada** por `EncargosVivos::hidratar` — o que a tela exibe é o último
  snapshot que alguém gravou nela.

**Portanto: simplesmente pular a materialização deixa a linha exibindo o encargo da última vez que a
obrigação foi hidratada — um número calculado numa data arbitrária.** É o defeito que o comentário de
`:1047-1052` já descreve ("o cache da última vez que alguém abriu a tela"). Trocar uma data inventada
no importe por um número velho na tela não é espelho.

Coerente com a regra (**não exibir número inventado**), o alvo é: acordo sem data **não materializa** e
a obrigação substituída mostra **vazio/pendente**, não um valor. Isso exige um estado distinguível de
"materializada com R$ 0,00" — hoje os dois seriam indistinguíveis na coluna.

⚠️ **É o único ponto desta fatia que toca a exibição de dinheiro.** Como interface é do dono, a forma
do vazio (rótulo, cor, se some ou aparece esmaecido) volta para ele antes de virar tela.

### 2.5 O caminho MANUAL não é espelho — e não deve afrouxar

`CriarAcordoUseCase` nasce de um humano lavrando acordo na tela, não da contabilidade.
`CriarAcordoInput::$dataAcordo` já é `?\DateTimeImmutable`, mas carrega
`#[Assert\NotNull('Informe a data do acordo.')]`, e `AcordoCriarType` monta um `DateType` obrigatório.

**Recomendação: manter obrigatório no manual.** A regra "não inventar o que a fonte não deu" vale para
o importe; na tela a fonte é a pessoa, e ela sabe a data. Afrouxar aqui seria expandir escopo e abrir
caminho para dado incompleto por descuido — o oposto do objetivo. `:160`
(`$input->dataEntrada ?? $input->dataAcordo`) e `:142-144` seguem seguros com isso.

### 2.6 O que a auditoria NÃO encontrou

- Nenhum `ORDER BY` de relatório contábil depende de `dataAcordo` além do `:95`;
- `Acordo::estaIncompleto()`/`parcelasFaltantes()` usam `numeroParcelasTotal`, não a data;
- a régua do espelho (`app:cobranca:espelho:*`) não lê `dataAcordo`;
- `EncargosVivos` **não** a lê (usa `hoje` e o vencimento da obrigação) — a exceção legítima do §1
  segue intacta, como o dono exigiu.

### 2.7 Ordem de execução obrigatória

A migration é o **último** passo de escrita, não o primeiro:

1. tipos anuláveis na entidade + DTOs (itens 1–8) e remoção do `now()` do construtor;
2. guardas nos 4 templates (§2.2) — **antes** de qualquer dado nulo existir;
3. `dataAcordoPadrao()` **apagado** dos dois importadores (violação #3);
4. ramo de atualização passa a preencher a data quando a planilha a traz (6ª violação, §2.1);
5. decisão do §2.4 aplicada (não materializar sem data);
6. testes, inclusive o de reintrodução do defeito;
7. **migration** `data_acordo` → anulável;
8. simulação com números **antes** de qualquer coisa em produção.

### 2.8 Passivo (375 acordos + 256 dívidas)

**Conserto obrigatório, não pendência de decisão** — mesmo caso da decisão #8, que o dono já resolveu
(o sistema espelha a contabilidade mesmo quando o resultado sobe). Do dono é o *quando* e o *como
comunicar*, não o *se*. Simulação com números antes de aplicar; nada roda em produção sem ele.

### 2.9 Frente

`cobranca-data-acordo-espelho`, cortada do **master local** (não de `origin/master`: este está 2
commits atrás, sem o `460e58af` da Fatia 1 nem o `37399179` de outra sessão). Tem migration —
registrada em `docs/frentes-ativas.md`.

### 2.10 Implementação — 1ª entrega (commit `74dd6ee9`). ⚠️ NÃO era a conta fechada

O traço com o motivo NA LINHA (decisão do dono) entrou na **célula do Total**, não na faixa de
encargos: a obrigação substituída é renderizada **sem** a faixa — só com o Total —, e
`totalComHonorarios` **soma** os encargos residuais. A auditoria do §2.3 não tinha pegado isso; quem
pegou foi o teste, ao ficar vermelho procurando o motivo numa tela que não o mostrava.

Segunda correção vinda do mesmo teste: `encargosNaoCalculados()` precisou virar **vigente-aware**. O
vínculo `acordoSubstituto` nunca é apagado (invariável 14), então um acordo **rompido** deixaria a
obrigação marcada como "não calculada" para sempre — quando na verdade ela volta ao exigível e passa a
ser hidratada ao vivo, com encargo real.

**Os 6 defeitos provados por reintrodução** (teste verde não prova nada):

| defeito reintroduzido | teste que fica vermelho |
|---|---|
| construtor voltando a gravar `now()` (7ª) | `AcordoSemDataTest` |
| guarda `{% if g.dataAcordo %}` removida | `AcordoSemDataNaTelaTest` — acha a data de HOJE no HTML |
| traço do Total removido | idem — o total com encargo residual reaparece |
| `ORDER BY dataAcordo DESC` sem o `HIDDEN` | idem — o sem data lidera |
| inadimplência voltando a chutar (#3) | `ImportarAcordosDetalhadosTest` |
| ramo de atualização não preenchendo (6ª) | idem |

⚠️ **A tabela acima foi escrita como conta fechada e não era.** Duas revisões depois dela acharam 1 🔴,
2 buracos de prova e 8 achados médios. Ver §2.11.

### 2.11 As duas revisões — e o padrão que elas expuseram

**1ª revisão (17/08), 10 achados; correções em `00deed6c`.** O crítico: `MontarDetalheAcordoUseCase`
somava `valorExigivel()` das substituídas, e sob acordo sem data isso é o **resíduo da última
hidratação**. Alimentava três números na tela do acordo — a coluna "Valor", o card "Dívida
substituída" e o "Desconto concedido/Juros acrescidos", este com sinal e cor. Era a **repetição
literal do caso `null|date`**: a mudança de estado achou um caminho que a auditoria dos pontos de
chamada (§2.3) não percorreu. Correção: por linha, o **principal** com traço e motivo; nos agregados,
**não apurável** (`?int` nulo).

**2ª revisão (18/08), 10 achados; correções em `1a8f2bc7`.** O que ela expôs é mais importante
que os achados:

> 🔴 **É a SEGUNDA vez nesta frente que uma correção entra declarada como provada e não está.**
> Na 1ª foi o laço do backfill; na 2ª foi o `!encargosCongelados()`. Nas duas, a suíte inteira ficava
> verde com a correção apagada.

Daí a regra que passa a valer aqui: **prova por reintrodução EXECUTADA, nunca rastreada por leitura** —
apagar a correção, ver vermelho, restaurar, ver verde, e dizer qual teste morreu. Ela pegou, na
própria rodada de conserto, um assert que mirava o número errado (`189,77`, quando o valor que sai ao
reverter é `100,00`, porque a Σ já acumula o principal) e um teste de console **vacuoso** (o cenário
criava acordo *com* data, então o bloco nunca era impresso).

**A decisão de dinheiro da 2ª rodada** (dono, 18/08) — congelamento tem **duas origens**, e elas não
são a mesma coisa na tela:

| estado | o que a tela mostra | por quê |
|---|---|---|
| **liquidada** (`liquidadaEm` preenchido) | **o número** | o snapshot é fato histórico: o valor pelo qual a dívida foi quitada. Escondê-lo apagaria informação real |
| **congelada sem `liquidadaEm`** (legado) | **o traço** | snapshot de data desconhecida — o "número velho" que esta fatia existe para não exibir |

O predicado testa `estaLiquidada()`, **não** `encargosCongelados()`. O argumento que fecha: **o dado
distingue os dois casos, então o sistema não precisa julgar.**

Também corrigido: o dry-run **afirmava escrita não feita** ("os encargos foram calculados" sem
`--confirmar`) — mesma família de defeito que a frente combate, o sistema afirmando o que não
aconteceu.

### 2.12 A 3ª revisão — e o que a medição de produção corrigiu na decisão

**3ª revisão (18/08).** Nada de runtime provado, mas três buracos da mesma família que já passou duas
vezes por aqui:

- **a prévia podia gravar.** O bloco do backfill faz `setDataAcordo` + `salvar(flush)` + materializa, e
  a única barreira era o guard `if ($usuario !== null)` — **sem assert nenhum**, num caminho que
  `cenarioAcordo37` percorre em todo teste da classe. Agravante: `prever()` **não roda em transação**,
  então uma regressão ali ficaria gravada. A casa já tem a regra "prévia que só consulta o banco
  mente"; este é o inverso, e é pior.
- **a cláusula `ehVigente()`** era a **terceira** do mesmo método a entrar sem prova.
- **o `catch` de `DataDoAcordoObrigatoriaException` era inalcançável** (`Assert\NotNull` + `isValid()`),
  e a justificativa escrita ("sem ela, 500") não era reproduzível por aquele caminho. **Revertido** — o
  próprio arquivo declara que catch morto apodrece, e defesa não-testável é dívida, não proteção. A
  exceção de domínio fica, protegendo o contrato do UseCase.

🔑 **A medição que corrigiu a decisão do dia anterior.** O dono mediu em **produção**:

| | |
|---|---:|
| congeladas **sem** liquidação (o 2º ramo da decisão de 18/08) | **0** |
| congeladas **e** liquidadas | 8.788 |
| universo | 17.061 |

Zero também no dev, e `liquidar()` é o **único** chamador de `congelarEncargos()` em `src/` — o estado
**não é produzível por código atual**. Consequências:

1. **O predicado fica como está** (`estaLiquidada()` continua semanticamente certo e não custa nada),
   **mas o 2º ramo governa ZERO linhas hoje**: é defesa contra dado legado, não conserto de problema
   vivo. Contá-lo como entrega seria inflar a lista.
2. **O badge parou de prometer.** Ele dizia que os encargos "ficam sem calcular **até a data chegar**",
   e para a congelada isso nunca aconteceria (`materializarNaDataDoAcordo` retorna cedo em
   `encargosCongelados()`). Texto novo: *"ficam sem calcular; a congelada mantém o snapshot que tem"*.
   **Não** se mexeu no early-return: alterar obrigação congelada — intocável por desenho — para atender
   zero casos é risco sem contrapartida.

### 2.13 ⚠️ Defeito PRÉ-EXISTENTE encontrado de passagem — NÃO consertado nesta fatia

`ImportarRelatorioCarteiraUseCase:260` chama `materializarEncargosImportados` na **reimportação** sem
checar `estaLiquidada()`/`encargosCongelados()`, e `definirEncargos` sobrescreve os quatro encargos
**mantendo `liquidadaEm`**. Ou seja: uma obrigação liquidada pode ter o snapshot da quitação
sobrescrito por uma reimportação.

E o comentário em `:257` diz **"RE-CONGELA na data nova"** — `definirEncargos` não congela nada.

Medido no dev: **0 de 319** liquidadas com `encargos_atualizados_em != liquidada_em`. Não medido em
produção.

🔴 **Fica registrado por causa do padrão, não do tamanho:** comentário que afirma o que o código não
faz já custou caro nesta frente — foi o `"dataAcordo não move dinheiro"` que escondeu a violação #3.

⏳ **Pendente e fora desta fatia:** o passivo (375 acordos + 256 dívidas). É conserto obrigatório, com
simulação de números antes e autorização do dono para rodar. Nada foi tocado em produção.
