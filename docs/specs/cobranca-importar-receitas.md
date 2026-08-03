# Importar "Receitas detalhadas por unidade/cliente" — o 4º relatório

**Risco:** ALTO (cria pagamento e obrigação — mexe em dinheiro)
**Data:** 2026-08-03 · **Etapa 2 de 3** da retomada da cobrança
**Handoff de origem:** `docs/gestao-cobrancas/HANDOFF_IMPORTAR_RECEITAS.md` (⚠️ ver §2, que o corrige)

> ⛔ **LEIA A §11 ANTES DE RODAR A IMPORTAÇÃO.** Em 03/08 o dono descobriu que o NN não é boleto avulso:
> é **parcela de acordo**. 187 recebimentos entrariam como dívida solta. **A importação não deve rodar
> até a etapa 3** (decisão A3).

---

## 1. O que este relatório responde, e por que ele muda o sistema

É o **4º e último** relatório da contábil. Os outros três dizem *o que é devido*; este diz **o que foi
pago**. Sem ele, todo recebimento no sistema é digitado à mão e sem conferência externa.

🔑 **Mas a medição mostrou que ele não encaixa onde o handoff supunha.** Medido nos arquivos de 03/08:

| | TOP LIFE I | TOP LIFE II | total |
|---|---|---|---|
| NNs distintos com recebimento válido | 1.220 | 858 | **2.078** |
| NN existe no acervo, ignorando carteira e competência | 1 | 79 | 80 |
| **casam de verdade, por `(caso, NN, competência)`** | **1** | **3** | **4 (0,2%)** |
| Acordos citados na coluna J | 93 | 34 | **127** |
| Acordos que já existem no sistema | 0 | 8 | **8** |

⚠️ **O "80 (3,8%)" desta tabela era um artefato, e é a quarta medição desta spec a cair.** Remedido em
03/08 contra o banco: dos 79 NNs da TOP LIFE II que "existem", **76 são homônimos de outro ano e de
OUTRA carteira** — NN `60083` da planilha é `01/2026`, o do banco é `01/2022`; `61498` é `07/2026` contra
`08/2022`, e assim por diante. Não são o mesmo boleto.

O overlap verdadeiro é **4 em 2.078**, e ele **reforça** a conclusão da seção em vez de enfraquecê-la:
o casamento é ainda mais residual do que se supunha, e é R1 que sustenta o histórico inteiro.

🔑 **Duas defesas independentes impedem esses 76 de virarem pagamento no boleto errado** (R$ 16.862,80 de
dívida viva, medido). Nenhuma das duas é acidente, mas nenhuma estava registrada:

1. **o casamento é por CASO**, dentro da carteira informada no comando — e os 76 estão na outra carteira,
   fora de alcance por construção;
2. **a competência compõe a chave** — se algum dia estiverem na mesma carteira, é ela que separa o
   `01/2026` do `01/2022`.

Medido também: das 33 obrigações do acervo com **competência nula** (que ativariam o fallback legado de
`findOnePorReferenciaECompetenciaNoCaso`, casamento por NN sozinho), **nenhuma** tem NN que apareça nas
planilhas. O fallback não é alcançado por este importador hoje — mas é o caminho por onde a proteção (2)
deixaria de valer, então não é para relaxar nele.

**A causa é de construção, não defeito:** o sistema só conhece o que a **Inadimplência** traz, e a
Inadimplência só traz o que **não foi pago**. Boleto pago sai da inadimplência e nunca entrou aqui. A
Receitas fala justamente dos pagos. Os dois conjuntos são quase disjuntos por definição.

Logo, casar por `(caso, NN, competência)` — o desenho do handoff — pousaria em 4% das linhas e reportaria
96% como "NN não encontrado".

## 2. ⚠️ Correção de um fato que estava dado como resolvido

O handoff §1 registra, com medição:

> *"Incluir 'Aberta' não acrescentou uma linha sequer. Toda linha do relatório já tem `Recebimento`
> preenchido."*

**Isso era verdade no export de 01/08 e é FALSO no de 03/08.** Medido:

| Arquivo (03/08) | Linhas de dado | Com data de recebimento | Com `Recebimento = "-"` |
|---|---|---|---|
| `top_life_1_Receitas_..._2026_08_03_09_51_26` | 4.499 | 3.216 | **1.283** |
| `top_life_2_Receitas_..._2026_08_03_09_53_34` | 2.691 | 1.880 | **811** |

As 2.094 linhas ignoradas têm `Valor recebido = 0` — são boletos em **aberto**, não receita. Ignorá-las
deixou de ser defesa teórica e passou a ser a regra que separa receita de dívida.

⚠️ **Correção da correção (remedido em 03/08, com o dry-run).** Esta seção afirmava que essas linhas
somavam **R$ 2.045.780** na coluna H. **Esse número não se reproduz em arquivo nenhum.** Medido célula a
célula, fora do adapter:

| | TOP LIFE I | TOP LIFE II | total |
|---|---|---|---|
| linhas com `Recebimento = "-"` | 1.283 | 811 | **2.094** ✓ |
| soma da coluna H dessas linhas | R$ 147.015,35 | R$ 133.351,36 | **R$ 280.366,71** |
| soma da coluna H do arquivo INTEIRO | R$ 429.654,58 | R$ 285.147,04 | R$ 714.801,62 |

Não há dois milhões em lugar nenhum: o maior número de qualquer coluna dos dois arquivos é
R$ 429.654,58. **A regra continua certa e continua necessária** — R$ 280 mil de dívida entrando como
pagamento apagaria a cobrança de 2.094 boletos —, só a magnitude estava errada, por um fator de 7.

🔑 **A lição real é maior do que a original.** Não é só que fato medido vence: é que *número de dinheiro
escrito numa spec precisa ser reproduzível a partir dela*. O R$ 2.045.780 sobreviveu a duas leituras
desta spec porque ninguém tinha como recalculá-lo sem abrir a planilha — e a primeira medição desta vez
também errou (contou o RODAPÉ do relatório como se fossem 19 boletos em aberto), até ser conferida
contra o total que o próprio relatório imprime.

### 2.1 Por que o export mudou — medido, não suposto

A última linha de cada arquivo traz os filtros usados. Nos de 03/08:

> `Situação das contas: Aberta e baixada; Competência: Todas; Período de vencimento: 01/01/2026 a 01/01/2027; …`

Isso explica as duas mudanças de uma vez, e nenhuma delas é defeito da fonte:

- **`Aberta e baixada`** (nos de 01/08 era só "baixada") → aparecem as 2.094 linhas com `-`;
- **o período é de VENCIMENTO, não de recebimento** → entram boletos pagos **antes** de vencer, inclusive
  em **2025** (medido: recebimentos de 24/01/2025 a 31/07/2026), e competências até **12/2026**.

## 3. Decisões do dono (03/08)

| # | Decisão |
|---|---|
| R1 | **Boleto que o sistema não conhece: CRIAR a obrigação e já marcá-la paga.** O sistema passa a ter o histórico de faturamento, não só o que está em aberto. É o que dá base à etapa 3 — sem isso os 119 acordos da Receitas que o sistema não conhece continuam inexistentes. |
| R2 | **Coluna I (`Valor recebido`) é o dinheiro**, não a H. |
| R3 | **Pagamento importado é igual ao digitado**: sem marca de origem, apagável à mão pela etapa 1, e reimportar traz de volta. Coerente com "o importe é a verdade absoluta". |
| R4 | Os relatórios têm de ser da **mesma data**. Os de 03/08 (09:48–09:53) cumprem. |
| R5 | **O histórico pago fica em SEÇÃO PRÓPRIA na tela**, separado do que está em aberto. Aprovado em 03/08. |

### 3.1 O que R1 muda no significado do sistema, e por que R5 é condição

Hoje a tela do devedor mostra **o que ele deve** — tudo que está na lista é coisa a cobrar. Depois de R1
o sistema conhece também **o que ele já pagou**.

⚠️ **"no ano" caiu junto com o export de 01/08.** Esta seção dizia: *"os recebimentos vão de 02/01 a
31/07/2026, então a planilha é o ano corrente"*. Era verdade nos arquivos de 01/08 (remedido: 02/01 a
30/07/2026, zero linhas fora). **É falso nos de 03/08**: os recebimentos vão de **24/01/2025** a
31/07/2026, porque o filtro do export é por **vencimento** (ver §2.1) — boleto que vence em 2026 pode ter
sido pago em 2025.

Por isso a seção da tela se chama **"Já pago"**, sem recorte de ano (decisão do dono, 03/08): recortar
pelo ano civil esconderia o pagamento antecipado que a própria planilha traz, e esvaziaria a seção
sozinha em 1º de janeiro.

Volume medido no dry-run: **2.073 obrigações criadas** (1.219 + 854) e **127** acordos citados, contra
3.431 obrigações existentes hoje. **O acervo praticamente dobra.**

⚠️ **Se o pago cair na mesma lista do em aberto, a tela deixa de responder "o que eu cobro dele?".** Um
devedor com 7 boletos pagos e 3 em aberto passaria a mostrar 10 linhas, com as 3 que importam no meio.
Não é risco de dinheiro — obrigação paga entra no bruto e sai no alocado, saldo inalterado (medido) —
é risco de **leitura**, e o ganho do panorama se perderia justamente na tela onde ele deveria aparecer.

Daí R5: **em aberto** de um lado (o que cobrar), **já pago** do outro, com o total à mostra e o detalhe
sob demanda. O panorama entra sem custar a tela de cobrança, e ainda dá de graça uma informação que hoje
não existe: quanto aquele devedor já pagou.

**Decidido ANTES de importar de propósito:** enquanto não há boleto pago no sistema, isto é ajuste de
tela; depois de 2.078 linhas dentro, é ajuste de tela **mais** conferência do que já entrou.

#### Como R5 ficou (implementado em 03/08, commit `9f8b8df4`)

Quatro decisões do dono fecharam o desenho:

| | Decisão | Por quê |
|---|---|---|
| régua | **toda obrigação quitada**, sem olhar origem | R3 dispensou marca de origem; a régua é `ObrigacaoOutput::quitada()`, a MESMA que já pintava o chip "Paga" — a tela não passa a ter duas definições de pago |
| recorte | **nenhum** | ver a ⚠️ acima: o recorte por ano civil esconderia o pagamento antecipado |
| escopo | **só obrigações avulsas** | o acordo continua legível num lugar só, com as parcelas pagas e a pagar juntas; e não mexe no bloco que a etapa 3 vai tocar |
| lugar | **na aba Dívida, recolhida, abaixo do em aberto** | o total sempre visível responde à pergunta sem expandir |

⚠️ **A partição é ADICIONAL, nunca substitutiva.** `obrigacoesAvulsas` continua sendo a UNIÃO e continua
alimentando os cards do cabeçalho, a prescrição e o rodapé da aba Honorários — trocá-la pela partição
move números que ninguém pediu para mover (um teste guarda isso).

Consequências conscientes, ambas de coerência da tela:

- **pagamento PARCIAL fica na fila** — ainda se cobra;
- **o botão "Novo acordo" passou a olhar só o que está em aberto**. A checkbox de selecionar só existe
  nas linhas da fila; manter a união deixaria o botão habilitado num caso só de quitadas, e ele abriria
  o modal sem nada para marcar. Quitada não é acordável na prática: o `restante` é 0.

## 4. Layout (medido; idêntico ao do handoff, reconferido em 03/08)

Cabeçalho na **linha 7**, dados a partir da 8. Linhas 1–6 são cabeçalho do relatório.

| Col | Campo | Uso |
|---|---|---|
| A | Unidade | identificação do objeto, mesma das outras planilhas |
| B | Sacado | nome — **PII** |
| C | **NN** | chave do boleto |
| D | **Classe de Conta** | define o balde (ver §5) |
| E | **Competência** | compõe a chave com o NN |
| F | Vencimento | vencimento da obrigação criada em R1 |
| G | **Recebimento** | data do pagamento; `-` significa **em aberto → linha ignorada** |
| H | Valor (R$) | nominal — **não usado** (R2) |
| I | **Valor recebido (R$)** | **o dinheiro** (R2) |
| J | Informações do acordo | preenchida em 100% das linhas, mas só ~7% no formato `Acordo N - Parc. x/y` |

## 5. Classes de conta → baldes do pagamento

🔑 **A entidade `Pagamento` já tem exatamente a decomposição que a planilha traz** (`valorDivida` /
`valorEncargos` / `valorHonorarios`). Não é preciso inventar rateio: a contabilidade já rateou.

| Classe | Balde | Observação |
|---|---|---|
| `1.1`, `1.14` | `valorDivida` | taxa de condomínio — o principal |
| `1.4` | `valorEncargos` | juros |
| `1.5` | `valorEncargos` | multa |
| `1.6` | `valorDivida` | **desconto, sempre negativo** — abate o principal |
| `1.15` | `valorHonorarios` | honorário advocatício |
| `1.12`, `1.19`, `1.22` | `valorDivida` | raras (**11 linhas** nos dois arquivos, medido); balde do principal. Elas — e qualquer classe fora deste mapa — são contadas e o comando as imprime |

**Desconto quase nunca precisa de tratamento próprio** (remedido em 03/08): somando a coluna I por NN, o
líquido nunca dá negativo — mas **dá ZERO em um caso**, o NN `60082` da TOP LIFE II (taxa R$ 170,00
anulada por um desconto de R$ −170,00: boleto integralmente perdoado).

A versão anterior desta seção afirmava "nunca dá negativo **nem zero**", e isso é falso. O importador
rejeita a linha ("Recebimento com valor líquido não positivo") e **o total continua batendo ao centavo**,
justamente porque o líquido dela é zero — rejeitar não tira dinheiro nenhum da conta. A rejeição está
certa: um pagamento de R$ 0,00 não é receita e não deve virar linha de extrato.

**H × I divergem só em `1.4`, `1.5` e `1.6`**, nunca na taxa nem no honorário: nos juros/multa o recebido
é maior (acumulou até o pagamento), no desconto é mais negativo. É a evidência que sustenta R2.

## 6. Chave e idempotência

**Medido: cada NN tem EXATAMENTE UMA data de recebimento** nos dois arquivos (1.220 NNs → 1.220 chaves;
858 → 858). Então:

- **Um pagamento por NN**, agregando as ~2,3 linhas de classe daquele NN.
- **Chave de idempotência:** `(tenant, obrigação do NN, data de recebimento)`. Reimportar o mesmo arquivo
  não pode criar pagamento nenhum na segunda vez.
- A obrigação criada em R1 usa a chave já estabelecida `(caso, NN, competência)` —
  `ObrigacaoRepository::findOnePorReferenciaECompetenciaNoCaso`.

## 7. Caminho de execução

**CLI, não tela.** Pelo precedente da TOP LIFE I (2.901 boletos → 500 por timeout de 30s na tela), e
agora são 5.096 linhas válidas: `app:cobranca:importar-receitas`, com `APP_DEBUG=0` e `memory_limit`
alto. Em prod: `scp` → `docker cp` → `docker exec -w /var/www/app`.

**Par prever/confirmar**, com o mesmo cuidado de **estado intra-execução** que já falhou duas vezes nesta
frente: a prévia tem de simular o que a confirmação faria, inclusive o efeito de linhas anteriores da
MESMA execução (ver `feedback_previa_precisa_de_estado`).

## 8. Como se prova

- **reimportar o mesmo arquivo não cria pagamento nenhum** na 2ª vez;
- **prévia × confirmação idênticas em TODOS os campos** — o comparador os deriva por reflexão e um teste
  próprio prova que nenhum campo público do resultado ficou de fora. O número de campos não é escrito em
  lugar nenhum de propósito: já esteve como "13" em dois lugares e "18" aqui, com o comparador olhando 16;
- **linha com `Recebimento = "-"` é ignorada** — e o teste mede que o **VALOR** dela não entra em balde
  nenhum, não só que a linha não vira receita. Contar linhas passaria mesmo se o valor entrasse (§2);
- **grupo com duas datas ou duas competências é rejeitado**, não fundido — fundir escolheria uma data em
  silêncio e furaria a chave de idempotência;
- **classe de conta fora do mapa da §5** cai no principal, mas fica contada e o comando a imprime;
- um pagamento importado **abate exatamente** o mesmo que abateria digitado à mão;
- obrigação criada por R1 nasce **liquidada** com o valor recebido, e o saldo do caso não muda por causa dela;
- desconto negativo compõe o principal sem gerar pagamento negativo;
- **isolamento por tenant** em tudo;
- ⚠️ **a soma da coluna I por carteira bate com o total importado**, ao centavo.

**Viés de confirmação = a contabilidade.** Depois de importar, o saldo tem de contar a mesma história dos
outros três relatórios — e agora os quatro são da mesma data (R4), então a conferência é possível pela
primeira vez.

### 8.1 A conferência FEITA (dry-run de 03/08, nada gravado)

🔑 **O relatório imprime o próprio gabarito.** Depois da última linha de dado vem "Total de receitas das
unidades" e um quadro de **total recebido por classe de conta**. É contra ele que se confere — não contra
uma soma que eu mesmo faça com o meu adapter, que não conferiria nada.

Por isso o resumo do comando passou a mostrar os **três baldes**, e não só o total: conferir só o total
fecha mesmo com uma troca entre baldes (principal contado como encargo soma igual e rateia errado no
`Pagamento`, que é o que alimenta o split de honorários).

| | TOP LIFE I | confere contra | TOP LIFE II | confere contra |
|---|---|---|---|---|
| **Total recebido** | 243.013,53 | `Total de receitas` **✓** | 136.898,49 | `Total de receitas` **✓** |
| — principal | 228.867,89 | `1.1+1.12+1.14+1.19+1.22+1.6` **✓** | 135.486,55 | `1.1+1.19+1.6` **✓** |
| — juros e multa | 5.610,14 | `1.4+1.5` **✓** | 552,83 | `1.4+1.5` **✓** |
| — honorários | 8.535,50 | `1.15` **✓** | 859,11 | `1.15` **✓** |

**Os oito números batem ao centavo.** Total geral que entraria: **R$ 379.912,02**.

O que o dry-run diz que aconteceria (dev, 3.431 obrigações e **0 pagamentos** antes):

| | TOP LIFE I | TOP LIFE II |
|---|---|---|
| recebimentos registrados | 1.220 | 857 |
| — em obrigação que JÁ existia | **1** | **3** |
| — com obrigação CRIADA (R1) | 1.219 | 854 |
| já importados antes | 0 | 0 |
| unidades / pessoas / casos criados | 119 | 101 |
| rejeições | 0 | 1 (`60082`, líquido zero — ver §5) |

O overlap medido — **1 + 3 = 4** obrigações preexistentes — é o número real do casamento por
`(caso, NN, competência)`, e é o que substituiu o "80" da §1. Confirma a tese daquela seção (o
casamento é residual; é R1 que dá corpo ao histórico) por uma margem ainda maior: 0,2%, não 3,8%.

## 9. Fora de escopo

- **Reativação por importação (D6)** — etapa 3, só depois desta.
- **Marca de origem no pagamento** — dispensada por R3.

### 9.1 ✅ RESOLVIDA — "boleto sem principal" não existe: é PARCELA DE ACORDO

**O dono leu a coluna J e a pendência caiu.** Medido: dos 37 recebimentos sem principal da TOP LIFE I,
**37 são parcela de acordo**. Cem por cento. Ver §11, que reescreve o entendimento da fonte.

Numa parcela de acordo, não ter taxa é **normal** — o acordo distribui as dívidas originais ao longo das
parcelas, e a parcela 1 pode ficar só com honorário enquanto a 8 fica quase toda com taxa. O NN `75124`
não é um boleto de honorário órfão: é a **Parc. 1/40 do Acordo 348**.

A pergunta aberta desde 01/08 (*"medir antes se o boleto é acessório de um de taxa"*) tinha resposta na
própria planilha, na coluna que o adapter já lia e o UseCase descartava. **Nenhuma das três opções que
estavam listadas aqui era a certa** — nem aceitar, nem rejeitar, nem anexar a um boleto de taxa.

O que fica de aviso no comando continua útil (é sinal de parcela de acordo entrando como avulsa), mas
deixa de ser uma decisão pendente: passa a ser **escopo da etapa 3**.

<details><summary>Os números medidos, que seguem valendo como referência</summary>

A versão anterior desta seção falava em **R$ 4.390,86** na TOP LIFE I. Medido nos arquivos de 03/08, com
a mesma chave `(unidade, NN)` do adapter, o número é outro:

| | TOP LIFE I | TOP LIFE II |
|---|---|---|
| recebimentos sem principal nenhum | **37** | 1 (o `60082`, já rejeitado por líquido zero) |
| total recebido neles | **R$ 11.179,36** | — |
| destes, com **exigível zero** (só a classe `1.15`) | **10** | — |
| total recebido nesses 10 | **R$ 2.618,18** | — |

**O que acontece hoje se a importação rodar:** os 37 criam obrigação com `valorOriginal = 0`, descrita
como `Taxa MM/AAAA (NN …)` sem taxa nenhuma. Nos 27 que têm juros/multa o exigível é positivo, a
alocação bate e eles quitam certo. Nos 10 só-honorário o exigível é zero e a alocação vale **R$ 0,00** —
a linha aparece em "Já pago" com R$ 0,00 e o honorário fica visível só no extrato de Movimentos.

**Nenhum centavo se perde em nenhuma das hipóteses** — o total recebido fecha ao centavo com a
contabilidade de qualquer jeito (§8.1). O que está em jogo é a **forma** do que entra.

O comando conta e imprime um aviso com quantidade, valor e NNs antes de qualquer `--confirmar`. O aviso
continua valendo — mudou o que ele significa: não é "decisão pendente sobre boleto estranho", é
**"parcela de acordo entrando como dívida avulsa"**, que a etapa 3 resolve.

</details>

### 9.3 ⏸️ Recebimento MAIOR que o exigível da obrigação preexistente

Medido no dry-run: dos 4 recebimentos que pousam em obrigação já conhecida, **3 pagam mais do que ela
exige** — porque os encargos que o sistema calculou não são os que a contabilidade cobrou.

| NN | exigível no sistema | a planilha alocaria | diferença |
|---|---|---|---|
| `76612` (TL I) | R$ 208,31 | R$ 202,16 | −R$ 6,15 (sobra dívida) |
| `61161` (TL II) | R$ 174,82 | R$ 175,44 | **+R$ 0,62** |
| `61314` (TL II) | R$ 174,82 | R$ 175,02 | **+R$ 0,20** |
| `61239` (TL II) | R$ 174,82 | R$ 175,62 | **+R$ 0,80** |

O importador aloca o valor **cheio**, e isso é deliberado: a régua do dono é "o que vem da planilha
entra", e o total tem de bater ao centavo (§8) — limitar ao exigível faria o excedente sumir e o total
deixar de fechar. É também o que o sistema já faz com alocação manual, que nunca teve teto por obrigação.

**Efeito medido** (teste `testRecebimentoMaiorQueOExigivelAlocaOValorCheio`): o `restante` da linha tem
piso 0 e não aparece negativo, mas o excedente **abate o saldo do caso**, que fica negativo se não houver
outra dívida. São R$ 1,62 nos 4 casos do dev. ⚠️ **Reconferir em produção antes do `--confirmar`** — o
número de casos lá não é conhecido, e este é o único ponto da etapa em que o dinheiro passa do alvo.

### 9.2 ⏸️ Obrigação de R1 REABERTA volta a crescer pela carteira

Achado da revisão, medido no código. A obrigação criada por R1 nasce liquidada e **congelada**, então a
cascata de encargos não a toca — isso está garantido por `Obrigacao::liquidar()`, não por configuração.

Mas `reabrir()` (exclusão do recebimento, etapa 1, já no master) **descongela**. A partir daí ela volta a
acumular juros/multa/honorários pela taxa da **carteira**, não pela da planilha. Um `$input->honorariosBp = 0`
existia no código com um comentário dizendo que travava isso; a linha era **inerte** (`modoHonorarios`
fica em `'herda'` e o bp submetido é descartado) e foi removida junto com o comentário falso.

É decisão de produto, não defeito: boleto reaberto virou dívida viva de novo, e travar em zero seria
decidir por conta própria que histórico reaberto nunca acumula encargo. **Fica para o dono.**

⚠️ **Correção da 2ª revisão: para os 37 sem principal, o risco é o OPOSTO do escrito acima.**
`EncargosVivos` recalcula os encargos sobre `getValorOriginal()`. Numa obrigação de R1 com
`valorOriginal = 0`, o recálculo dá **zero** — então `reabrir()` não a faz crescer: ela some. O exigível
volta a 0, `quitada()` volta a ser verdadeira (0 ≥ 0) e a obrigação **permanece na seção "Já pago" mesmo
depois de o recebimento ter sido apagado**, em vez de voltar para a fila de cobrança.

Medido: esses 37 carregam R$ 3.970,25 de juros/multa e R$ 7.209,11 de honorário congelados, que o
recálculo zera. Não é perda de dinheiro — o boleto valia zero de principal —, é um registro que fica
inconsistente com o ato de desfazer. Some à mesma decisão da §9.1: se o dono resolver rejeitar ou anexar
os sem-principal, isto desaparece junto.

## 10. Estado

**Ao abrir a frente:** `master` local em `40c3e05a` (etapa 1 commitada), 6 commits não publicados,
suíte 3136/3136.

**Em 03/08, ao fechar a etapa 2** (R5 + dry-run + conferência + duas revisões com correção entre elas):

- **15 commits não publicados**. Nada em produção.
- Suíte **3169/3169**. `lint:twig`, `lint:container` e `doctrine:schema:validate --skip-sync` verdes.
- **Sem migration** nesta etapa.
- **Nada foi gravado**: todas as execuções contra as planilhas reais foram dry-run.
- Planilhas de 03/08 09:48–09:53, as três de cada carteira, **mesma data** — gitignored, PII.

⚠️ Esta lista já nasceu velha uma vez (dizia `fd76b8d8` / 11 commits / 3155 dois commits depois de deixar
de ser verdade). Se ela divergir de `git rev-list --count origin/master..HEAD` e da suíte, **acredite nos
comandos, não nela.**

### 10.1 O que a etapa 2 derrubou desta própria spec

**Cinco** fatos escritos aqui como "medido" caíram ao serem remedidos:

| Onde | Dizia | É |
|---|---|---|
| §2 | as linhas em aberto somam **R$ 2.045.780** | **R$ 280.366,71** — o número não se reproduz em arquivo nenhum |
| §3.1 | *"a planilha é o ano corrente"* | há recebimento de **2025**: o filtro do export é por vencimento |
| §5 | o líquido por NN *"nunca dá negativo nem zero"* | dá **zero** em 1 caso (NN `60082`) |
| §1 | overlap de **80 (3,8%)** | **4 (0,2%)** — 76 eram homônimos de outro ano e outra carteira |
| §1 | *"106 acordos citados, **zero** existem"* | **127** citados, **8** já existem (ver §11.2) |

Os três primeiros vinham do export de 01/08 e eram verdadeiros nele. O quarto nunca foi verdadeiro: era
uma contagem de NN sem competência nem carteira, isto é, a medição errada da coisa certa.

**Nenhum dos quatro derrubou uma decisão.** As regras — descartar o `-`, seção sem recorte, rejeitar
líquido não-positivo, criar a obrigação ausente — seguem certas, e três delas ficaram mais bem
fundamentadas depois de remedidas: o overlap real de 0,2% **reforça** R1 em vez de enfraquecê-lo. O que
caiu foram os **números**.

🔑 **O padrão, agora com quatro casos:** todo número de dinheiro desta spec precisa dizer *como* foi
medido, senão sobrevive por não ser recalculável. E a medição precisa ser conferida contra algo externo —
foi o total impresso no rodapé do relatório que pegou o erro da minha própria primeira medição.

### 10.2 O que as duas revisões acharam, e o que isso diz

| | 1ª passada | 2ª passada |
|---|---|---|
| bloqueantes | 1 (boleto sem principal aceito em silêncio) | 1 (o aviso da 1ª saía **depois** da gravação) |
| defeitos em teste que "passava" | 1 (teste do descarte media só contagem) | 3 (assert vacuoso, guarda tautológica, contador sem cenário) |

🔑 **A 2ª passada existiu para isso.** Metade do que ela achou foram defeitos **nas correções da 1ª** — e
o mais grave é que a correção do bloqueante B1 tinha sido aplicada no lugar errado do fluxo, entregando
um aviso pós-fato. Corrigir sem re-revisar teria fechado a etapa com o bloqueante intacto e a sensação
de resolvido.

O segundo padrão é mais desconfortável: **três dos quatro defeitos de teste eram asserts que não podiam
falhar.** Um deles foi escrito *nesta sessão, para corrigir exatamente esse tipo de problema*, e a
"prova por injeção de defeito" que o acompanhou falhou por carona em outro assert — a injeção quebrava o
teste, só que não pelo motivo alegado. Não basta injetar o defeito e ver vermelho: é preciso conferir
que o vermelho veio do assert que se quer provar.

---

## 11. 🔑 A estrutura de ACORDO da fonte — descoberta em 03/08, define a etapa 3

**Achado pelo dono, lendo a coluna J.** Reescreve o entendimento do relatório e resolve a §9.1.

### 11.1 O NN não é um boleto avulso: é uma PARCELA DE ACORDO

A fonte tem **três** níveis, não um:

```
Acordo 348                       ← coluna J: "Acordo 348 - Parc. 1/40"
  ├─ Parc. 1/40 = NN 75124       ← o boleto DAQUELA parcela (venc. 10/03/2026, comp. 03/2026)
  │    ├─ 1.15  R$  72,67        ← a COMPOSIÇÃO: de que dívidas a parcela é feita
  │    ├─ 1.15  R$   0,60
  │    ├─ 1.15  R$   0,12
  │    └─ 1.15  R$ 168,72        (= R$ 242,11, o valor da parcela)
  ├─ Parc. 7/40 = NN 75130       → 1.14 R$ 7,35 + 1.15 R$ 234,70
  └─ Parc. 8/40 = NN 75131       → 5 linhas de 1.1 + 1 de 1.14
```

As várias linhas do mesmo NN **não são duplicata nem ruído**: são as dívidas originais que o acordo
consolidou naquela parcela. Por isso a parcela 1 é só honorário e a 8 é quase toda taxa — o acordo
distribuiu as origens ao longo das 40 parcelas.

Isto explica de uma vez os "37 sem principal" (§9.1) e os **273 grupos com mais de uma linha da mesma
classe** que a leitura já agregava sem saber por quê.

### 11.2 O que foi medido

| | TOP LIFE I | TOP LIFE II |
|---|---|---|
| grupos `(unidade, NN)` com acordo na coluna J | 350 | 45 |
| destes, **recebidos** (é o que a importação toca) | **160** | **27** |
| recebidos SEM principal | 37 | 1 |
| — que **são** parcela de acordo | **37 (100%)** | 0 |
| acordos distintos citados | 93 | 34 |
| — com TODAS as parcelas no arquivo | 57 | 34 |
| — que **já existem** no sistema (`numero_externo`) | **0** | **8** |
| — que têm aba no relatório "Acordos detalhados" | **40** | **8** |

Duas propriedades que sustentam a chave, ambas medidas: **nenhum NN aparece em dois acordos** e **nenhum
acordo cruza unidades**.

⚠️ **A §1 estava errada ao dizer "106 acordos citados, zero existem".** São **127** citados e **8** já
existem — criados pelo importador de Acordos detalhados, com `numero_externo` 28, 21, 39, 31, 37, 32, 9
e 34. (Quinta medição desta spec a cair; ver §10.1.)

### 11.3 O que o código faz hoje, e por que está errado

O `TopLifeReceitasAdapter` **já lê** a coluna J e produz `AcordoDoRelatorio(numero, parcelaIndice,
parcelaTotal)`. O `ImportarReceitasUseCase` **ignora o campo**.

Consequência: os **187 recebimentos** que são parcela de acordo (160 + 27) viram obrigações **avulsas**
chamadas "Taxa MM/AAAA", soltas na fila de cobrança, sem vínculo com o acordo que as gerou.

A infraestrutura para fazer certo **já existe**: `Acordo` tem `numeroExterno` e `numeroParcelasTotal`
(com índice `(tenant_id, numero_externo)`), e `Obrigacao` tem `acordoOrigem`.

### 11.4 Decisões do dono (03/08) para a etapa 3

| # | Decisão |
|---|---|
| **A1** | **Parcela paga ⇒ o acordo existe e tem de ser criado.** Não se cria "só a parcela": o acordo é a entidade real, e a parcela é dele. |
| **A2** | **Status `Ativo`.** Só não é ativo se já terminou de ser pago — aí `Cumprido`. |
| **A3** | **A etapa 2 fecha como está.** Isto é a **etapa 3**, junto com D6. ⛔ **A importação de receitas NÃO deve rodar antes** — ela criaria 187 obrigações avulsas que a etapa 3 teria de desfazer. |

Sobre A1, medido: o relatório **"Acordos detalhados" cobre 48 dos 127** acordos citados (uma aba por
acordo, título `Acordo n377`). Os **79 restantes** teriam de nascer só com o que a Receitas dá — número,
total de parcelas e as parcelas pagas. É a primeira pergunta de desenho da etapa 3.

### 11.5 O desenho, em uma frase

A coluna J passa a decidir **dois caminhos** na gravação:

- **vazia** → boleto avulso, exatamente como hoje (1.891 dos 2.077 recebimentos);
- **`Acordo N - Parc. x/y`** → a obrigação nasce como **parcela do acordo N** (`acordoOrigem`), criando o
  acordo com `numeroExterno = N` e `numeroParcelasTotal = y` quando ele não existir, ou ligando ao que já
  existe (8 casos hoje).
