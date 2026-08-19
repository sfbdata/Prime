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

## 10. 🔴 O override que faltou em 135 parcelas de acordo

Achado de 19/08, **medido em produção**. É o item que esta fatia fecha antes de qualquer outro: sem
ele, a própria fatia transforma um defeito de exibição em cobrança a mais.

⚠️ **Esta seção foi REESCRITA depois da 1ª revisão, que derrubou a versão original.** O que a versão
original dizia — e onde ela errava — está registrado na §10.6, porque o erro é instrutivo.

### 10.1 O defeito

O relatório de acordos da contabilidade **não tem coluna de encargo nenhuma**. Medido no espelho de
17/08, nas três carteiras: de **8.671 linhas de parcela, ZERO** com juros, multa, honorário ou total.
Ela publica só o **Valor acordado**. E as 135 não aparecem em relatório nenhum dela que tenha coluna
de encargo — conferido contra a inadimplência de 17/08: **0 de 135**.

O sistema já copia isso quase em toda parte. Das **2.041** parcelas de acordo em produção:

| | override `taxa_honorarios_bp = 0` | honorário |
|---|---|---:|
| 1.906 parcelas | ✅ tem | R$ 0,00 nas 301 substituídas |
| **135 parcelas** | ❌ **não tem** | **R$ 2.764,16** que ela não cobra |

**Não é divergência de critério — é a mesma regra aplicada em 1.906 lugares e esquecida em 135.** O
conserto é copiar ela (§1.1), não escolher uma regra melhor.

### 10.2 Por onde entraram, e onde o guard vai

As 135 nasceram em 07/08 como **contas originais reconstruídas** (`reconstruirContaOriginal`) —
provado pela marca de procedência que só ela grava: 135/135 carregam
*"Reconstruída da planilha de acordos"*. Nasceram corretas: conta original **não é parcela**.

O defeito veio do **segundo passo**: o ramo `parcela-vinculada` de `completarParcelas`
(`ImportarAcordosDetalhadosUseCase:~652`) as ligou ao acordo — e ligar **sem** aplicar o override é o
que criou as 135.

⚠️ **Não é o único vinculador.** Há outros dois (`ImportarRelatorioCarteiraUseCase:~246` e
`ImportarReceitasUseCase::garantirVinculoAoAcordo`), tratados na §10.5. A 1ª redação desta seção dizia
"é a única mutação que transforma obrigação em parcela" — falso, e é o mesmo tipo de absoluto que
licenciou o erro da §10.6.

🔑 **O guard vai onde a obrigação VIRA parcela**, junto do `setAcordoOrigem` — e **só quando o
`valorOriginal` do sistema É o Valor acordado que ela declara** (`$divergencia === null`, já calculado
ali). Não vai no criador da conta original: ver §10.3.

### 10.3 ⛔ O que NÃO entra — e vale R$ 102.126,32

A conta original reconstruída tem a mesma origem, a mesma marca e a mesma cara das 135. **Mas papel
diferente:** é a dívida VELHA que o acordo engoliu, e nessa a carteira cobra honorário normalmente.

| | quantas | honorário |
|---|---:|---:|
| parcela de acordo, com override | 1.906 | R$ 0,00 nas substituídas |
| **parcela de acordo, sem override** | **135** | **R$ 2.764,16** ← o defeito |
| dívida velha engolida (reconstruída) | 3.347 | R$ 102.126,32 ← **legítimo** |
| dívida velha engolida (boleto real) | 126 | R$ 4.555,97 ← **legítimo** |

**A régua é `acordoOrigem`, não a procedência.** A conta reconstruída nasce só com
`acordoSubstituto`; a parcela tem `acordoOrigem`. É essa coluna que separa R$ 2.764,16 de conserto de
R$ 102.126,32 de estrago.

### 10.4 O tamanho, e onde ele NÃO está

⚠️ **Nenhuma das 135 está no exigível.** Todas têm acordo substituto vigente, e
`aplicarExigibilidade` exclui exatamente isso — rodado contra produção: **0 de 135**. Não há devedor
sendo cobrado a mais hoje.

O número muda na **tela de detalhe do acordo**: `MontarDetalheAcordoUseCase` lê
`$acordo->getParcelas()` sem filtro de exigibilidade e as **hidrata ao vivo** (os 135 acordos de
origem são todos vigentes: 48 `ativo` + 87 `cumprido`). Com o honorário dentro de `valorExigivel()`,
a soma das parcelas sobe, e `quitada` (`alocado >= valor`) vira `false` em parcela **paga**.

**Risco adormecido:** se um desses acordos substitutos for rompido, as 135 voltam ao exigível — e aí
o honorário indevido vira dinheiro no saldo.

### 10.5 O que esta fatia faz

1. `completarParcelas` grava `taxaHonorariosBp = 0` junto do `setAcordoOrigem`, **e só quando o valor
   do sistema bate com o da planilha** — ver §10.5.1.
2. Comando `app:cobranca:reconciliar-honorario-parcela` corrige as 135 já gravadas — `bp = 0` **e**
   `honorarios = 0`, preservando juros, multa e a data do snapshot (INV-H2/H3). Simula primeiro, e só
   grava com `--aplicar` **mais `--ids` da lista que o humano aprovou** (§10.5.2).
3. O comando ignora parcela **sem NN** — nasceu na tela, e ali a escolha é do usuário (§10.7). Zero
   em produção hoje, mas o comando é re-executável e rodá-lo depois daquela fatia apagaria a escolha
   de quem clicou.
4. Provas por reintrodução executadas nas guardas do CÓDIGO: o override do vínculo, a **condição do
   valor** (§10.5.1), a régua da população (`acordoOrigem`), a cláusula do NN e o filtro de tenant.
   A lista aprovada (§10.5.2) e as travas de CLI têm teste próprio
   (`ReconciliarHonorarioDeParcelaCommandTest`, 8 casos), no molde do comando irmão.

### 10.5.1 🔴 Por que o guard é CONDICIONADO ao valor bater

`taxa_honorarios_bp = 0` carrega **dois** significados, e essa é a armadilha desta fatia:

1. override de encargo — "não cobre honorário nesta obrigação";
2. em `ImportarReceitasUseCase:~256`, o sinal `$honorarioEmbutidoNoValorOriginal`, que manda alocar o
   recebimento **BRUTO** em vez do líquido.

Gravá-lo cegamente ao vincular reintroduziria, por outra porta, um defeito que aquele UseCase já
cometeu e reverteu: uma obrigação nascida avulsa tem `valorOriginal = principalCentavos` (honorário
**fora**), e alocar o bruto contra ela abateria do saldo um honorário que não está lá.

**A condição `$divergencia === null` faz a vinculada terminar IDÊNTICA à parcela criada.** Com o
`valorOriginal` igual ao Valor acordado declarado, o par `(valorOriginal, taxaHonorariosBp)` da
obrigação vinculada passa a ser exatamente o que `parcelaInput` grava numa parcela que nasce aqui —
as mesmas 1.906 que já existem. A fatia não introduz assimetria de alocação: iguala a vinculada às
que já estão lá. Divergindo, nada é tocado, e a divergência já sai no relatório para o humano.

⚠️ **O que esta condição NÃO prova:** que o honorário esteja embutido naquele valor. Igualdade de
dois números não diz nada sobre a composição de nenhum deles, e o relatório de acordos não tem coluna
de encargo (§10.1) — não há de onde tirar essa prova. Uma redação anterior desta subseção afirmava
que sim, e a §10.6 a derruba: das 3.482 reconstruídas, só 27 têm linha `1.15` dentro.

⚠️ **A 1ª redação usava outra prova, e ela era tautológica:** *"o valorOriginal das 135 bate com a
soma da coluna Valor do NN, 135/135"*. Para a conta reconstruída isso não prova nada — o adapter
**constrói** o valor como essa soma. A prova que vale é a que a §10.6 traz: das 3.482 reconstruídas,
só 27 têm linha `1.15` dentro. Por isso o argumento do honorário embutido saiu daqui: quem autoriza o
override é o **papel** (é parcela) mais a **igualdade com o valor declarado**, não a composição.

**Fica de fora, medido como zero e sem virar pendência:** os outros dois vinculadores
(`ImportarRelatorioCarteiraUseCase:~246`, `ImportarReceitasUseCase::garantirVinculoAoAcordo`)
produziram **0 linhas erradas** em produção. Medido: das 2.041 parcelas de acordo, 1.906 têm o
override e 135 não — e as 135 carregam, todas, a marca da conta reconstruída. **Nenhuma parcela sem
override existe fora desse conjunto.**

⚠️ Não é possível contar "quantas nasceram por `parcelaInput`": `ParcelaAcordoImportavel` registra que
a parcela criada ali fica **indistinguível** da criada por `ImportarRelatorioCarteiraUseCase`. A
medição que sustenta a conclusão é a de cima, que não depende dessa separação. Separar de vez os dois
significados da coluna é tarefa de outra fatia.

### 10.5.2 🔴 O comando não adivinha: LISTA APROVADA (INV-H0)

O guard acima só grava o override com o valor batendo. **O comando não tem a planilha na mão** e não
consegue reproduzir essa checagem — o Valor acordado que decide não está no banco no momento da
correção.

**Três réguas automáticas foram tentadas para contornar isso, e as três caíram em revisão:**

| régua | por que caiu |
|---|---|
| procedência (nasceu de `reconstruirContaOriginal`) | atingia 3.482 quando o defeito são 135; apagaria R$ 102.126,32 |
| papel (`acordoOrigem IS NOT NULL`) sozinho | grava `bp = 0` em avulsa vinculada → alocação BRUTA indevida |
| marca na descrição como "filtro de segurança" | **medido: as 1.906 parcelas CERTAS não têm a marca**; e no dev 0 de 4 candidatas tinham — pularia justamente as que estão cobrando |

🔑 **Então a varredura deixou de decidir.** Ela PROPÕE; quem escolhe é o humano, passando `--ids` com
o que aprovou olhando a simulação. Candidata fora da lista é **pulada com motivo**; id aprovado que já
não é candidato **aborta tudo** (`AprovacaoNaoConfereException`, dentro da transação).

A simulação imprime a linha pronta para colar e diz o que conferir: a coluna **valor NO SISTEMA**
contra o Valor acordado da planilha. Tirar uma linha da lista é como o humano recusa uma obrigação.

⚠️ **O rótulo da coluna importa:** ela mostra o valor DO SISTEMA, que é justamente o número que quem
confere vai procurar na planilha. Uma redação anterior a chamava de "valor da planilha", o que
pré-respondia a pergunta.

📌 **Registrado, não escondido:** o comando é a única peça que grava `taxa_honorarios_bp = 0` sem ter
a planilha para conferir. É por isso que ele é o único que exige aprovação explícita.

### 10.6 Os erros das versões anteriores, registrados de propósito

A versão original desta §10 punha o guard em `reconstruirContaOriginal` e justificava assim: *"o
adapter SOMA todas as linhas do NN, então o honorário já está dentro do valorOriginal"*.

A soma é fato (`AcordosDetalhadosAdapter::montarContaOriginal`). **A conclusão não era.** Medido: das
3.482 contas reconstruídas, apenas **27** têm linha `1.15 - Honorário advocatício` dentro do grupo. A
justificativa cobria 0,8% da população que o conserto atingia.

Dois erros, os dois pegos pela revisão e confirmados por medição:

- **o número reportado estava 22× menor** — eu disse R$ 4.736,15 procurando com um filtro estreito;
  o carimbo aparece em 3.482 obrigações, R$ 104.890,48;
- **o conserto era largo demais** — atingiria 3.482 quando o defeito são 135.

🔑 **A lição, que é a §1.1 de novo:** eu escolhi a régua pela PROCEDÊNCIA ("nasceu daquela rotina")
quando a regra da contabilidade é sobre o PAPEL ("é parcela"). Régua derivada do código em vez de
derivada do relatório dela erra em silêncio — e teria apagado R$ 102.126,32.

🔴 **E a lição de segunda ordem, que custou mais duas revisões:** depois de trocar a régua, tentei
duas vezes salvar a varredura automática com um proxy melhor. Nas duas o proxy vazava, porque o dado
que decide — o Valor acordado declarado — **não existe no banco no momento da correção**. Quando o
sistema não tem como saber, a saída não é um proxy mais esperto: é parar de decidir e devolver a
escolha para quem tem a planilha na mão. É a §1.1 aplicada ao próprio comando.

📌 **Achado menor, medido e registrado, sem virar pendência aberta:** 7 contas originais
reconstruídas têm linha `1.15` dentro do valor e cobram honorário sobre honorário — **R$ 600,21**.
É outro mecanismo (não tem a ver com parcela) e não bloqueia nada.

### 10.7 O que fica FORA (fatia própria, decidida pelo dono em 19/08)

`CriarAcordoUseCase` e `EditarAcordoUseCase` criam parcela sem override nenhum. **Decisão do dono: a
escolha é do usuário, não do sistema** — a tela vai oferecer "cobrar honorário sobre as parcelas?",
padrão **não cobrar**, e o campo fica **somente leitura** em acordo que veio da contabilidade (todos
os 398 de hoje), para ninguém quebrar o espelho sem querer.

Sai desta fatia porque exige **migration** (a escolha mora no acordo, para a parcela acrescentada
depois nascer igual às irmãs) e porque **não muda número nenhum hoje**: medido em produção, das
2.041 parcelas de acordo, **zero** nasceram na tela — todas têm NN da contabilidade.
