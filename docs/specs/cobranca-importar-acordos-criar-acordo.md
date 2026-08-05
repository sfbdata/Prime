# SPEC — o importador de Acordos passa a CRIAR o acordo (item 5)

**Risco: ALTO** — mexe em dívida. Spec antes, duas revisões depois.
**Frente:** `HANDOFF_AUTOMATIZAR_DOWNLOADS.md` §6.3 item 5 · §9.3.
**Spec-mãe:** `docs/specs/cobranca-importar-acordos-detalhados.md` (esta revoga a recusa da §3.1).

---

## 1. O problema, em uma frase

O relatório de **Acordos** é a fonte canônica do que a contábil chama de acordo, mas hoje ele **nunca cria
acordo**: aba cujo acordo não existe no sistema é reportada e ignorada (§3.1 da spec-mãe,
no início de `processarAba`). Quem cria acordo hoje é o relatório de **Receitas** — e ele só
cria quando **alguém pagou** uma parcela. Acordo recém-fechado, sem nenhum pagamento, não nasce em lugar
nenhum, e as parcelas dele não existem para o sistema.

## 2. O que foi MEDIDO (07/08, contra as planilhas reais)

> 🔴 **LEIA ISTO ANTES DOS NÚMEROS — achado da 2ª revisão.** Tudo na §2 é medido **planilha contra
> planilha**, e responde a uma pergunta específica: *"depois que a Inadimplência e a Receitas completa
> rodarem num banco vazio, quantos acordos ainda faltam?"* — que é exatamente o cenário do **item 8**.
> **Não é** a previsão de rodar este importador contra um banco qualquer.
>
> O código decide contra o **banco**, não contra a planilha: ele exige objeto + caso ATIVO. Medido no dia
> seguinte, com o dry-run real contra `saas_ux` (que tem só a importação estreita da etapa 3), **só a TL1
> `EM_ANDAMENTO`**: **45** acordos seriam criados · **608** parcelas · **R$ 129.811,51** entrando no
> saldo · **21 abas recusadas pela R1**. Números totalmente diferentes — e certos, porque a pergunta é
> outra.
>
> 🔑 **Consequência de desenho:** a **R1 não é ramo morto.** Ela dispara sempre que a inadimplência da
> carteira não foi importada antes, e é ela que impede o importe de pendurar acordo onde não há
> cobrança. A ordem da §7 deixou de ser recomendação e virou requisito.
>
> ⚠️ **O número que o dono autorizar tem de vir do dry-run do banco em que a importação vai rodar**, não
> desta seção.

Fontes, ditas por extenso — `2026-08-04-api/` para Acordos e Inadimplência de TL1/TL2,
`2026-08-04-completo/` para as Receitas das três carteiras e para tudo da AMLI (o lote `-api/` de Receitas
é recusado pelo validador do rodapé, §10.4 do handoff, e está certo em recusar).

Scripts: `_item5_medir.php` · `_item5_efeito.php` · `_item5_conferir.php` (pasta gitignored).

### 2.1 Quantos acordos passam a nascer: **38**

| carteira | declarados pela fonte | nascem hoje (Receitas) | **faltam** |
|---|---:|---:|---:|
| TOP LIFE 1 | 325 | 304 | **21** |
| TOP LIFE 2 | 34 | 26 | **8** |
| AMLI BR 060 | 33 | 24 | **9** |
| **total** | **392** | **354** | **38** |

⚠️ **O handoff dizia 29, e não está errado — está incompleto.** Os 29 são TL1+TL2 (21+8), que batem ao
número. Faltavam os 9 da AMLI, que o dono decidiu incluir. Mais um caso de *fato do handoff tem prazo de
validade*: a conta muda quando o escopo muda.

Os 4 acordos do antigo item 2 (155, 374, 394, 414 — §9.3 do handoff) estão entre os 21 da TL1. ✅

### 2.2 O efeito no saldo, decomposto

Régua da casa: **número grande não é dinheiro na tela**. Separado por natureza:

| | quantidade | valor |
|---|---:|---:|
| parcelas que passam a existir → **ENTRA no saldo** | 119 | **R$ 28.926,43** |
| parcelas que já existiriam (só ganham o vínculo) | 32 | — |
| parcelas já pagas na fonte (nunca criadas, §5 da spec-mãe) | 12 | — |
| contas originais reconstruídas, já substituídas → **neutro** | 267 | R$ 41.975,83 |
| contas originais marcadas (saem do saldo) | **0** | R$ 0,00 |
| **efeito líquido** | | **+ R$ 28.926,43** |

Concentração: 4 acordos de junho/julho de 2026 respondem por R$ 23.476,00 — o **414** (R$ 12.412,00),
o **407** (R$ 4.680,00), o **394** (R$ 3.690,00) e o **411** (R$ 2.694,00). São acordos fechados há poucas
semanas, sem nenhuma parcela paga — exatamente a forma que a Receitas não enxerga.

### 2.3 🔑 A medição foi CONFERIDA, porque o zero era grande demais

**0 de 267 contas originais casam** com a Inadimplência ou a Receitas. Se esse zero fosse defeito de chave,
o item 5 contaria a **mesma dívida duas vezes**: a conta original continuaria no saldo e a parcela do
acordo entraria por cima. Três conferências independentes, e o zero se sustenta:

1. **formato de competência idêntico** nas três fontes (`MM/AAAA`) — a chave não está quebrada;
2. **régua frouxa (só o NN, sem competência): também 0** — não é a competência que separa;
3. **contraprova**: a MESMA régua casa **837 parcelas** dos acordos que já funcionam hoje
   (783 TL1 · 27 TL2 · 27 AMLI) e só **45 de 3.502 contas originais**.

A explicação é propriedade da fonte, e já estava no docblock do UseCase: **a contábil remove a conta
renegociada do relatório de inadimplência ao fechar o acordo**. Por isso ela não está em lugar nenhum, e
por isso o §3.2.1 (reconstruir já substituída) existe. **Risco de dobrar a dívida: zero.**

### 2.4 A unidade sempre existe

**38 de 38** têm a unidade presente na Inadimplência ou na Receitas da mesma carteira. O texto bruto da
`Unidade:` do relatório de Acordos tem o **mesmo formato** do de Inadimplência, incluindo a forma com
parênteses (`01-01 (05-03,06-01,06-02,06-03 e 06-04)`, 26 das 325 abas de TL1) — a mesma régua
`separarUnidade` dos outros dois adapters se aplica sem ajuste.

## 3. Decisões do dono

| # | decisão | quando |
|---|---|---|
| D1 | **Cria o acordo — "sim, cria, a planilha manda"**, inclusive quando as parcelas são só honorário e juros, sem principal | 06/08 (§9.5) |
| D2 | **Unidade que não existe em nenhuma outra planilha: RECUSA a aba e avisa** — não abre cobrança nova a partir do relatório de Acordos | 07/08 |
| D3 | **`Data base` vira a data do acordo**, não `Criado em` — é a data em que a contábil parou o relógio dos juros | 07/08 |
| D4 | AMLI BR 060 entra · CANCELADOS ficam fora | 05/08 |

Sobre **D2**: contra as planilhas o ramo não dispara (38/38 têm unidade), mas **contra banco ele dispara
muito** — 21 recusas só na TL1 `EM_ANDAMENTO` de `saas_ux` (2ª revisão). Não é defesa redundante nem ramo
morto: é o que impede o importe de pendurar acordo onde não há cobrança. A alternativa era o relatório de
Acordos ganhar poder de abrir cobrança nova. O custo é a ordem de execução (§7): rodar Acordos **antes**
da Inadimplência recusa as abas, sem gravar nada, e basta rodar de novo na ordem certa.

Sobre **D3**: divergem em 4 das 38 abas (ex.: acordo 39 — base 13/07, digitado 30/07). `Data base` é o que
`materializarNaDataDoAcordo` usa para tirar o snapshot dos encargos das contas substituídas — e o T9 prova
o EFEITO (o `encargosAtualizadosEm` da reconstruída), não só o campo do acordo.

## 4. O que passa a acontecer

### 4.1 A regra

Na aba cujo acordo **não existe** na carteira, o importador **cria o acordo** e segue o fluxo normal da
aba (completar parcelas + reconciliar contas originais), em vez de ignorá-la.

O acordo nasce com:

| campo | de onde vem |
|---|---|
| `caso` | caso **ATIVO** do objeto resolvido por `Unidade:` (régua `separarUnidade`) na carteira |
| `status` | a linha `Situação:` pelo mapa que já existe (`Em andamento`→`Ativo`, `Liquidado`→`Cumprido`) |
| `dataAcordo` | `Data base` do cabeçalho (**D3**) |
| `numeroExterno` | o número da aba |
| `numeroParcelasTotal` | o `total` do indicador `p/t` — **só quando a aba tem parcela a materializar** (ver 4.1.1) |
| `valorTotalNegociado` | `Valor final acordado` do cabeçalho |
| `tenant` / `criadoPor` | os da execução |

⚠️ **NÃO registra evento no histórico** — e esta linha da spec já disse o contrário, por uma revisão.

A 1ª revisão pediu o evento (38 acordos apareceriam sem nada dizendo de onde vieram). A 2ª mediu o efeito
colateral e o derrubou: `TipoEventoHistorico::AcordoCriado` é **exatamente** o que a **Central de
Acompanhamento — que está em produção** — conta como a coluna "Acordos" do trabalho humano de cobrança
(`EventoHistoricoRepository::agregarAtividadePorUsuario`, `COUNT(*) FILTER (WHERE e.tipo = :acordo)`), e
ainda alimenta a "Última ação". Registrar aqui creditaria dezenas de acordos "fechados" num único dia a
quem rodou a importação — e um relatório que conta importação como trabalho para de medir trabalho. É a
mesma distorção que o comentário de `PagamentoExcluido`, no próprio enum, diz que a Central existe para
evitar. Volta também a bater com os outros dois importadores, que criam acordo sem evento.

**A procedência não se perde:** `numeroExterno` só é preenchido por importação, e as contas reconstruídas
carregam `Reconstruída da planilha de acordos (emissão …)` na descrição (§3.2.1 da spec-mãe). A decisão
está travada por teste (T1b), com o assert que diz *por que* ela existe.

### 4.1.1 ⚠️ `numeroParcelasTotal` só entra quando há parcela a materializar

**Achado da 1ª revisão, e medido.** `Acordo::estaIncompleto()` compara este campo com as linhas de parcela
que existem, e a tela do acordo estampa **"⚠ Faltam N parcelas"** quando falta. Medido em 07/08 nos três
arquivos `*_LIQUIDADO`:

| arquivo | abas | parcelas | com data de liquidação |
|---|---:|---:|---:|
| TL1 · TL2 · AMLI | 310 | 627 | **627 (100%)** |

**Nenhuma aba `Liquidado` tem parcela em aberto** — e parcela que consta paga **não é criada** (§5 da
spec-mãe: não se escreve pagamento a partir de planilha). Logo os acordos `Cumprido` desta frente nascem
com **zero linhas de parcela**; gravar o total neles produziria um aviso **permanente e falso** na tela
dos **12** (TL1: 111, 178, 222, 263, 301, 305, 354, 422 · TL2: nenhum · AMLI: 18, 25, 27, 48 — recontado
na 2ª revisão; a versão anterior dizia 13). Aviso que sempre dispara ninguém lê — é a mesma régua que mantém a sobrescrita fora do bloco de
avisos do relatório.

Regra: grava o total quando a aba traz **ao menos uma parcela não liquidada**; senão, `null`. Provado nos
dois sentidos (T3b e T3c).

⚠️ `valorTotalNegociado` é gravado **sempre** aqui, diferente do `ImportarReceitasUseCase` (que só grava
com uma parcela). O motivo da restrição de lá não vale aqui: a Receitas só traz as parcelas **pagas** e
inventaria o total; o relatório de Acordos **imprime o total negociado no cabeçalho da aba**.

### 4.2 As quatro recusas (o acordo NÃO é criado)

Todas viram aba ignorada com motivo próprio, nunca exceção — uma aba estranha não derruba o lote.

| # | quando | por quê |
|---|---|---|
| R1 | a **unidade** não tem objeto na carteira, ou o objeto não tem caso **ativo** | **D2**. Sem caso não há onde pendurar o acordo, e abrir cobrança não é papel deste relatório |
| R2 | a **situação** não está no mapa (`SITUACOES`) | nunca adivinhar status: é a mesma régua que a sobrescrita já aplica |
| R3 | a situação está no mapa mas **não é vigente** (`Cancelado`) | acordo não vigente tem a aba pulada inteira de qualquer forma (`:312-337`) — criá-lo deixaria um acordo vazio no sistema. Coerente com **D4** |
| R4 | a aba **não tem `Data base`** | é a data que para o relógio dos juros (**D3**). Chutá-la é decidir dinheiro no escuro |

⚠️ **Corrigido após a 1ª revisão: as QUATRO recusas nascem mortas, não duas.** Medido nas 6 planilhas:
situações presentes = `{Em andamento, Liquidado}` apenas (R2 nunca dispara) · `0` abas sem `Data base`
(R4) · o `*_CANCELADO.xlsx` é barrado pelo validador do item 6 antes de chegar aqui (R3) · 38 de 38 abas
têm unidade com caso ativo (R1). Ficam registradas como o que são: ramos não exercitados pela fonte,
provados só por teste — a mesma honestidade que a spec-mãe registra para `Cumprido → Ativo`. Nenhuma
consequência em dinheiro; a consequência de escondê-las seria alguém supor cobertura que não existe.

### 4.3 O que NÃO muda

- **Não é sobrescrita de situação.** O acordo nasce com o status da planilha; isso não é uma *mudança* de
  status e **não** entra em `situacaoSobrescrita` nem gera evento `AcordoEditado` no histórico — o evento
  é o de **criação** (§4.1). ⚠️ A 1ª revisão achou que o T3 passava com o acordo **nascendo `Ativo`**: a
  sobrescrita logo abaixo o corrigia para `Cumprido` e o estado final ficava igual. Status certo pelo
  caminho errado. O T3 ganhou o assert que distingue os dois (`situacoesSobrescritas() === []`).
- **Nenhuma das guardas de dinheiro existentes é afrouxada.** Parcela paga na fonte continua não sendo
  criada; NN ambíguo continua recusado; valor lançado continua não sendo sobrescrito; INV-I continua
  valendo. O acordo recém-criado não tem parcela nem alocação, então `mapearParcelasPagas` e
  `ImpactoDaReativacaoDeAcordo` respondem *falso/vazio* para ele — que é o correto, não uma exceção.
- **O aviso de divergência de valor fica exatamente como está** (item 3, decisão do dono: por último).

### 4.4 Idempotência

Na segunda execução o acordo **existe** e a aba entra pelo caminho de sempre. Nada é recriado. O acordo é
achado por `(numeroExterno, carteira, tenant)` — a busca por carteira já é a que existe, e é obrigatória:
o número é sequencial **por carteira**, não global.

## 5. Prévia × confirmação — a invariável que decide se isto pode ir a produção

O dry-run **é o produto principal** (§6 da spec-mãe): prévia e confirmação percorrem o **mesmo** método,
`$usuario === null` é o dry-run. Criar acordo mete uma escrita no meio disso, e é aqui que a coisa quebra
se for feita sem cuidado.

**A regra:** a entidade `Acordo` é **construída nos dois modos**, a partir da aba, e **persistida só na
confirmação**. A prévia trabalha com um `Acordo` transiente (nunca passado ao `persist`), o que faz as
duas execuções processarem exatamente as mesmas abas, pelos mesmos ramos, com os mesmos números.

Três pontos em que o `id` nulo do acordo transiente é lido, todos conferidos (referências por MÉTODO,
não por número de linha — a 2ª revisão achou as linhas já defasadas):

| onde | com acordo transiente (prévia) | com acordo persistido (confirmação) | igual? |
|---|---|---|---|
| `completarParcelas`, parcela de outro acordo | `id` do outro `!== null` → divergência | `id` do outro `!== id` novo → divergência | ✅ |
| `reconciliarContasOriginais`, já substituída por outro acordo | `id` do substituto `!== null` → recusada | idem | ✅ |
| `materializarNaDataDoAcordo` | só roda na confirmação | usa `dataAcordo`, que já está setada | ✅ |

⚠️ **Na confirmação o acordo tem de ser persistido ANTES da primeira parcela.** O
`RegistrarObrigacaoUseCase` dá flush por obrigação; ligar uma obrigação a um `Acordo` não gerenciado
estouraria com *new entity found through relationship*.

## 6. O que o operador vê

`AcordoProcessado` ganha `acordoCriado: bool` + `situacaoDoAcordoCriado` + `dataDoAcordoCriado`, e o
relatório passa a imprimir a linha — na prévia como *"seriam criados"*, na confirmação como *"criados"*,
com número, unidade, sacado, **situação e data base**. Sem isso o dry-run mostraria parcelas e contas de
um acordo que o operador não sabe que vai nascer.

⚠️ Os dois últimos campos entraram na 1ª revisão: a primeira versão imprimia só número/unidade/sacado, e
`AcordoProcessado` sequer os carregava. São justamente os dois campos que as decisões do dono governam —
a **situação** decide se as parcelas entram no saldo, a **data base** é a D3.

As recusas R1–R4 entram no campo `ignoradoPorque` que já existe, com texto que diz **o que fazer**
(R1: *"importe a inadimplência desta carteira primeiro"*).

## 7. Ordem de importação (consequência operacional para o item 8)

Por **D2**, a ordem passa a importar: **Inadimplência → Receitas → Acordos**. Rodar Acordos antes recusa
por R1 e não grava nada — recuperável, mas o relatório fica cheio de recusa. Registrar no runbook do
item 8.

## 8. Testes — o que cada um tem de provar

Todo teste é provado **reintroduzindo o defeito**, e conferindo **qual assert** ficou vermelho. Injeção
que não avermelha o teste previsto significa que o teste não prova o que diz (custou 3 versões no item 6).

| # | prova | injeção que tem de avermelhar |
|---|---|---|
| T1 | aba com acordo inexistente **cria** o acordo, com caso/status/data/número corretos | voltar a devolver `abaIgnorada` |
| T2 | as **parcelas e contas** da aba são processadas depois de criar (não é só o acordo que nasce) | criar o acordo e devolver `abaIgnorada` na sequência |
| T3 | `Liquidado` nasce **`Cumprido`**, e a aba é processada (é vigente) | mapear `Liquidado` para `Ativo` |
| T4 | **R1** — sem objeto na carteira, recusa e não cria | resolver o caso por outro caminho |
| T5 | **R1** — objeto existe mas sem caso ativo, recusa e não cria | aceitar caso encerrado |
| T6 | **R2** — situação fora do mapa, recusa e não cria | criar com `Ativo` por padrão |
| T7 | **R3** — `Cancelado` não cria acordo | criar e deixar a aba pulada |
| T8 | **R4** — sem `Data base`, recusa e não cria | cair para `Criado em` ou para hoje |
| T9 | **D3** — a data gravada é a `Data base`, não `Criado em` (aba em que divergem) | trocar por `criadoEm` |
| T10 | **idempotência** — segunda execução não cria de novo nem duplica parcela | remover a busca por número externo |
| T11 | **prévia × confirmação** — a prévia projeta os MESMOS números que a confirmação efetiva, e **não grava acordo nenhum** | persistir o acordo também no dry-run |
| T12 | o acordo criado **não** aparece como sobrescrita de situação nem gera evento `AcordoEditado` | reaproveitar o caminho da sobrescrita |
| T13 | a criação resolve a unidade **dentro da carteira**: o escritório vizinho com a mesma unidade não é tocado | tirar carteira+tenant da busca do objeto |
| T14 | unidade **com parênteses** resolve o mesmo objeto (26 das 325 abas reais são assim) | a régua parar de separar o parêntese |
| T3b | a forma **REAL** do `Liquidado` (todas as parcelas pagas): nasce sem parcela e **sem aviso falso** | gravar `numeroParcelasTotal` sempre |
| T3c | aba com parcela em aberto **continua** gravando o total (o sentido oposto do T3b) | nunca gravar o total |
| T1b | o acordo criado **não** vira evento de trabalho de cobrança (não polui a Central) | registrar `AcordoCriado` |
| T1c | a procedência sobrevive sem o evento (`numeroExterno` + descrição da reconstruída) | parar de gravar o número externo |

⚠️ **T13 não prova o filtro de `tenant`** de `ObjetoCobrancaRepository::findOnePorIdentificacaoNaCarteira`
— medido na 1ª revisão: removendo só esse filtro a suíte fica verde, porque a carteira já é tenant-bound
a montante (`resolverCarteira` usa `findOneByIdDoTenant`). Aquele filtro é defesa em profundidade
preexistente, de outro escopo. Registrado para ninguém supor cobertura que não existe.

⚠️ **T4/T5/T6/T7/T8 testam a RECUSA. T1/T2/T3/T9 testam o ACEITE.** Os dois sentidos, sempre — a 2ª
revisão do item 6 achou uma frente em que tudo testava a recusa e nada testava o aceite, e uma letra
errada teria deixado a suíte verde com o comando travado em produção.

## 9. O que fica de fora

- **Não** cria objeto, pessoa nem caso (D2).
- **Não** dá baixa de pagamento (§5 da spec-mãe, segue fora de escopo).
- **Não** mexe no aviso de divergência de valor (item 3, por último).
- **Não** sobrescreve valor lançado (§4 da spec-mãe).
