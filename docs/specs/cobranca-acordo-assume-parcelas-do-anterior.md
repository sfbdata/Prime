# SPEC — O acordo novo assume as parcelas do anterior

**Risco: ALTO.** Mexe na INV-I, a guarda que hoje protege o saldo contra dívida contada em dobro.
Exige spec (este arquivo), teste provado por reintrodução do defeito e **duas** passadas de `/review`.

**Frente:** §15 do `docs/gestao-cobrancas/HANDOFF_AUTOMATIZAR_DOWNLOADS.md`. **Substitui** a frente da §14.

**Banco de medição:** `saas_ux_zero` (importação do zero completa, TL1 + TL2 — a prova do item 8).
**Planilhas:** `docs/gestao-cobrancas/planilhas atualizadas/2026-08-04-api/` (gitignored, PII).

---

## 1. O problema, em uma frase

Quando a contabilidade renegocia uma parcela de um acordo dentro de um acordo novo, o sistema recusa
registrar essa substituição — e passa a cobrar a mesma dívida duas vezes: a parcela velha **e** a parcela
nova que a substituiu.

---

## 2. O que foi MEDIDO (não suposto)

Tudo abaixo foi medido no `saas_ux_zero` contra as planilhas de 04/08. Os scripts estão em
`docs/gestao-cobrancas/planilhas atualizadas/_f15_*.php`.

### 2.1 A coluna F é confiável, e é a única prova documental

A seção **"Relação das contas originais"** da planilha de **Acordos detalhados** tem 6 colunas.
A sexta, **F ("Detalhamento")**, diz de onde veio aquela dívida.

| medição | resultado |
|---|---:|
| grupos (NN + competência) na seção de contas originais, TL1+TL2 | 5.029 |
| grupos em que as linhas do mesmo grupo trazem detalhamento **divergente** | **0** |
| formas distintas do texto da coluna F | **2**: `-` (6.222 linhas) e `Acordo N - Parcela N/N` (2.213) |
| contas originais que declaram `Acordo V - Parcela p/t` (chaves distintas, TL1) | **457** |
| dessas, em que o V declarado **difere** do acordo que o sistema já registrou como origem | **0** |
| dessas, disputadas por **mais de um** sucessor não cancelado | **0** |

Duas leituras importam:

1. **A coluna F nunca contradiz o sistema.** Em 100% dos casos em que o sistema já sabia que aquele
   boleto era parcela do acordo V, a coluna F diz exatamente `Acordo V`. Ela não é a chave de busca —
   a chave continua sendo **NN + competência**. Ela é a **prova de que a substituição é intencional**.
2. **O texto é regular.** Só duas formas no dado inteiro. O parser pode ser estrito sem perder nada.

### 2.2 A unidade da substituição é a PARCELA, não o acordo

Este é o achado que corrige a §15 do handoff. Exemplo real — aba do acordo **393** (arquivo
`top_life_1_Acordos_detalhados_LIQUIDADO.xlsx`):

```
Relação das contas originais
NN 75125 | 1.15 Honorário advocatício | 04/2026 | 10/04/2026 | 242,05 | Acordo 348 - Parcela 2/40
```

O acordo 393 vale R$ 301,69 inteiro. Ele **não substituiu o acordo 348** — renegociou **uma parcela**
dele, a 2 de 40. O acordo 348 continua com **38 parcelas em aberto (R$ 9.197,90)**.

Aplicando a régua parcela a parcela aos 26 acordos velhos que perdem parcelas para um sucessor (TL1):

| | acordos | efeito |
|---|---:|---|
| ficam **sem nenhuma** parcela em aberto | **20** | substituídos de fato — devem sair dos vigentes |
| **continuam** com parcelas em aberto | **6** | renegociação parcial — seguem legitimamente vigentes |

Os 6 parciais: 163, 244, 255, 306, 332, 61. O 255, por exemplo, perde 13 parcelas e **fica com 14
(R$ 2.488,64)**. ⚠️ Estes 26 são os acordos *citados como origem*; a contagem que a §6.1 usa para o selo
da tela é outra (**37**), porque parte por parcela ainda paga, não por acordo citado.

🔴 **Consequência para a reclamação ao suporte:** dos 4 acordos que o handoff lista como bug do Group
Software (348, 292, 372, 82), **3 não são bug**:

| acordo | o sucessor assumiu | o velho ainda deve |
|---|---|---:|
| 348 | 1 parcela de 40 — R$ 242,05 | 38 parcelas — R$ 9.197,90 |
| 292 | 1 parcela de 40 — R$ 191,76 | 28 parcelas — R$ 5.369,48 |
| 372 | 2 parcelas de 40 — R$ 511,64 | 37 parcelas — R$ 9.460,16 |
| **82** | 1 parcela (8/10) — R$ 278,50 | **1 parcela — R$ 278,50** ← zera |

348, 292 e 372 estão "Em andamento" porque **estão mesmo**. Só o **82** é candidato à reclamação.
Decisão do dono (07/08): **reclamar só do 82**.

### 2.3 Hoje a INV-I CAUSA a dobra que foi escrita para impedir

Estado atual do `saas_ux_zero`, carteira TOP LIFE 1:

| | obrigações | principal |
|---|---:|---:|
| parcelas velhas que a coluna F prova terem sido renegociadas, **ainda no saldo** | **286** | **R$ 63.961,06** |
| parcelas dos acordos sucessores, **também no saldo** | 387 | R$ 100.483,89 |

🔴 **Esta linha dizia 302 / R$ 67.469,44 até a 2ª revisão cobrar a reconciliação com o efeito medido, e
o número era MEU erro de medição** — o script contava também as contas reivindicadas por sucessores do
arquivo `*_CANCELADO.xlsx`, que não é importado. A conta fecha exata: **457** chaves declaradas na coluna
F, menos **19** só de sucessor cancelado = **438**; dessas, **286** casam com uma parcela viva de acordo
vigente e **152** correspondem a obrigações sem vínculo de origem no sistema (que já funcionam hoje).
286 + 152 = 438. A medição também tem defeito, inclusive a minha.

A mesma dívida, do mesmo devedor, contada duas vezes. A importação do zero produziu **286 recusas da
INV-I** exatamente aqui.

⚠️ **Número grande não é dinheiro na tela:** os R$ 63.961,06 são **principal**, não o valor com encargos
que a tela exibe. O que sai do saldo é essa parcela de principal mais os encargos que ela acumulou.

### 2.4 O sucessor CANCELADO não conta

129 das 457 contas são citadas por **dois** sucessores — e em **todas** um deles está no arquivo
`CANCELADO`. É a renegociação que fracassou: a dívida velha volta a valer. **19 contas são citadas
só por sucessor cancelado** e devem continuar cobradas.

Isso já está resolvido por construção: o arquivo `*_CANCELADO.xlsx` não é importado (decisão do dono), e
mesmo que fosse, `ImportarAcordosDetalhadosUseCase` pula aba de acordo não vigente (§12.5 do handoff).
**A spec não precisa de regra nova para isso — precisa de teste que trave a propriedade.**

### 2.5 O efeito sobre a frente da §14

As **241 parcelas / R$ 51.738,56** da §13.7 (vencidas, que a inadimplência não lista) reproduzem exatas
na medição — o que valida o método. Com esta regra:

| | parcelas | valor |
|---|---:|---:|
| a coluna F prova a substituição → **resolvidas** | **214** | **R$ 46.258,31** |
| sobram para a §14 | 27 | R$ 5.480,25 |

### 2.6 O que NÃO muda

- **TOP LIFE 2: zero.** Nenhuma conta original da TL2 declara parcela de acordo anterior. A carteira
  inteira é indiferente a esta mudança.
- **152 contas** declaradas pela coluna F correspondem a obrigações que no sistema **não têm acordo de
  origem** (131 porque o vínculo nunca foi feito, 21 porque o acordo velho não existe no banco). Essas
  **já funcionam hoje** — a INV-I não as bloqueia. Nada muda para elas.
- **3 contas** declaradas não existem no sistema; seguem sendo reconstruídas como hoje (§3.2.1).

---

## 3. Decisões do dono (07/08)

| # | Pergunta | Decisão |
|---|---|---|
| D1 | Como o acordo totalmente assumido aparece na tela? | **Rótulo derivado.** Ele já sai da seção "Dívida" sozinho e cai em "Acordos encerrados"; passa a exibir **"Substituído pelo acordo #N"**. Sem estado novo no banco, sem migração. A planilha continua dizendo "Em andamento" e não briga com o sistema. |
| D2 | A guarda relaxada vale na tela também? | **Só a importação.** O `CriarAcordoUseCase` continua recusando acordo sobre acordo — lá não existe prova documental. A prova da coluna F é a **condição** de aceitar. |
| D3 | Reclamar com o suporte? | **Só do acordo 82.** |

---

## 4. A regra, em português

> Uma conta original só pode ser marcada como substituída mesmo sendo parcela de outro acordo **quando a
> coluna F daquela linha declarar, textualmente, que ela é parcela exatamente daquele acordo**.

Nos dois sentidos:

- **aceita** — a coluna F diz `Acordo 163 - Parcela 4/12` e o sistema tem aquele boleto como parcela do
  acordo cujo **número externo** é 163 → marca como substituída pelo acordo novo;
- **recusa** — a coluna F está vazia (`-`), traz outro texto, ou declara um acordo **diferente** do que o
  sistema registrou → mantém a recusa de hoje, com a mensagem de hoje.

O conjunto `:vigentes`/`:naoVigentes` de `doCasoExigiveis` **não muda**. A parcela velha sai do saldo pela
derivação que já existe (`acordoSubstituto` vigente ⇒ fora do exigível). Nenhuma query de exigibilidade é
tocada.

---

## 5. O que muda no código

### F1 — o adapter passa a ler a coluna F

`app/src/Cobranca/Service/Importacao/AcordosDetalhadosAdapter.php`

- nova constante `ORIG_DETALHAMENTO = 5` (a classe já **documenta** a coluna no docblock e nunca a leu);
- parse **estrito** de `Acordo (\d+) - Parcela (\d+)/(\d+)`; qualquer outra coisa (inclusive `-` e vazio)
  → não declarado;
- exige a declaração em **TODAS** as linhas do grupo, apontando o **mesmo** acordo — mais estrito do que
  classe/competência/vencimento, que leem só a primeira. Qualquer linha sem a declaração, ou apontando
  outro acordo, faz o grupo valer como **não declarado**. Medido hoje: 0 grupos divergentes. A rigidez é
  deliberada: aceitar por maioria faria uma mudança futura no formato da fonte passar em silêncio e tirar
  dívida do saldo; exigir unanimidade faz a mesma mudança cair na recusa, que é impressa no relatório.

`app/src/Cobranca/Service/Importacao/ContaOriginalImportavel.php`

- dois campos novos, ambos `?…`: `acordoOrigemDeclarado` (int, o número externo) e `parcelaOrigemDeclarada`
  (string `p/t`, só para a mensagem do relatório).

### F2 — a INV-I do importador aceita **com prova**

`app/src/Cobranca/UseCase/ImportarAcordosDetalhadosUseCase.php`, em `reconciliarContasOriginais`.
São **duas** portas, e as duas precisam mudar — senão a mesma linha é aceita ou recusada conforme a ordem
das abas no arquivo:

**Porta A — a obrigação já está no banco** (hoje `:737`)

```
if ($obrigacao->getAcordoOrigem() !== null) { recusa (INV-I) }
```
passa a aceitar quando `$conta->acordoOrigemDeclarado === $obrigacao->getAcordoOrigem()->getNumeroExterno()`.
A comparação é pelo **número externo** (o número que a contábil usa e que aparece na planilha), nunca pelo
`id` interno.

⚠️ A mensagem de recusa de hoje imprime `getAcordoOrigem()->getId()` — o id **interno**. Para quem confere
contra a planilha isso é um número que não existe em lugar nenhum. Passa a imprimir o número externo.

**Porta B — a obrigação foi criada ou vinculada nesta MESMA execução** (hoje `:698-707`, os tipos
`parcela` e `parcela-vinculada`) — é a porta que produziu as 286 recusas.

`ObrigacoesTocadasNaImportacao` passa a guardar, junto do tipo, **o número externo do acordo** que criou
ou vinculou aquela parcela e **o valor em centavos** com que ela nasceu. A porta B aceita quando o
declarado bate com esse número.

🔑 **Paridade prévia × confirmação (invariável §6 da spec-mãe).** Na porta B, no dry-run a obrigação **não
existe no banco** e na confirmação existe. Se a prévia contasse pelo acumulador e a confirmação pela
entidade, os dois números divergiriam. Portanto: **os dois modos contam pelo valor guardado no
acumulador**, que veio da planilha e é idêntico nos dois. A escrita (`setAcordoSubstituto` +
`materializarNaDataDoAcordo`) só acontece na confirmação, como todo o resto.

### F3 — a guarda que fica alcançável

`RomperAcordoUseCase` e `CancelarAcordoUseCase` já recusam romper/cancelar um acordo cujas parcelas foram
renegociadas por outro acordo vigente (`AcordoComParcelasRenegociadasException`, a INV-L). O docblock dela
diz *"só alcança dado legado — INV-I bloqueia criar o estado hoje"*. **Depois desta spec o estado passa a
ser criável, e a guarda vira a proteção principal contra o dano da §2.1 do ajuste 9.** Não muda código —
muda de estatuto, e passa a exigir teste próprio.

Uma correção é necessária: `motivoParaNaoDesativar` (`:900`) descobre parcelas renegociadas por **query ao
banco**. Numa importação em que o acordo velho é processado **antes** do sucessor, a query não vê nada e a
desativação passaria. Passa a consultar também o acumulador da execução — o mesmo padrão que o resto do
importador já usa.

### F4 — a tela

- `AcordoOutput` ganha `?int $substituidoPeloAcordoId`, **derivado**, sem coluna nova;
- `MontarDetalheCasoUseCase` calcula a partir das obrigações que **já carregou por query** (nunca pela
  coleção inversa do Doctrine): o acordo A recebe o rótulo quando **não tem nenhuma parcela viva** e
  **ao menos uma** parcela dele está substituída por um acordo vigente B;
- `templates/cobranca/objeto/_partials/_movimentos.html.twig` exibe **"Substituído pelo acordo #N"** na
  linha do acordo em "Acordos encerrados".

O acordo **parcialmente** renegociado (os 6 casos) **não** recebe o rótulo: ele tem parcela viva, vira
grupo na seção "Dívida" e continua vigente — que é o que a medição diz ser verdade.

---

## 6. Invariantes

| # | Invariante | Como se prova |
|---|---|---|
| **INV-S1** | Sem prova na coluna F, a recusa da INV-I permanece — nas duas portas | teste de recusa com `-`, com texto estranho e com acordo **diferente** |
| **INV-S2** | A comparação é por **número externo** do acordo, nunca pelo id interno | teste com id interno ≠ número externo (obrigatório: no dado real eles divergem) |
| **INV-S3** | Prévia e confirmação produzem os **mesmos** números nas duas portas | teste que roda `prever()` e `confirmar()` sobre a mesma entrada e compara |
| **INV-S4** | `doCasoExigiveis` não é tocada; a parcela velha sai do saldo por derivação | teste de saldo antes/depois |
| **INV-S5** | Romper/cancelar o acordo velho com parcelas renegociadas continua recusado | teste do `RomperAcordoUseCase` e do `CancelarAcordoUseCase` sobre o estado agora criável |
| **INV-S6** | A importação nunca desativa acordo cujas parcelas foram renegociadas **nesta mesma execução** | teste com aba do velho **antes** da aba do sucessor |
| **INV-S7** | `CriarAcordoUseCase` (tela) continua recusando acordo sobre acordo | os testes existentes continuam verdes, sem alteração |
| **INV-S8** | Acordo parcialmente renegociado continua vigente e com grupo na tela | teste do `MontarDetalheCasoUseCase` |
| **INV-S9** | Sucessor cancelado não tira a dívida velha do saldo | teste com aba `Cancelado` |
| **INV-S10** | Nunca apagar (invariável 14): a parcela velha continua existindo, só sai do exigível | teste que confere a linha no banco |

---

## 6.1 ✅ O EFEITO MEDIDO — banco descartável `saas_ux_f15` (clone do `saas_ux_zero`)

Importação dos 4 arquivos (TL1 e TL2, `EM_ANDAMENTO` e `LIQUIDADO`) com `--confirmar`, e a **mesma
importação rodada antes com a guarda antiga reinjetada**, para isolar o que é desta mudança.

### Saldo exigível (principal)

| carteira | antes | depois | delta |
|---|---:|---:|---:|
| **TOP LIFE I** | 3.858 obrigações · R$ 644.590,04 | 3.572 · R$ 580.628,98 | **−286 · −R$ 63.961,06** |
| TOP LIFE II | 537 · R$ 94.081,36 | 537 · R$ 94.081,36 | **0** |

⚠️ **O relatório imprime R$ 77.916,65** ("principal que sai") somando as 369 contas marcadas — mas
**R$ 13.955,59 delas já estavam fora do saldo** (contas já liquidadas de acordos `Liquidado`). O que sai
da tela é **R$ 63.961,06**. É a sexta vez nesta frente que número grande de relatório não é dinheiro na
tela; a medição é a diferença do saldo, nunca a soma que o comando imprime.

### O que mais mudou no banco (e o que não mudou)

| | delta | é desta mudança? |
|---|---:|---|
| obrigações criadas/apagadas · soma de `valor_original` · alocações · pagamentos · casos · acordos | **0** | — |
| status de acordo alterado | **0** | — |
| obrigações com `acordo_substituto` | **+286** | ✅ **sim, e é só isto** |
| obrigações com `acordo_origem` | +131 | ❌ **não** — a linha de base com a guarda antiga produz os mesmos +131 |
| obrigações com origem **e** substituto | +417 | 286 desta mudança + 131 da linha de base |

🔑 **A linha de base revelou um furo antigo na INV-I:** rodando com a guarda original, **131 obrigações
ficam com `acordoOrigem` E `acordoSubstituto`** — o estado "acordo sobre acordo" que a INV-I existia para
impedir. Elas escapam porque o `acordoSubstituto` é gravado enquanto `acordoOrigem` ainda é nulo, e o
vínculo de origem chega depois, por outra aba. **A INV-I nunca impediu o estado; impedia uma das ordens
de chegada.** Isso não é defeito introduzido aqui, e não muda o saldo — mas desfaz a premissa de que a
guarda era uma barreira.

### A tela

| forma do acordo velho | acordos | o que passa a mostrar |
|---|---:|---|
| não sobrou parcela nenhuma | **8** | "Acordos encerrados", selo **"Substituído pelo acordo #N"** |
| sobraram só parcelas **pagas** | **29** | continua como grupo na seção Dívida, com o mesmo selo |
| sobrou parcela **em aberto** | 12 | nada muda: segue vigente e cobrando |

**37 acordos deixam de se anunciar "Ativo"** (antes desta mudança: zero).

🔑 **A régua do selo custou uma medição para ficar certa.** A primeira versão usava a mesma régua do
agrupamento ("qualquer parcela não substituída") e pegava **8** de 37 — deixando de fora justamente os
29 do caso comum (o devedor pagou 1 a 3 parcelas antes de renegociar o resto). O selo passou a viajar
também no `GrupoAcordoObrigacoesOutput`, para que o acordo que ainda vira grupo se anuncie igual.

## 6.2 Prova por reintrodução do defeito — 15 injeções, 15 vermelhos

Cada linha foi aplicada ao código, a suíte rodada, o teste vermelho conferido, e o código restaurado.

| injeção | teste que ficou vermelho |
|---|---|
| aceita tudo sem prova | 7 testes de recusa, inclusive o `testNaoMarcaParcelaComoSubstituida` que já existia |
| recusa tudo (INV-I antiga) | 9 testes, todos os de aceite |
| compara pelo **id interno** em vez do número externo | `testConfereProcedenciaPeloNumeroExternoENaoPeloIdInterno` (+4) |
| permite autossubstituição | `testAcordoNaoSubstituiParcelaDeSiMesmo` |
| aceita origem não vigente | `testNaoAssumeParcelaDeAcordoNaoVigente` |
| guarda de desativação só olha o banco | `testNaoDesativaAcordoComParcelaRenegociadaNaMesmaExecucao` |
| principal vem da planilha, não do acumulador | `testPortaBNaoDivergeEntrePreviaEConfirmacao` |
| adapter não lê a coluna F | 2 testes do adapter |
| adapter com parse frouxo | `testColunaFComTextoForaDoPadraoNaoDeclara` |
| adapter lê só a primeira linha do grupo | `testColunaFDivergenteEntreLinhasNaoDeclara` |
| selo sem checar parcela que sobrou | `testAcordoParcialmenteRenegociadoContinuaVigente` |
| régua do selo ignora parcela paga | `testAcordoComSobraApenasPagaSeAnunciaSubstituido` |
| grupo volta a mostrar o status | `testAcordoComSobraApenasPagaSeAnunciaSubstituido` |
| template volta a mostrar o status | `testAcordoTotalmenteAssumidoMostraOSucessor` |

⚠️ **Duas injeções passaram batido na primeira rodada** e obrigaram a corrigir os testes, não o código:

1. **guarda de desativação só olha o banco** — na confirmação a marcação já está gravada quando a aba do
   acordo velho chega, então a query enxerga tudo e a guarda funciona mesmo sem o acumulador. O defeito
   só aparece no **dry-run**, onde nada é gravado: a prévia prometeria um cancelamento que a confirmação
   não faria. O teste passou a comparar prévia × confirmação.
2. **régua do selo** — o acordo parcialmente renegociado vira grupo na seção Dívida, e o selo só era
   exibido em "Acordos encerrados"; um valor errado no DTO ficava invisível. O teste passou a conferir a
   derivação no `AcordoOutput`, antes de virar HTML.

Suíte completa: **3.407 testes verdes**.

## 7. O que esta spec NÃO faz

- **não** cria estado novo no `StatusAcordo` nem coluna nova em `cobranca_acordo` (D1);
- **não** mexe no `CriarAcordoUseCase` nem no modal da tela (D2);
- **não** mexe no aviso de divergência (item 3 da fila — decisão do dono, por último);
- **não** mexe em `doCasoExigiveis`, `exigiveisDosCasos` nem em nenhuma query de exigibilidade;
- **não** liga a acordo de origem as 152 contas que hoje entram sem vínculo — elas já funcionam;
- **não** importa o arquivo `*_CANCELADO.xlsx`;
- **não** resolve as 27 parcelas / R$ 5.480,25 que sobram da §14.

---

## 8. Riscos aceitos e registrados

1. **A cadeia.** 22 acordos são, eles mesmos, sucessores de outro (31 → 211 → 396). Uma obrigação pode
   passar a ter `acordoOrigem` **e** `acordoSubstituto` ao mesmo tempo. O modelo aceita (são dois
   `ManyToOne` independentes, sem índice único), e `doCasoExigiveis` a exclui pela cláusula do substituto.
   **Precisa de teste de cadeia de 3 níveis.**
2. **Cancelar no meio da cadeia.** Se o acordo do meio for cancelado, as parcelas que ele substituiu voltam
   ao saldo **e** as parcelas do acordo mais novo continuam nele — é o dano da §2.1. A INV-L barra o
   caminho manual e o `motivoParaNaoDesativar` barra o da importação (com a correção da F3). Não há
   terceiro caminho. **Precisa de teste nos dois.**
3. **Ordem das abas.** Aceitar na porta A e recusar na porta B (ou vice-versa) faria o resultado depender
   da ordem do arquivo. Por isso as duas portas mudam juntas, e a INV-S3 as trava.
4. **Lote com a MESMA aba duas vezes, uma delas `Cancelado`.** `processar()` não deduplica abas por
   número. Uma aba pode desativar o acordo antes de a aba do sucessor chegar, e nesse instante
   `motivoParaNaoDesativar` ainda não tem nada marcado para barrar. A **§9.2** fecha a coerência do
   estado (a marcação passa a ser recusada), mas o saldo ainda soma as originais devolvidas pelo
   cancelamento com as parcelas do sucessor — o dano do §2.1, que aqui vem do **cancelamento**, não da
   marcação. Não é alcançável com as planilhas de hoje: `Cancelado` só existe no arquivo
   `*_CANCELADO.xlsx`, que não é importado. **Registrado, não corrigido.**

---

## 9. 1ª REVISÃO — 5 correções aplicadas, 2 achados medidos e recusados

O `feature-review-agent` confirmou o efeito medido (−286 obrigações, −R$ 63.961,06; nenhuma linha criada,
apagada ou repontada) e apontou 3 bloqueantes e 7 observações. O que foi feito com cada um:

### 9.1 A porta B pulava a guarda de "já substituída" que a porta A tem — **corrigido**

`completarParcelas` vincula uma obrigação solta olhando **só** `acordoOrigem === null`, sem olhar o
substituto. Uma obrigação já substituída por acordo vigente podia, portanto, chegar à porta B como
`parcela-vinculada` — e o `setAcordoSubstituto` a repontaria **em silêncio**. Três danos: assimetria com
a porta A (o que a spec §8.3 declara inaceitável), principal somado como "sai do saldo" para dívida que
já estava fora, e perda da única memória de quem a substituiu antes (o rompimento daquele acordo deixaria
de devolvê-la). A guarda da porta A foi espelhada, com a mesma idempotência.

**Pré-condição real:** 131 obrigações que já tinham substituto ganham `acordoOrigem` nesta importação.
Não se materializou nas planilhas de 04/08 (0 trocas medidas) — a guarda é o que garante que continue
assim. Travado por `testPortaBNaoTrocaSubstitutoExistente`.

### 9.2 A porta B **supunha** a vigência do acordo de origem — **corrigido**

O código afirmava, por comentário, que "`completarParcelas` só roda para aba vigente, logo a origem é
vigente". Verdade no instante da criação — e não depois, se uma aba seguinte do lote desativar o acordo.
O acumulador passou a guardar a **entidade** `Acordo` em vez do número, e a vigência é lida dela na hora
da decisão. Travado por `testPortaBRecusaQuandoAbaAnteriorDesativouOAcordoDeOrigem`.

⚠️ **Medido, e corrige o que o revisor afirmou:** a recusa aqui é de **coerência**, não de dinheiro. A
parcela de um acordo cancelado já está fora do saldo por derivação, então marcá-la ou não dá o mesmo
total (medido no teste: R$ 890,00 nos dois casos). O dano de dinheiro do cenário vem do **cancelamento**,
e está registrado no risco 4 acima.

### 9.3 O selo de substituição estava **no lugar** do selo de estado — **corrigido**

O comentário do template prometia "ao lado", o código fazia `if/else`. Medido: dos 37 acordos que
recebem o selo, **20 estão `Cumprido`** — a baixa que a contábil deu sumia da tela. Os dois selos passam
a conviver. Consequência para a spec e para a mensagem de commit: *"37 acordos deixam de se anunciar
Ativo"* estava errado — **17 diziam "Ativo" e 20 diziam "Cumprido"**. Travado por
`testAcordoCumpridoAssumidoMantemOEstadoDaContabil`.

### 9.4 O selo escolhia um sucessor a esmo quando havia vários — **corrigido**

Medido: **8 acordos** tiveram as parcelas divididas entre sucessores diferentes, um deles entre **12**.
`sucessorPorAcordo` fazia "o último vence", então o número exibido dependia da ordem da query e era falso
para as demais parcelas. Agora o id fica **nulo** e `qtdSucessores` responde: a tela diz *"Substituído por
N acordos"*. Travado por `testAcordoAssumidoPorVariosNaoInventaUmSucessor`.

### 9.5 A mensagem de recusa mandava investigar a coisa errada — **corrigido**

Quando a coluna F declarava o número da própria aba **e** o sistema registrava outro acordo como origem,
a recusa dizia "o PRÓPRIO acordo desta aba" quando o problema real é a divergência entre as fontes. A
ordem das checagens foi invertida. Travado por `testRecusaDizQualInvestigacaoResolve`.

### 9.6 Recusado com medição: indexar a obrigação CRIADA por `obr:<id>`

O revisor apontou que `registrarCriada` não indexa por id, ao contrário de `registrarMutada`, e chamou de
"assimetria silenciosa". **Corrigir isso introduziria um defeito:** a obrigação criada tem id na
confirmação e não tem no dry-run, então `tipoDaObrigacao` responderia `'parcela'` num modo e `null` no
outro — exatamente a divergência prévia×confirmação que o acumulador existe para impedir. A assimetria é
deliberada e agora está documentada no docblock.

### 9.7 Registrado, fora de escopo

- **`parcelasRenegociadasPorAcordoVigente` usa `$acordo->getTenant()` nullable.** Real, mas só alcança
  entidade transiente; nesta guarda o acordo sempre vem do repositório. Endurecer a query é mexer numa
  assinatura usada por outras frentes — fica anotado.
- **`docs/folha-de-ponto/` untracked com PII.** Não é desta frente; é pendência conhecida do dono.

**Depois das correções: 3.413 testes verdes, e o efeito no dado real é idêntico** (−286 obrigações,
−R$ 63.961,06) — os caminhos corrigidos não ocorrem no lote de 04/08, que é o que a guarda garante.

---

## 10. 2ª REVISÃO — achou 2 defeitos NAS CORREÇÕES da 1ª (o 11º da frente)

A 2ª revisão reproduziu o efeito no centavo (286 obrigações, R$ 63.961,06; 37 acordos com selo, 17
`ativo` + 20 `cumprido`) e recusou aprovar. Os dois bloqueantes nasceram dos meus dois commits.

### 10.1 🔴 A PRÉVIA GRAVAVA NO BANCO — e não só: estourava

A porta B escrevia `setAcordoSubstituto` + flush **sem a guarda `$usuario !== null`** que a porta A tem.
Defeito meu, do primeiro commit, que passou pela 1ª revisão inteira.

**Provado, não deduzido:** escrevi o teste antes da correção e ele não falhou por assert — **morreu com
exceção**, `A new entity was found through the relationship 'Obrigacao#acordoSubstituto'`. Na prévia o
acordo novo nunca é persistido, então o flush o arrasta junto e derruba a projeção inteira. Quem rodasse
a prévia deste caminho não veria número errado: veria o comando quebrar.

Alcançável pelo caminho `parcela-vinculada` (a obrigação já existe no banco, e o acumulador guarda a
entidade real mesmo no dry-run). Zero ocorrências no lote de 04/08 — a interseção entre "ganhou
`acordo_origem`" e "ganhou `acordo_substituto`" na mesma importação é vazia. Travado por
`testPortaBNaPreviaNaoGrava`.

### 10.2 🔴 A correção 9.2 trocou um furo de coerência por um furo de PARIDADE

Ler a vigência da entidade (`$origem->getStatus()`) parecia o oposto de supor `true`. Só que
`aplicarSobrescrita` **só grava o status na confirmação** — então a entidade responde o valor novo num
modo e o antigo no outro, e a porta da INV-I decide por essa resposta. A prévia aceitaria e a confirmação
recusaria (ou o inverso, na **reativação**, que é caminho normal deste importador). A INV-S3 caiu por
causa da correção que citava a INV-S3.

**A correção certa não era escolher entre coerência e paridade.** O status é *decidido* em ambos os modos
(`$statusFinal` de `processarAba`) e só *escrito* em um. O acumulador passou a guardar a **decisão**, e as
duas portas perguntam a ela. Coerência e paridade juntas. Travado por
`testPortaBRecusaQuandoAbaAnteriorDesativouOAcordoDeOrigem` (que ganhou os asserts de prévia×confirmação)
e por `testReativacaoDoAcordoDeOrigemNaoDivergeEntreOsModos`, o sentido inverso.

### 10.3 Observações aceitas e corrigidas

- **id interno na mensagem de recusa** (o mesmo defeito que a §5 F2 mandou eliminar, replicado por mim na
  correção 9.1): passou a imprimir o número externo, com o id só rotulado quando não há externo;
- **os números dos comentários estavam misturando dois universos**: 8 acordos têm vários sucessores, mas
  só **4** recebem o selo, e o máximo do acervo é **22**, não 12 (12 é o máximo *entre os que recebem
  selo*);
- **duas frases contraditórias na sub-linha**: acordo rompido E assumido exibiria "voltaram ao total em
  aberto" e "saíram do total em aberto" juntas — os dois `if` viraram `if/elseif`;
- **assert que procurava "Ativo" na seção inteira** passou a isolar a linha do acordo: o original só
  ficava vermelho enquanto houvesse um único acordo encerrado na tela;
- **a reconciliação 302 → 286**, que a revisão cobrou, expôs erro na MINHA medição da §2.3 — corrigido lá.

### 10.4 Recusado com medição

**Indexar por `obr:<id>` a obrigação criada** (repetido da 1ª revisão): introduziria a divergência
prévia×confirmação, porque a obrigação criada tem id em um modo e não no outro. Documentado no docblock.

**Endurecer `parcelasRenegociadasPorAcordoVigente` contra `getTenant()` nulo**: real, mas só alcança
entidade transiente, e nesta guarda o acordo sempre vem do repositório. Fica anotado.

**Depois das correções: 3.416 testes verdes**, e o efeito no dado real segue **idêntico** nas três versões
do código (−286 obrigações, −R$ 63.961,06) — os caminhos corrigidos não ocorrem no lote de 04/08, que é
exatamente o que as guardas garantem.
