# Spec — o honorário entra no total da dívida

> Risco **ALTO** (muda o saldo exigível de todas as carteiras em produção). Frente do espelho da
> contabilidade, §3.2 do `docs/HANDOFF_ESPELHO_CONTABILIDADE.md`. **Já aprovada pelo dono.**
> Medido em produção em **2026-08-19**.

## 1. O defeito

A contabilidade soma `principal + juros + multa + honorários`. O rodapé do relatório de
inadimplência dela (emissão 17/08), nas 3 carteiras:

| principal | juros | multa | honorários | total dela |
|---:|---:|---:|---:|---:|
| 535.384,49 | 149.771,17 | 10.705,69 | **126.878,17** | **822.739,52** |

`Obrigacao::valorExigivel()` deixa o honorário de fora. A tela mostra ~R$ 126 mil **a menos** que o
documento dela.

**O honorário já está calculado e gravado** — só não é somado. Medido: R$ 126.362,85 de honorário
materializado nas obrigações exigíveis em aberto (111.535,80 TL1 · 14.047,74 TL2 · 779,31 AMLI),
contra os R$ 126.878,17 do rodapé dela. Diferença de R$ 515,32 (0,4%).

### 1.1 Isto é o sistema formando opinião

O código diz, com todas as letras (`Obrigacao.php:28` e `:225`):

> *Honorários ficam FORA do `valorExigivel()` (INV-E2/SPEC §18.5): honorário não é dívida do credor.*

A distinção é defensável em contabilidade — mas é **julgamento**, e a regra desta frente é que o
sistema copia, não julga (handoff §1.1). Ela põe no total; nós pomos no total.

🔑 **O conserto é TIRAR a exceção, não acrescentar regra.** Espere apagar código. Se a solução ficar
maior que o problema, ela entendeu a tarefa errado. A distinção "quanto disso é honorário" continua
existindo — como **detalhamento na tela**, que é do dono, nunca como subtração do total.

**INV-E2 é revogada por esta spec.** Registrar isso explicitamente, para que ninguém a "restaure"
depois lendo o comentário antigo.

## 2. 🔴 A fórmula do exigível está DUPLICADA em três lugares

Este é o achado que decide a fatia. Trocar só o método deixaria o sistema com duas definições de
dívida — o saldo diria uma coisa e a quitação diria outra, **em silêncio**.

| # | onde | o que faz |
|---|---|---|
| 1 | `Obrigacao::valorExigivel()` (`:231`) | a canônica; 15 pontos de chamada |
| 2 | `EncargosVivos::exigivelVivo()` (`:74`) | `getValorOriginal() + juros + multa + correcao` do cálculo ao vivo — **é por aqui que o `AutoAlocadorFifo` enxerga a dívida** |
| 3 | `ReconciliadorLiquidacao` (`:83-84`) | monta a soma inline para decidir se **QUITA ou REABRE** |

**As três mudam juntas ou nenhuma muda.** O próprio repositório já registra a lição em
`ObrigacaoRepository::aplicarExigibilidade`: *"Regra de dinheiro duplicada diverge em silêncio."*

Depois desta fatia, #2 e #3 devem **delegar** para a regra canônica em vez de repetir a soma — é o
mesmo conserto que a D10 fez com os critérios de exigibilidade.

## 3. Os pontos de chamada: 15, não 35

Chamadas reais de `->valorExigivel()`, em 10 arquivos (os 35 do handoff contavam menções em
comentário e docblock). **Dois decidem estado**, o resto lê:

| grupo | onde | efeito de somar honorário |
|---|---|---|
| **decide quitação** | `ReconciliadorLiquidacao:86` (via cópia #2), `ExcluirPagamentoUseCase:175` | a barra da quitação sobe |
| **saldo** | `CalculadoraSaldo:72,106,161` | o saldo exigível sobe |
| **alocação** | `AutoAlocadorFifo` (via `exigivelVivo`), `AcordoCriarType:114,159` | há mais dívida a abater/renegociar |
| **tela** | `ObrigacaoOutput:156`, `MontarDetalheCasoUseCase:133`, `MontarDetalheAcordoUseCase:62,94,99` | os números da tela sobem |
| **aviso** | `ImpactoDaReativacaoDeAcordo:130`, `AlertasCobranca:227` | idem |

`totalComHonorarios()` (`Obrigacao:237`) vira **redundante** — passa a ser igual a `valorExigivel()`.
Remover, atualizando os 3 usos em `_divida.html.twig` e os 2 em DTOs. É o "apagar código" da §1.1.

## 4. Risco medido — e por que ele é baixo

### 4.1 Dívidas hoje quitadas que deixariam de estar: **ZERO**

Medido em produção, obrigação a obrigação: nenhuma quitada reabre, porque **nenhuma quitada carrega
honorário**. As 1.014 quitadas com juros/multa > 0 e honorário R$ 0,00 se explicam por inteiro:

| causa | quantas |
|---|---:|
| carência de 30 dias — comportamento **correto** (juros/multa desde o 1º dia, honorário só após o 30º) | 601 |
| parcela de acordo com override `taxa_honorarios_bp = 0` — é a §3.4, não esta fatia | 343 |
| criadas-já-pagas pelo importador de receitas (`ImportarReceitasUseCase:564` grava `honorariosBp = 0`) | 70 |
| **sem explicação** | **0** |

O override zero existe em 1.906 obrigações e **100% delas são parcela de acordo**.

⚠️ Este zero é **frágil por dependência**: ele vale porque o honorário das quitadas é zero hoje. A
§3.4 (que conserta o honorário zerado da parcela de acordo) muda essa população. **Se a §3.4 entrar
antes desta fatia, remedir.**

### 4.2 O efeito é sobre dívida EM ABERTO

O saldo exigível sobe **R$ 126.362,85** nas 3 carteiras. Nenhuma dívida quitada volta a abrir.

## 5. A ordem de alocação — a pergunta se dissolve

O handoff manda *"não escolham uma ordem: descubram a dela"*. Medido, a resposta é que **não há
ordem a descobrir de nenhum dos dois lados**:

- **Do lado dela:** o relatório de receitas não tem ordem, tem **rateio declarado**. Cada recebimento
  aparece repartido por categoria (`1.1 Taxa de condomínio`, `1.4 Juros`, `1.5 Multas`,
  `1.15 Honorário advocatício`, `1.6 Descontos`), tudo na mesma data. Não é uma cascata; é um extrato.
- **Do nosso lado:** `cobranca_alocacao_pagamento` guarda **um único `valor` por obrigação** — não
  existe alocação por categoria. Logo não há "para onde o dinheiro vai" a decidir: o honorário
  simplesmente entra no número único que o pagamento abate.

E a separação que existe já está preenchida e conferida: `cobranca_pagamento` tem
`valor_divida`/`valor_encargos`/`valor_honorarios`, e o honorário recebido soma **R$ 54.253,67**
contra os R$ 54.135,16 da categoria `1.15` dela. Melhor ainda: **Σ pago = Σ alocado ao centavo nas
três carteiras** — todo o dinheiro de honorário já está abatendo dívida hoje, contra um exigível que
não o contava.

🔑 **Nada a construir aqui.** Nem ordem de alocação, nem leitura nova do relatório de receitas.
Registrado para que a fatia não invente trabalho que a medição já eliminou.

## 6. A coluna-sombra fica como está

`encargos_reconhecidos` = `juros + multa + correcao` (`sincronizarSombraDeEncargos`) existe para que
um **rollback do deploy** encontre o saldo intacto. Ela deve continuar **sem** honorário: é
exatamente o valor que a versão anterior espera ler. Mexer nela quebraria a rede de segurança que
ela é. Não é esquecimento — é decisão, e está registrada aqui.

## 7. Prova

**Prova por reintrodução, EXECUTADA** — apagar a correção, ver vermelho, restaurar, ver verde, e
dizer qual teste morreu. Nesta frente quatro correções já entraram declaradas como provadas sem
estar; leitura de código não conta como prova.

Testes obrigatórios:

1. **A duplicação (§2), um teste por cópia.** Um teste que só exercite o método deixaria #2 e #3
   passarem: precisa haver teste que quite/reabra uma dívida (caminho #3) e teste que aloque via FIFO
   (caminho #2) com honorário > 0. **É o teste que pega o defeito que esta spec existe para evitar.**
2. Saldo do caso sobe exatamente o honorário das obrigações em aberto.
3. Dívida com honorário > 0 e alocado = principal+juros+multa **não** é quitada.
4. Carência: dívida com ≤ 30 dias de atraso segue com honorário 0 e o total não muda.
5. Isolamento multi-tenant nos caminhos tocados.
6. Suíte completa verde na frente **e de novo no master depois do merge** — é o passo que todo mundo
   pula e que já salvou esta frente duas vezes.

## 8. O que esta fatia NÃO faz

- Não conserta o honorário zerado da parcela de acordo (**§3.4**) — população e mecanismo diferentes.
- Não muda arranjo, rótulo ou agrupamento de tela: isso é do dono.
- Não desmonta o cálculo ao vivo (`EncargosVivos`) — é projeção aceita.
- Não toca a coluna-sombra (§6).

## 9. Depois de pronto

⚠️ **O total na tela sobe ~R$ 126 mil.** O dono avisa a equipe de cobrança antes do deploy. Não é
dinheiro novo: é dinheiro que a contabilidade já cobrava e o sistema não mostrava.

## 10. 🔴 O quarto criador de obrigação não tinha o guard — as 135 parcelas

Achado de 19/08, **medido em produção**, e é o item que esta fatia fecha antes de qualquer outro:
a própria fatia transformaria um defeito de exibição em cobrança a mais.

### 10.1 Onde elas nascem

Existem **quatro** pontos que criam `Obrigacao` pelos importadores. Três põem o override
`taxa_honorarios_bp = 0`; **um não punha**:

| # | onde | guard |
|---|---|---|
| 1 | `ImportarRelatorioCarteiraUseCase::obrigacaoInput()` (linha de acordo) | ✅ |
| 2 | `ImportarAcordosDetalhadosUseCase::parcelaInput()` | ✅ |
| 3 | `ImportarReceitasUseCase` (ramo `$ehParcela`) | ✅ |
| 4 | **`ImportarAcordosDetalhadosUseCase::reconstruirContaOriginal()`** | ❌ **era o furo** |

🔑 **O handoff dizia "dois importadores já aplicam o guard". São três.** Mais uma vez o inventário
por `grep` de método contou a menos — a mesma armadilha que fez a §2 desta spec dizer "três cópias
do exigível" quando são cinco.

**Prova de que as 135 vêm daí, e não de outro caminho:** `reconstruirContaOriginal` carimba a
procedência na descrição (`descricaoComProcedencia`). **135 de 135 carregam o texto
"Reconstruída da planilha de acordos"**, que nenhum outro caminho escreve. Nenhuma veio da tela.

### 10.2 Por que o guard pertence a ela — o gatilho é OUTRO

Nos casos 1–3 o gatilho é *"é parcela de acordo, e o valor negociado já embute o honorário"*.
`reconstruirContaOriginal` **não cria parcela** — cria conta original substituída. O gatilho aqui é
outro, e está no adapter:

> `AcordosDetalhadosAdapter::montarContaOriginal()`: *"Uma conta original é o GRUPO de linhas do
> mesmo NN+competência, **somado** (...) o valor é a soma de todas."*

Ou seja: `valorOriginal` recebe o boleto INTEIRO — principal **mais** as linhas `1.4 - Juros`,
`1.5 - Multas` e **`1.15 - Honorário advocatício`**. O honorário já está dentro. Cobrá-lo de novo por
taxa é contar duas vezes o mesmo dinheiro dela.

**Confirmado no dado real (relatório de acordos de 17/08, produção):** o `valorOriginal` das 135 bate
com a soma da coluna Valor daquele NN em **135/135, ao centavo** (R$ 21.796,35 dos dois lados), e
dentro dessa soma há **R$ 2.047,95 em linhas `1.15 - Honorário advocatício`**, distribuídas em 20
obrigações — 8 das quais são, elas mesmas, linha de honorário.

E a régua dela é explícita: no relatório de acordos, de **8.671 linhas de parcela, ZERO** têm juros,
multa, honorário ou total. Ela declara só o Valor acordado. Cobrar encargo por cima é o sistema
formando opinião — §1.1.

### 10.3 O tamanho medido — e onde ele NÃO está

⚠️ **Correção do handoff §7.1: os R$ 2.764,16 NÃO estão sendo cobrados, e a fatia não os cobraria
pelo saldo.** As 135 têm `acordo_substituto` **vigente** (`cumprido`), e `aplicarExigibilidade`
exclui exatamente isso. Rodado contra produção: **0 de 135 entram no exigível.**

A exposição real é **outra, e maior** — a tela de detalhe do acordo. `MontarDetalheAcordoUseCase` lê
`$acordo->getParcelas()` **sem filtro de exigibilidade** e **hidrata ao vivo**. Os 135 acordos de
origem são todos vigentes (48 `ativo` + 87 `cumprido`), então todas hidratam:

| | |
|---:|---:|
| honorário gravado hoje | R$ 2.764,16 |
| **honorário que a tela somaria** | **R$ 4.736,15** (piso) |

É piso porque a hidratação recalcula juros e multa para hoje e o honorário é 20% sobre a soma. Efeito
colateral: `quitada` (`alocado >= valor`) vira `false` em parcela que **está paga**.

**Risco adormecido:** se um desses acordos substitutos for rompido ou cancelado, as 135 voltam ao
exigível — e aí o honorário indevido vira dinheiro no saldo.

### 10.4 Os caminhos que só LIGAM: medidos como ZERO, e por que NÃO são tocados

Três caminhos ligam `acordoOrigem` a uma obrigação que já existia, sem tocar o override:
`ImportarAcordosDetalhadosUseCase:641` · `ImportarRelatorioCarteiraUseCase:246` ·
`ImportarReceitasUseCase:471` (`garantirVinculoAoAcordo`).

**Medido em produção: eles produziram ZERO linhas erradas.** Das 135 com `bp` nulo, 135 vêm de
`reconstruirContaOriginal`; nenhuma é avulsa apenas vinculada. Entram aqui como número medido, não
como pendência (regra da casa: achado medido como zero não vira problema aberto).

🔴 **E há motivo positivo para NÃO os tocar.** `taxa_honorarios_bp = 0` carrega **dois** significados:
é override de encargo **e** é o sinal que decide a alocação em
`ImportarReceitasUseCase:256` —

    $honorarioEmbutidoNoValorOriginal = $acordo !== null && $obrigacao->getTaxaHonorariosBp() === 0;
    $valorAlocado = $honorarioEmbutidoNoValorOriginal ? totalRecebido() : recuperadoDivida();

Uma avulsa comum tem `valorOriginal = principalCentavos` (honorário **fora**). Gravar `bp = 0` ao
ligá-la faria a importação de receitas alocar o valor **bruto** contra um valor que não contém o
honorário — abatendo a mais e quitando dívida que não quitou. **O guard no criador é seguro; no
vinculador, não é.** Se um dia surgir avulsa vinculada, o conserto é separar os dois significados,
não repetir o `0`.

### 10.5 O que esta fatia faz

1. `reconstruirContaOriginal` passa a gravar `modoHonorarios = 'percent'` + `honorariosBp = 0`,
   com o gatilho da §10.2 escrito no código.
2. Comando de correção das 135 já gravadas: `taxa_honorarios_bp = 0` **e** `honorarios = 0` (é o que
   a materialização na data do acordo teria produzido com o guard). Simula primeiro; só grava com
   `--aplicar`.
3. Teste provado por reintrodução: apaga o guard, vê vermelho, restaura, vê verde.

### 10.6 O que fica FORA (fatia própria, decidida pelo dono em 19/08)

`CriarAcordoUseCase` e `EditarAcordoUseCase` criam parcela sem override nenhum. **Decisão do dono: a
escolha é do usuário, não do sistema** — a tela vai oferecer "cobrar honorário sobre as parcelas?",
padrão **não cobrar**, e o campo fica **somente leitura** em acordo que veio da contabilidade (todos
os 398 de hoje), para ninguém quebrar o espelho sem querer.

Sai desta fatia porque exige **migration** (a escolha mora no acordo, para a parcela acrescentada
depois nascer igual às irmãs) e porque **não muda número nenhum hoje**: medido em produção, das
2.041 parcelas de acordo, **zero** nasceram na tela — todas têm NN da contabilidade.
