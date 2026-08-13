# Parcela de acordo: aceitar a que só tem encargo, e parar de contar dinheiro duas vezes

**Risco: ALTO.** Corrige valor de obrigações **já gravadas** em produção e faz dinheiro novo entrar na
dívida. Escrito em 2026-08-12, reescrito no mesmo dia depois que a 1ª revisão derrubou quatro números
e achou um defeito maior do que o que a frente ia consertar.

> 🔴 **LEIA A §12 ANTES DE QUALQUER COISA.** Em 2026-08-12 a Fase 0 (espelho da contabilidade) entrou
> em produção e permitiu **remedir** esta frente contra o banco real. Duas premissas centrais desta
> spec caíram: os 17 boletos do defeito 1 **já estão no sistema**, e o defeito 2 **ainda não
> aconteceu** — está armado, não disparado. Os números das §1, §2 e §9 continuam corretos como
> descrição do *arquivo*, mas **não descrevem mais o estado do sistema**. A §12 diz o que mudou, com
> medição de produção.

> **Para quem for implementar:** os números aqui foram medidos rodando o **adapter real contra o
> arquivo real** de 12/08 e consultando o **banco de produção**. Onde diz "medido", é medido. A versão
> anterior desta spec tinha números lidos da coluna errada da planilha — se algum número aqui não
> reproduzir na sua máquina, **pare e meça**, não ajuste o código para caber no número.

## 1. São dois defeitos, e o segundo é maior

**Defeito 1 — a porta fechada (o que originou a frente).** `TopLifeInadimplenciaAdapter:201` recusa
boleto cujo principal (classes `1.1`/`1.14` + desconto `1.6`) seja zero. Medido no lote de 12/08:
**17 boletos da TOP LIFE I**, todos parcela de acordo, ficam fora. Efeito: **subcobrança** — o devedor
deve o que o sistema não mostra. TOP LIFE II e AMLI: zero casos.

**Defeito 2 — o dinheiro contado duas vezes (achado pela revisão, JÁ EM PRODUÇÃO).** Na parcela de
acordo, `valorOriginal` é a soma da coluna **H (Valor)** de *todas* as classes — inclusive das linhas
`1.4 - Juros`, `1.5 - Multas` e `1.15 - Honorário`. Mas o H dessas mesmas linhas **também** é somado
em `$jurosTotal`/`$multaTotal`/`$honorarios` (`TopLifeInadimplenciaAdapter:180-186`), e
`materializarEncargosImportados` (`ImportarRelatorioCarteiraUseCase:276-286`) grava esses
acumuladores **sem olhar se é parcela de acordo** — é chamado incondicionalmente para obrigação nova
(`:236`) e reimportada (`:260`).

🔑 **O defeito 2 não depende da porta.** Ele já atinge as parcelas de acordo que entram normalmente
hoje. **A frente do defeito 1 apenas o amplia** — nos 17 boletos barrados a fração duplicada chega a
100% do valor.

⚠️ **Consertar só o defeito 1 é pior do que não fazer nada:** hoje o sistema cobra **de menos**; com a
porta aberta e o defeito 2 vivo, passaria a cobrar **de mais**. Cobrar a mais de um devedor é o erro
que esta casa não pode cometer.

## 2. O que está medido

### 2.1 No arquivo de 12/08 (adapter real)

| | TOP LIFE I |
|---|---|
| grupos no arquivo | 3.023 (3.006 importáveis + 17 rejeitadas) |
| rejeitados por "sem principal" | **17**, todos com acordo na coluna N |
| valor que virará `valorOriginal` (Σ coluna H) | **R$ 5.152,19** |
| coluna Total (M) desses 17 | R$ 6.348,67 — **não é o que se grava** |
| acordos referenciados pelos 17 | **10** (155, 225, 339, 348, 369, 374, 394, 396, 414, 426) |
| desses, que **só nascem** com a mudança | **5** (348, 394, 396, 414, 426) |
| parcelas de acordo que **já entram** hoje | 90 |
| dessas, com linha `1.4`/`1.5` | **10** |
| dinheiro duplicado nessas 10 | **R$ 1.179,98** |

### 2.2 Em PRODUÇÃO (consultado em 12/08 pelo MCP de leitura)

| carteira | parcelas de acordo | com encargo gravado | juros+multa gravados |
|---|---:|---:|---:|
| TOP LIFE I | 1.845 | 696 | R$ 34.495,79 |
| TOP LIFE II | 54 | 23 | R$ 488,44 |
| AMLI BR 060 | 48 | 32 | R$ 559,97 |

**A assinatura do defeito:** na TOP LIFE I há **22 parcelas de acordo cujo juros gravado é maior que
metade do próprio valor original** — R$ 5.392,74 de encargo sobre R$ 2.630,98 de principal, a pior com
razão **9,2×**. Juros não chega nessa ordem por passagem de tempo a 1% ao mês.

⚠️ **Quantas das 1.947 estão infladas não foi determinado** — exige cruzar cada NN com a planilha.
Dimensionar isso é o **primeiro passo da implementação**, porque é ele que decide o tamanho da
reconciliação.

## 3. Por que existe parcela só de encargo

Explicação do dono (12/08): *"Fizemos um acordo de 10 taxas atrasadas e junto veio os juros e
honorários. A contabilidade coloca as taxas separadas para serem pagas nas primeiras parcelas do
acordo, para que sejam pagas logo."*

O acordo **redistribui** a dívida entre as parcelas, e a distribuição não é uniforme. Uma parcela só de
honorário/juros é o desenho normal do acordo.

⚠️ **Não escreva "é a cauda do acordo".** Medido: 8 dos 17 são parcela **1 ou 2** de acordos de 40, 20,
12, 10 e 6 parcelas. A distribuição não segue "taxa primeiro, encargo depois" de forma limpa. As
parcelas reais são `1/6, 1/10, 1/12, 1/20, 1/40, 2/12, 2/20, 2/40, 3/11, 3/40 (×3), 4/40 (×2), 5/40,
6/20, 6/40`. **Não existe parcela p>t** — a leitura anterior de "Parc. 5/4" era `5/40` truncado.

## 4. A hipótese de "boleto acessório" está refutada

Ela travou a frente desde 01/08: se o boleto de honorário fosse acessório de um de taxa, aceitá-lo
contaria o mesmo dinheiro duas vezes.

**A prova forte** (use esta): a regra de 10/07 descarta a linha acessória **dentro do mesmo NN**. Os 17
são **NNs próprios**, com vencimento próprio e acordo próprio — pela chave de agrupamento do adapter
(`:96-98`, unidade + NN) não podem ser linha acessória de ninguém.

**A ilustração** (não é prova sozinha): a unidade `01-09`, competência `08/2026`, tem `77103`
(Acordo 426 - Parc. 1/6), `75129` (Acordo 348 - Parc. 6/40) **e** o boleto de taxa `77133`. Três
obrigações, três origens — o "irmão" com principal é a taxa do mês, não o pai do honorário.

## 5. A regra correta — de onde sai cada pedaço

Na **parcela de acordo**, a coluna **H é o principal negociado**; as colunas **I/J/K/L** são os
encargos de atraso **da parcela**, e é só de lá que juros/multa/correção/honorário devem sair.

| pedaço | fonte |
|---|---|
| `valorOriginal` | Σ coluna **H**, todas as classes |
| juros | Σ coluna **I** — **sem** o H das linhas `1.4` |
| multa | Σ coluna **J** — **sem** o H das linhas `1.5` |
| correção | Σ coluna **K** |
| honorários | Σ coluna **L** — **sem** o H das linhas `1.15` |

Conferido ao centavo contra a contabilidade em dois casos:

**NN 67611** (linha única `1.4 - Juros`): valorOriginal 445,45 · juros 88,35 · multa 8,91 · honorário
108,54 → exigível **542,71**, total **651,25** = coluna M ✓. *Hoje gravaria exigível 988,16.*

**NN 65072** (linha `1.15`): valorOriginal 687,00 · juros 230,37 · multa 13,74 · honorário **186,22**
→ exigível **931,11**, total **1.117,33** = coluna M ✓. *Hoje gravaria honorário 687,00 — o mesmo
dinheiro do valorOriginal — e exibiria 1.618,11.*

🔑 **As conversões "H de 1.4 → juros", "H de 1.5 → multa" e "H de 1.15 → honorário" estão CERTAS para o
boleto comum**, onde `valorOriginal = principalCentavos` (só 1.1/1.14/1.6) e essas linhas ficam de
fora. Elas só duplicam na parcela de acordo. **Não as remova** — torne-as condicionais ao ramo.

## 6. A mudança

**É decisão de consumo, não de leitura.** O `BoletoImportavel` já carrega `principalCentavos` e
`somaColunaValorCentavos` lado a lado; o adapter pode continuar produzindo os dois conjuntos. Quem
precisa escolher é o ramo `$boleto->acordo !== null`.

**6.1 A porta** — `TopLifeInadimplenciaAdapter:201`: recusar apenas quando **não** for parcela de
acordo. `$acordoReconhecido` já existe e está preenchido nesse ponto em todos os caminhos (nasce em
`:152`, preenchido em `:190`, e o laço já terminou). A mensagem de recusa precisa mudar junto — hoje
descreve um caso que deixará de ser recusado.

**6.2 Os encargos** — `materializarEncargosImportados` (`:276-286`) precisa, no ramo do acordo, gravar
os encargos **sem** o H das linhas de encargo. Isto exige que o adapter exponha os dois conjuntos
(hoje só expõe o somado).

**6.3 Reconciliação das obrigações já gravadas.** O `INV-E1` do docblock do adapter (`:30-33`) avisa
que mexer nessa soma **altera saldo existente**. Precisa de plano: quantas linhas, quanto muda, como
se prova que o novo valor bate com a contabilidade. **Não é um `if`.**

### O que CONTINUA recusado

Boleto sem principal **e sem** acordo. Hoje não existe em nenhuma carteira (17/17 têm acordo). Aceitar
criaria obrigação de R$ 0,00 com o dinheiro preso nos acumuladores — outra decisão, não tomada.

## 7. Ressalvas medidas que a implementação vai encontrar

- **A hidratação SOBRESCREVE, não soma.** `EncargosVivos::hidratar()` (`:83-109`) recalcula a partir de
  `valorOriginal` e ignora o juros gravado. Logo a **tela mostra o valor certo** mesmo hoje.
- **Mas a hidratação é EM MEMÓRIA, sem flush** (docblock `:12`). A linha no banco continua inflada, e
  **toda leitura que não passa por ela vê o número errado**: SQL direto, o MCP, relatório agregado — e
  a conferência pós-importação, que é justamente o critério de aceite desta frente.
- **Obrigação congelada nunca é re-hidratada** (`:61`, `:88`; `Obrigacao::valorExigivel():231-233`). Se
  uma dessas for liquidada ou substituída antes de qualquer hidratação, **o valor inflado congela e
  vira permanente**.
- A competência desses 17 vem de linha de encargo, pelo fallback de `competenciaDoBoleto():232-241` — e
  é ela que amarra a dedup por (caso, NN, competência).
- `principalCentavos` continua sendo `0` para eles (`:215`). O ramo do acordo não usa, mas outros
  leitores desse campo, sim.

## 8. Testes exigidos

1. **Parcela de acordo composta só de linha `1.4 - Juros`**: `valorOriginal` e `juros` **não** podem
   carregar o mesmo dinheiro. É o teste que pega o defeito 2 — sem ele a frente entrega o defeito com
   a suíte verde. Use o NN 67611 como caso: exigível 542,71, total 651,25.
2. **Parcela de acordo com linha `1.15`**: honorário sai da coluna L (186,22), não do H (687,00).
3. **Boleto só de encargo COM coluna N** → entra. **Prove reintroduzindo o defeito**: com o predicado
   antigo, vermelho.
4. **Boleto só de encargo SEM coluna N** → continua rejeitado, com a mensagem nova.
5. **Não-regressão**: boleto com taxa + linha acessória de honorário continua entrando com
   `principalCentavos`. É o caso que a regra de 10/07 protege — e ele nem chega ao predicado, porque
   tem principal > 0.
6. Suíte completa verde.

## 9. Impacto esperado

Na primeira importação após o deploy, **TOP LIFE I**: 17 obrigações a mais, **R$ 5.152,19** de valor
negociado, **até 5 acordos** novos (348, 394, 396, 414, 426), zero rejeição do tipo. TOP LIFE II e
AMLI: nenhuma obrigação nova.

**Mais a reconciliação do defeito 2**, cujo tamanho é o primeiro passo a medir (§2.2).

A dívida em aberto **sobe** pelo defeito 1 (receita que o escritório passa a enxergar) e **desce** pelo
defeito 2 (dinheiro que estava contado duas vezes). **Os dois efeitos precisam ser reportados
separados** — somados, escondem um ao outro.

## 9.1 O critério de PRONTO — decidido pelo dono em 12/08

> *"Antes de importar vamos arrumar os problemas. Quero o sistema alinhado com o relatório da
> contabilidade."*

⚠️ **"Alinhado" NÃO é "o total bate ao centavo", e mirar nisso quebra o sistema.** O JusPrime
**recalcula juros/multa/correção ao vivo** a partir da configuração da carteira; a contabilidade
calculou os dela no instante da emissão. Os totais divergem por **desenho**, e a própria saída da
importação já mostra isso (*"60402: sistema R$ 1.131,24, planilha R$ 1.180,16 — o valor lançado NÃO
foi alterado"*). Perseguir igualdade de total levaria alguém a copiar o encargo da planilha e matar o
cálculo ao vivo.

**Alinhamento, aqui, tem três partes — e só as duas primeiras são exigíveis:**

1. **Mesmo conjunto de dívidas.** Todo NN que a contabilidade cobra existe no sistema, e nenhum a
   mais. É o defeito 1 (17 faltando).
2. **Mesmo principal.** O `valor_original` de cada obrigação é a soma da coluna **Valor (H)** daquele
   NN — sem encargo embutido. É o defeito 2.
3. **Encargos são do sistema.** Divergir da planilha é o comportamento correto. **Não conferir.**

**A conferência de aceite**, por carteira, é portanto: (a) contagem de NNs do relatório × NNs no
sistema, com a lista dos que faltam ou sobram; e (b) Σ coluna H do relatório × Σ `valor_original` no
sistema. Se (a) e (b) fecharem, está alinhado — mesmo com o total geral diferente.

**Essa conferência não existe hoje** e é entregável desta frente: sem ela, ninguém consegue afirmar
que o sistema está alinhado, que é exatamente a pergunta que originou tudo isto.

## 10. Fora de escopo

- Criar acordo pelo importador de **acordos** (segue valendo a §3.1 da spec dele).
- O caso sem acordo (§6, fim).
- Mexer em `obrigacaoInput():505-519`, que **já escolhe certo** no ramo do acordo.

## 11. Depois de implementar

Revisão adversarial contra esta spec, correções, **re-revisão**, e o deploy é do dono. Como a
importação é idempotente, os 17 entram sozinhos na primeira importação após o deploy — mas a
**reconciliação das já gravadas não acontece sozinha**.

## 12 🔴 Remedição de 2026-08-12 — o que a Fase 0 mediu no banco de produção

Esta seção existe porque a Fase 0 (`cobranca-espelho-da-contabilidade.md`) pôs a planilha da
contabilidade **dentro do banco**, e pela primeira vez foi possível conferir esta spec contra o
sistema em vez de contra o arquivo. Tudo abaixo foi medido pelo MCP somente-leitura de produção,
sobre o lote `relatorio_id = 5` (TOP LIFE I, `dados_ate` 12/08, 4.123 linhas de dado).

**O que continua valendo:** todos os números que descrevem o **arquivo** reproduziram ao centavo —
17 grupos sem principal, todos com acordo na coluna N, Σ H = **R$ 5.152,19**; e os da §2.2 (1.845 /
696 na TL1, 54 / 23 na TL2, 48 / 32 na AMLI; 22 parcelas com juros > metade do principal, R$ 5.392,74
sobre R$ 2.630,98, pior razão 9,20×). A spec mediu bem. O que mudou é o **estado do sistema**.

### 12.1 Defeito 1 — o código continua defeituoso; a consequência é ZERO

| medido em prod (12/08) | |
|---|---:|
| grupos que o adapter recusaria (principal 1.1/1.14/1.6 = 0) | **17** |
| desses, com acordo na coluna N | **17** |
| **desses, que JÁ EXISTEM no sistema** | **17** |
| **desses, que existem como parcela de acordo** | **17** |
| conferência da Fase 0: dívidas faltando na TL1 | **0** de 3.023 |
| conferência da Fase 0: principal diferente na TL1 | **0** |

**A premissa "17 boletos ficam de fora" caiu.** Eles entraram pelo **importador de acordos**
(`ImportarAcordosDetalhadosUseCase`), que não passa pela porta do adapter de inadimplência. O
`valor_original` das 107 parcelas de acordo presentes no relatório é **idêntico** à Σ H de todas as
classes, nas 107 — inclusive nessas 17.

⚠️ **Isso NÃO significa que o defeito 1 morreu.** A recusa continua em
`TopLifeInadimplenciaAdapter:201`, e continua errada: enquanto ela existir, **o caminho da
inadimplência nunca cria nem atualiza uma parcela só de encargo**. Hoje o importador de acordos tapa
o buraco; o dia em que uma parcela dessas aparecer só na inadimplência, ela some de novo. O defeito
mudou de classe: era **subcobrança medida**, virou **dependência não declarada de outro importador**.

➡️ **Consequência para o plano:** o §9 ("17 obrigações a mais, R$ 5.152,19, até 5 acordos novos") está
**errado hoje** — a primeira importação após o deploy criaria **0** obrigações novas por este
caminho, porque as 17 já existem e a importação é idempotente pela chave
`(caso, referencia_externa, competencia)`. O ganho da §6.1 passa a ser **de robustez**, não de
receita. Quem justificar a frente pela receita de R$ 5.152,19 vai justificar com número morto.

### 12.2 🔴 CORRIGIDO EM 13/08 — o defeito 2 NÃO está só armado: ele JÁ DISPAROU em 22 dívidas

> ⚠️ **Esta seção foi escrita em 12/08 afirmando "0 com a assinatura" e a afirmação estava errada.**
> A consulta que a produziu testava a assinatura **só no campo juros** (linha `1.4`). O defeito
> materializou pela **multa** (linha `1.5`). Medido em 13/08 pela peça 4 do espelho
> (`app:cobranca:espelho:encargos`), rodada em produção, e confirmado por SQL independente:

| carteira | dívidas com a assinatura | dinheiro duplicado |
|---|---:|---:|
| TOP LIFE I | 11 | R$ 697,30 |
| TOP LIFE II | 6 | R$ 234,57 |
| AMLI BR 060 | 5 | R$ 65,33 |
| **total** | **22** | **R$ 997,20** |

Por campo: **22 em multa · 0 em juros** (e o honorário em parte das mesmas dívidas). Foi por isso que a
medição só de juros deu zero.

**Conferido à mão, NN 74789:** linhas `1.5` somam R$ 301,62 de coluna Valor · coluna J soma R$ 8,00 ·
**multa gravada R$ 309,62** = a soma exata das duas. E os R$ 301,62 já estão dentro do
`valor_original` (R$ 399,37). O mesmo dinheiro em dois lugares, que é a definição do defeito 2.

**O que continua verdade da medição de 12/08:** nenhuma das 22 está congelada, então a hidratação ao
vivo recalcula na leitura e a **tela mostra o valor certo** (§7). O inflado está no banco e contamina
toda leitura que não hidrata. 🔴 **Mas obrigação congelada nunca é re-hidratada:** se alguma das 22
for liquidada ou substituída por acordo antes do conserto, o valor inflado vira permanente.

**O que muda no plano:** a reconciliação da §6.3 deixa de ser hipotética e passa a ter tamanho —
**22 obrigações, R$ 997,20**, todas identificáveis pela assinatura, em produção, hoje.

### 12.2.1 O que a medição de 12/08 acertou (e continua valendo)

Medido nas **107 parcelas de acordo** que aparecem no relatório de 12/08 e existem no sistema:

| | |
|---|---:|
| com `valor_original` == Σ H de todas as classes | **107 / 107** |
| com encargo gravado no banco | 104 |
| com a assinatura **no campo juros** (`juros gravado == Σ I + H das linhas 1.4`) | **0** |
| com encargo gravado igual às colunas I/J da planilha | **0** |

⚠️ **Estas duas linhas continuam corretas, e foram lidas como mais do que dizem.** "Zero no campo
juros" não é "zero dupla contagem" — a §12.2 mostra 22 dívidas pela **multa**. A lição é a da casa:
uma medição vale pelo recorte que ela cobre, e reportá-la sem o recorte é reportar outra coisa.

**A maioria do encargo dessas parcelas não veio do adapter de inadimplência** — veio do importador de
acordos, que calcula na data do acordo (`ImportarAcordosDetalhadosUseCase:1070`). Mas
`materializarEncargosImportados` **rodou sobre 22 delas**, e é daí que sai o defeito da §12.2.

**E a "assinatura do defeito" da §2.2 não mede um problema vivo.** Das 22 parcelas com juros > metade
do principal:

| | |
|---|---:|
| **liquidadas** | **21** de 22 |
| **congeladas** | **21** de 22 |
| presentes no relatório de 12/08 | **1** |
| com a assinatura da dupla contagem | **0** |

São **histórico já quitado e congelado**, não dívida que alguém vá cobrar. A §2.2 usou esse número
para dizer que o defeito 2 "já atinge as parcelas que entram hoje"; medido, ele não atinge — atingiria.

### 12.3 O tamanho real do defeito 2: o raio da PRÓXIMA importação

✏️ **Corrigido em 13/08:** o que está errado no banco **não** é zero — são 22 dívidas e R$ 997,20
(§12.2). Esta seção mede a outra metade: o que a **próxima** importação acrescentaria. As duas somam.

O defeito é de **consumo no import**, e a importação está bloqueada. Então o tamanho não é só "quanto
está errado no banco", e sim **quanto vira errado no instante em que a importação
rodar**. Medido no lote de 12/08:

| | TOP LIFE I |
|---|---:|
| parcelas de acordo no relatório | 107 |
| **com linha de encargo (`1.4`/`1.5`/`1.15`)** | **29** |
| dessas, **em aberto** (não liquidadas) | **29** |
| dessas, congeladas (protegidas do recálculo) | **0** |
| H das linhas `1.4` (viraria juros em dobro) | R$ 2.465,58 |
| H das linhas `1.5` (viraria multa em dobro) | R$ 730,84 |
| H das linhas `1.15` (viraria honorário em dobro) | R$ 4.127,47 |
| **🔴 total que a próxima importação contaria duas vezes** | **R$ 7.323,89** |

Dentro dessas 29 estão as **17** de principal zero — nelas a fração duplicada é 100% do valor, como a
§1 já dizia.

🔑 **A inversão que isto produz no plano.** A §1 diz *"consertar só o defeito 1 é pior do que não
fazer nada"*. Continua verdade, e ficou **mais forte**: hoje o sistema **não** cobra a menos (os 17
estão lá, com o principal certo), então abrir a porta não corrige subcobrança nenhuma — só entrega
R$ 7.323,89 de cobrança a maior. **A ordem correta é defeito 2 primeiro, defeito 1 depois** — ou os
dois no mesmo deploy, nunca o 1 sozinho.

### 12.4 A régua de aceite que a §9.1 pediu já existe — e a §9.1 está superada

A §9.1 dizia *"essa conferência não existe hoje e é entregável desta frente"*. Ela existe:
`app:cobranca:espelho:conferir` responde (a) mesmo conjunto de dívidas e (b) mesmo principal, por
carteira. Rodada em produção em 12/08: TL1 **3.023/3.023**, TL2 514/515, AMLI **38/38**, com **zero**
"principal diferente" nas três.

E a §9.1 item 3 (*"encargos são do sistema, não conferir"*) foi **substituída** pela premissa nova do
dono (espelho §1): a contabilidade é a verdade, o cálculo ao vivo é **projeção**, e divergir continua
esperado mas deixou de ser inconferível.

⚠️ **A régua ainda é cega para o defeito 2.** Nem a conferência nem a calibração leem o encargo
**gravado** em `cobranca_obrigacao` — a conferência compara `valor_original`, e a calibração compara a
nossa fórmula contra as colunas da planilha. Rodar `espelho:calibrar` antes e depois de consertar o
defeito 2 dá **o mesmo número**. Quem for implementar a Fase 1 precisa construir essa terceira
consulta (encargo gravado × colunas I/J/L do espelho) **antes** de tocar no adapter, senão não tem
como provar o próprio conserto.

### 12.5 🔴 Entrou um TERCEIRO item na Fase 1, e ele quase anula o defeito 2

Decisão do dono em 12/08 (registrada na §16.3 do espelho): **o sistema espelha a contabilidade, esteja
ela certa ou errada.** Isso derruba a **decisão #8** do importador de acordos
(`ImportarAcordosDetalhadosUseCase::parcelaInput():1286-1290`, `honorariosBp = 0`): a contabilidade
cobra honorário na parcela de acordo vencida, logo o sistema tem de mostrar.

A Fase 1 passa a ter **três** efeitos de dinheiro, e eles apontam para lados diferentes:

| # | mudança | efeito na dívida | tamanho medido (lote 12/08) |
|---|---|---|---:|
| 1 | abrir a porta do adapter (defeito 1) | — | **R$ 0** (as 17 já existem) |
| 2 | parar de contar o H das linhas de encargo (defeito 2) | **desce** | **R$ 7.323,89** |
| 3 | reverter a decisão #8: honorário na parcela de acordo | **sobe** | **R$ 7.227,62** |

⚠️ **O 2 e o 3 quase se cancelam — a diferença líquida é da ordem de R$ 96.** Um relatório que some os
efeitos vai mostrar "praticamente nada mudou" enquanto **103 devedores sobem** e **29 descem**. A §9
já mandava reportar separado; agora isso deixou de ser boa prática e virou a única forma de a mudança
ser auditável. **Nunca reporte o líquido.**

E a ordem da §12.3 continua valendo, agora com três: **o item 1 é o único que não pode ir sozinho.**

### 12.7 ✅ A prova de que o conserto ESPELHA a contabilidade (medida em 13/08)

Pergunta do dono, e é a pergunta certa: *"parar de gravar o valor da linha de encargo como encargo —
isso é o entendimento da contabilidade?"* Sob a premissa do módulo (§16.3 do espelho), o sistema não
pode consertar segundo a **nossa** régua; tem de reproduzir a **deles**.

**Medido no lote de 12/08, nas parcelas de acordo da TOP LIFE I** — para cada linha, a multa lançada
pela contabilidade é 2% do Valor **daquela linha**:

| classe da linha | linhas | multa == 2% do Valor da própria linha |
|---|---:|---:|
| `1.1` taxa de condomínio | 259 | **259** |
| `1.14` energia | 114 | **114** |
| **`1.5` multas** | 23 | **23** |
| **`1.4` juros** | 21 | **21** |
| **`1.15` honorário** | 23 | **23** |

🔑 **A contabilidade cobra multa SOBRE a linha de multa** — e sobre a de juros, e sobre a de
honorário, do mesmo jeito que sobre a taxa de condomínio. Em 100% das linhas.

Só há uma leitura: para ela, o Valor daquelas linhas é **principal** — dívida velha incorporada ao
acordo. Se fosse encargo, ela não cobraria encargo em cima.

**No NN 74789:** a multa que a contabilidade mostra é **R$ 8,00** (2% de R$ 399,37). Os **R$ 309,62**
que o sistema gravava **não existem em nenhuma coluna da planilha** — eram a soma que
`materializarEncargosImportados` fazia. O conserto devolve a régua deles.

⚠️ **Ressalva, e é outra frente:** no **boleto comum** o sistema também não classifica como a
contabilidade — ela trata a linha `1.4` como principal e cobra encargo em cima; nós a excluímos do
principal e a jogamos no encargo. **O total fica igual, a classificação não.** Fora do escopo do
defeito 2, e minúsculo hoje: **1 boleto** na TOP LIFE I, R$ 6,28. Mexer nisso mudaria `valorOriginal`
de boleto comum, que é raio de explosão muito maior — decisão própria, não subproduto desta.

### 12.6 O que esta remedição NÃO muda

- ⛔ **A importação em produção segue bloqueada pelo dono.** Remedir a spec libera planejar, não importar.
- A refutação da "hipótese de boleto acessório" (§4) continua de pé — não dependia de nenhum número daqui.
- A regra correta da §5 (H é principal; I/J/K/L são os encargos) continua correta e continua não
  implementada.
- As ressalvas da §7 continuam válidas, com um ajuste: as **21 parcelas congeladas e liquidadas** da
  §12.2 são exatamente o cenário que a §7 previa ("o valor inflado congela e vira permanente") — só
  que o valor congelado nelas **não** é inflado por dupla contagem. Congelaram por liquidação normal.
