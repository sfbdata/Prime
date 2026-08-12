# Parcela de acordo: aceitar a que só tem encargo, e parar de contar dinheiro duas vezes

**Risco: ALTO.** Corrige valor de obrigações **já gravadas** em produção e faz dinheiro novo entrar na
dívida. Escrito em 2026-08-12, reescrito no mesmo dia depois que a 1ª revisão derrubou quatro números
e achou um defeito maior do que o que a frente ia consertar.

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
