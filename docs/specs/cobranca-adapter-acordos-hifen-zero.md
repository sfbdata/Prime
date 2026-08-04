# SPEC — O hífen da fonte é ZERO, não "valor inválido"

**Risco: ALTO** (decide quanta dívida entra no saldo do devedor).
**Autorizado pelo dono em 04/08/2026:** *"pode corrigir, risco alto é deixar as parcelas de fora por
causa do hífen."*

Frente: `docs/gestao-cobrancas/HANDOFF_AUTOMATIZAR_DOWNLOADS.md`.
Spec-mãe do importador: `docs/specs/cobranca-importar-acordos-detalhados.md`.
Achado pelo **dry-run contra o dado real de 04/08**, não por leitura de código.

---

## 1. O defeito, em uma frase

A fonte escreve **`-`** onde o valor é zero. O adapter lê isso como *"valor não numérico"* e **descarta a
parcela inteira** — não a linha.

## 2. Como foi medido

`app:cobranca:importar-acordos` **sem `--confirmar`**, contra `saas_ux_dryrun` (clone descartável de
`saas_ux_pos_etapa3`), com os 6 arquivos de `planilhas atualizadas/2026-08-04-api/`. O valor descartado
foi obtido reaplicando a régua do adapter com `-` tratado como zero e somando a diferença.

| arquivo | parcelas rejeitadas | valor que NÃO seria criado |
|---|---:|---:|
| TL1 `EM_ANDAMENTO` | 71 | R$ 21.456,42 |
| TL1 `LIQUIDADO` | 74 | R$ 16.901,80 |
| TL2 `LIQUIDADO` | 27 | R$ 10.679,95 |
| **total** | **172** | **R$ 49.038,17** |

⚠️ **Dois universos, e é preciso não misturá-los** (a 2ª revisão pegou esta spec fazendo exatamente isso):

| universo | o que cobre | grupos derrubados pela régua antiga |
|---|---|---|
| **dry-run** — 3 arquivos importáveis | TL1 `EM_ANDAMENTO` + TL1 `LIQUIDADO` + TL2 `LIQUIDADO` | 72 + 74 + 27 = **173** = 172 parcelas + **1** conta (NN 74652) |
| **levantamento de colunas** — 6 arquivos | os 3 acima + os 3 `CANCELADO` e TL2 `EM_ANDAMENTO` | **174** = 172 parcelas + **2** contas (NN 74652 e **71791**) |

A 2ª conta, **NN 71791**, mora no TL1 `CANCELADO` — arquivo que **não é importado** por decisão do dono,
e por isso não aparece na tabela de valor acima. A versão anterior desta seção somava "172 + 1 = 173" e
chamava a conta de fechada, misturando o universo de 3 com o de 6; o docblock do adapter, no mesmo
commit, dizia "2 contas". **Os dois números estavam certos e os universos não estavam declarados.**

**Levantamento dos 6 arquivos** (2 carteiras × 3 situações), em TODAS as colunas de dinheiro que o
adapter lê. A primeira versão desta tabela tinha só `G` e `H` e se declarava "exaustiva" — **estava
errada**: o script separava as seções pelo formato da coluna C, e a competência de uma conta original
(`05/2026`) casa com o padrão `\d+/\d+` de "parcela p/t", então a seção de contas nunca foi varrida.
Refeito separando as seções pelos **títulos**, e conferido cruzando o total da coluna `G` (317, idêntico).

| coluna | onde | valores que a régua antiga rejeita | ocorrências |
|---|---|---|---:|
| `G` — Valor acordado | parcelas (lida) | **só `-`** (hex `2d`) | 317 |
| `E` — Valor original | contas originais (lida) | **só `-`** | **2** |
| cabeçalho — `Valor total` / `Valor final` | ficha (lido) | — | **0** |
| `H` — Valor liquidado | parcelas (**não é lida**) | só `-` | 6.825 |

**Nenhum outro símbolo aparece** — nenhum traço unicode (`–`/`—`), nenhum `N/A`, nenhum hífen com
espaço não-quebrável. A régua nova não precisa adivinhar variantes que o dado não tem.

### 2.1 A coluna `E` muda de comportamento, e isso é dinheiro

`parseCentavos` é **compartilhado** entre a coluna `E` e a `G`, então a correção muda as duas. Conta
original vira **obrigação reconstruída** (§3.2.1 da spec-mãe).

As 2 ocorrências, inspecionadas linha a linha: **NN 74652** (acordo 339, TL1 `EM_ANDAMENTO`) e **NN
71791** (acordo 277, TL1 `CANCELADO`). Ambas são `1.6 - Descontos` com `-` numa conta que **tem
principal** — `190,00` + `-` e `170,00` + `-`. O desconto zerado derrubava a conta inteira, e a dívida
original não era reconstruída.

**Por que o NN 71791 não vira dívida nem se o `CANCELADO` for importado — garantia de CÓDIGO, não
operacional.** A versão anterior desta seção justificava com *"arquivo que não é importado"*, o que
depende do operador não passar o arquivo — e a frente de automação existe para passar todos. A garantia
real: `Cancelado` mapeia para `StatusAcordo::Cancelado` (`ImportarAcordosDetalhadosUseCase::SITUACOES`),
`ehVigente()` devolve `false` para ele
([`StatusAcordo:42-48`](../../app/src/Cobranca/Enum/StatusAcordo.php#L42-L48)), e a guarda de vigência
**pula a aba inteira** — com o comentário que explica por quê: escrever ali cria dívida, porque a conta
reconstruída nasce com `acordoSubstituto` e `doCasoExigiveis` só exclui o que está substituído por
acordo **vigente**. Achado da 2ª revisão, que foi verificar no código em vez de aceitar o argumento.

Efeito medido no dry-run: *"Contas originais reconstruídas"* passa de **1075 → 1076** em TL1
`EM_ANDAMENTO`. Elas **nascem substituídas pelo acordo** e não entram no exigível: *"Contas originais
marcadas como substituídas"* e o principal que sai ficaram idênticos (0 e R$ 0,00).

## 3. Por que é zero, e não dado faltando

Três evidências independentes, todas do próprio repositório e do próprio arquivo:

1. **O mesmo adapter já reconhece a convenção.** `preenchido()`
   ([`:391-396`](../../app/src/Cobranca/Service/Importacao/AcordosDetalhadosAdapter.php#L391-L396))
   retorna `false` para `'-'` na coluna Liquidação — *"vazio ou hífen = ausente"*. A convenção foi
   aplicada numa coluna e esquecida na outra. **É inconsistência interna, não descoberta sobre a fonte.**
2. **O hífen só aparece em três rubricas, todas legitimamente zeráveis** — medido nos 6 arquivos:
   `1.4 - Juros` (146×), `1.5 - Multas` (145×) e `1.6 - Descontos` (26×). Somam os mesmos 317 do
   levantamento por coluna. Nenhuma linha de principal (`1.1 - Taxa de condomínio`) traz hífen.
3. **O próprio arquivo denuncia a perda.** O importador já emite *"Leitura NÃO fecha com o cabeçalho da
   própria planilha"*, comparando a soma das parcelas com o `Valor final acordado` do cabeçalho. Vários
   acordos fecham em **exatamente metade** (acordo 58: R$ 65,00 lidos × R$ 130,00 declarados; acordo 104:
   R$ 173,18 × R$ 346,34). O alarme certo já existia e foi lido como ruído da contábil.

## 4. A regra

No `parseCentavos` do `AcordosDetalhadosAdapter`, **depois** da limpeza de espaços e **antes** do teste
numérico: um valor que sobrou como exatamente `-` vale **0 centavos**.

| entrada | hoje | depois | por quê |
|---|---|---|---|
| `'-'` | ❌ `false` → derruba a parcela | **`0`** | é o zero da fonte |
| `' - '` / `'-'` com NBSP | ❌ `false` | **`0`** | a limpeza já normaliza para `-` |
| `'-\u{00A0}3,04'` | `-304` | **`-304`** (inalterado) | **desconto negativo — não pode virar zero** |
| `''` / `null` | `0` | `0` (inalterado) | já era |
| `'a combinar'` | `false` → rejeita | **`false`** (inalterado) | texto de verdade continua rejeitado |

⚠️ **A armadilha desta correção:** `-` isolado é zero, mas `-\u{00A0}3,04` é **desconto**. O docblock do
adapter já registra que perder o desconto custa a parcela inteira (R$ 400,68 no caso medido). Qualquer
correção que remova hífen antes do teste numérico troca um defeito de dinheiro por outro. A régua tem de
casar o token **inteiro**, nunca um `str_replace`.

## 5. O que NÃO muda

- **Parcela cujo total continuar `<= 0` segue rejeitada** (`:342-344`). Uma parcela que só tem linhas de
  hífen soma zero e **não vira obrigação** — criar dívida zerada seria inventar passivo, mesma regra que
  `testContaOriginalComValorZeroEhRejeitada` já protege nas contas originais.
- **Conta original cujo total continuar `<= 0` segue rejeitada** (`:290`), pela mesma razão.
- **Texto não numérico continua rejeitando**, e o teste que garante isso (`testValorNaoNumericoEhRejeitado`,
  que usa `'a combinar'`) **não muda uma linha**.
- Nada em competência, vencimento, classe ou p/t.
- **A coluna `H` (Valor liquidado) continua não sendo lida.** Tem 6.825 hífens e nenhum efeito hoje;
  passar a lê-la é outra frente.

### 5.1 O cabeçalho: comportamento muda, mas o dado não exercita

`valorDoRotulo` (`:385`) usa o mesmo `parseCentavos` para `Valor total das contas originais` e `Valor
final acordado`. Com hífen, esses rótulos passariam de `null` (não comparável) para `0`, e as guardas
`!== null` do UseCase (`:211`, `:220`) emitiriam *"o cabeçalho diz R$ 0,00"* onde antes havia silêncio.

**Medido: 0 ocorrências de hífen no cabeçalho dos 6 arquivos.** O ramo não é exercitado. Registrado por
honestidade — a versão anterior desta spec dizia *"nada nas seções de cabeçalho"*, o que era falso sobre
o **código** ainda que verdadeiro sobre o **dado**. Se um dia acontecer, o efeito é ruído no relatório,
não dinheiro: o valor lançado nunca é sobrescrito (§4 da spec-mãe).

✅ **Mesmo não sendo exercitado, o caso é travado por teste** (`testHifenNoCabecalhoValeZero`). A 2ª
revisão apontou a assimetria: justificar ausência de teste com *"0 ocorrências no dado"* é o mesmo
argumento que a §5.2 classifica como frágil — *snapshot de fonte externa, não invariante*. A régua é
permanente; o teste também tem de ser.

### 5.2 ⚠️ O modo de falha que a correção TROCA — hífen numa linha de principal

Se um dia a fonte trouxer `-` numa linha de `1.1 - Taxa de condomínio`, o comportamento muda de
**rejeitar a parcela** (ruidoso, visível na contagem de rejeições) para **criá-la com valor menor**
(silencioso). Exemplo: principal `-` + honorário `25,50` nasce valendo R$ 25,50.

Isso é uma consequência aceita, não um descuido, por três razões:

1. **Não ocorre no dado.** Medido: as 319 ocorrências estão todas em `1.4`, `1.5` e `1.6` — nenhuma em
   linha de principal. É snapshot de fonte externa, não invariante; por isso está registrado aqui.
2. **Tratar hífen como zero só em algumas rubricas** seria inventar regra que a fonte não declara, e
   voltaria ao problema de origem: um mapa incompleto que esconde dado.
3. **A rede que sobra ficou mais confiável, não menos.** A conferência *"Leitura NÃO fecha com o
   cabeçalho"* caiu de 33 avisos para 2 em TL1 `EM_ANDAMENTO` (§7.1) — antes ela era ruído que ninguém
   lia justamente porque o hífen a disparava em massa. Agora um aviso desses significa alguma coisa.

## 6. Escopo: só o adapter de Acordos

`TopLifeInadimplenciaAdapter` ([`:307-320`](../../app/src/Cobranca/Service/Importacao/TopLifeInadimplenciaAdapter.php#L307-L320))
e `TopLifeReceitasAdapter` ([`:341-354`](../../app/src/Cobranca/Service/Importacao/TopLifeReceitasAdapter.php#L341-L354))
têm `parseCentavos` com a mesma fragilidade — e são **código em produção**.

**Não entram nesta correção**, por fato medido: o dry-run dos dois contra os arquivos reais de 04/08 deu
**0 rejeições por "não numérico"** (Inadimplência: 86 rejeições, todas por *"sem principal"* ou *"sem
NN"*; Receitas: 0 rejeições em 1.203 recebimentos). Só o relatório de Acordos tem coluna com Juros/Multas
zerados escritos como `-`.

Fica **registrado como dívida latente**, não como pendência aberta
(`feedback_nao_inflar_a_lista_de_problemas`): a régua deles é ainda mais estreita que a do Acordos — não
limpa espaço não-quebrável nem separador de milhar, dois casos que o adapter de Acordos só aprendeu
depois de apanhar do dado real. Se um dia esses relatórios trouxerem hífen, o defeito é o mesmo.

## 7. Testes exigidos

Em `app/tests/Cobranca/Unit/AcordosDetalhadosAdapterTest.php` (**arquivo existente** — conferido, não
suposto; a spec anterior desta frente errou exatamente nisso, §5.5).

**Cada teste é provado reintroduzindo o defeito, conferindo QUAL assert fica vermelho.** Nesta frente já
apareceram 5 asserts que não podiam falhar. O cenário aqui tem **defesas em série** (`$total <= 0` barra
depois de `parseCentavos`), então o caso do hífen puro é isolado num teste mínimo.

| # | Caso | Assert que tem de ficar vermelho ao reintroduzir o defeito |
|---|---|---|
| 1 | parcela com linhas `170,00` + `-` (Juros) + `-` (Multas) | `rejeitadas` está **vazio** e o valor da parcela é **17000** — hoje a parcela inteira some |
| 2 | o `-` **não** vira dívida a mais | a parcela vale `17000`, não `17000` + qualquer coisa vinda do hífen |
| 3 | desconto negativo `-\u{00A0}3,04` continua `-304` | o teste existente `testDescontoNegativoComEspacoNaoQuebravel` segue verde **sem alteração** |
| 4 | `'a combinar'` continua rejeitando | `testValorNaoNumericoEhRejeitado` segue verde **sem alteração** |
| 5 | parcela **só** de hífens (total zero) | continua rejeitada, com motivo de *total não positivo* — não vira obrigação de R$ 0,00 |
| 6 | **conta original** com principal + `-` no desconto (§2.1) | a conta **não** é rejeitada e vale o principal — é o caso real dos NN 74652 e 71791 |
| 7 | **conta original só de hífens**, ao lado de uma com `0,00` explícito | os dois motivos de recusa são **idênticos** — é esse par que prende o teste à régua, e não o texto da mensagem |
| 8 | **hífen no cabeçalho** (`Valor total` / `Valor final`) | os rótulos viram `0`, não `null`, e a conta da linha não é afetada |

⚠️ Os casos 6 e 7 **faltavam na primeira versão desta spec**, que só olhou a coluna das parcelas — achado
da 1ª revisão, e o motivo de a §2 ter sido remedida. O **par de controle do caso 7** e o **caso 8** vieram
da 2ª revisão: sem eles, o teste 7 dependia só da string `'não positivo'` (reescrever a mensagem o
deixaria verde com o defeito de volta) e o terceiro chamador de `parseCentavos` ficava sem prova.

## 7.1 ⚠️ O efeito MEDIDO depois da correção — e a consequência que eu errei

**A primeira leitura deste achado disse que R$ 49.038,17 "não entrariam no saldo". Isso estava errado**
como consequência, e o dry-run pós-correção mostrou. O defeito é real; o efeito no saldo, neste estado
do banco, é **zero**. Registrado aqui porque é o padrão que
`feedback_medir_antes_de_aceitar_achado_de_revisao` descreve — acertar o defeito e errar a consequência.

**Rejeições a zero nos três arquivos** (72→0, 74→0, 27→0). Onde as 172 parcelas foram parar:

| destino | parcelas | valor | mexe no saldo? |
|---|---:|---:|---|
| já existiam no sistema (a Receitas criou) | 64 | \* | **não** |
| pagas que não existem e que o importe **recusa criar** | 16 | \* | **não** (recusa de spec: criar cobraria de novo) |
| **subtotal em aba processada** | **80** | **R$ 27.624,45** | **não** |
| em **aba ignorada** (o acordo não existe no sistema) | 92 | R$ 21.413,72 | **não hoje** |
| **total** | **172** | **R$ 49.038,17** | |

\* O valor foi medido por **aba processada × ignorada**, que é o corte que decide o efeito; a quebra
entre "já existiam" e "recusa criar" foi contada em parcelas, não em reais. Os R$ 27.624,45 do subtotal
não entram no saldo por nenhum dos dois caminhos. **Mais 2 contas originais** (R$ 190,00 e R$ 170,00,
§2.1) entram como reconstruídas, que nascem substituídas e também não mexem no exigível.

**`Parcelas futuras criadas` e `principal que sai` ficaram IDÊNTICOS** nos três arquivos (525 / R$
136.006,22 · 0 · 0 e R$ 793,05 · R$ 340,00). Nenhum centavo entra ou sai por causa desta correção.

**Então por que ela vale?** Três efeitos medidos, nenhum deles no saldo de hoje:

1. **R$ 21.413,72 estão em abas ignoradas** — parcelas de acordos que o sistema ainda não tem. Viram
   dívida real assim que esses acordos existirem, e a frente de automação existe justamente para
   trazê-los (handoff §6.1). O defeito estava esperando o dado chegar.
2. **A ordem de produção protege por acidente, não por desenho.** O saldo não se move porque a Receitas
   rodou **antes** e já criou as parcelas. Invertida a ordem — ou num tenant sem Receitas — as 64 seriam
   criadas pelo Acordos.
3. **33 divergências de valor saíram do escuro** (18→24, 20→25, 0→22). Parcela rejeitada não é comparada
   com o sistema; agora é. Conferida uma no dado real: NN 60626 = 170,00 + 170,00 + `-` (Desconto) +
   25,50 = **R$ 365,50**, exatamente o que o importador reporta como "planilha", contra R$ 345,50 no
   sistema. **A leitura nova está certa e a divergência é real** — e o valor lançado continua não sendo
   alterado (§4 da spec-mãe).

E o sintoma do §3.3 encolheu como previsto: *"Leitura NÃO fecha com o cabeçalho"* caiu de 33→2 e 20→10.

## 8. Como a entrega é conferida

1. suíte completa verde no container — **3219/3219 antes da frente, 3224/3224 depois** (5 testes novos:
   2 de parcela, 2 de conta original e 1 de cabeçalho; a conta de controle `0,00` do caso 7 vive dentro
   do próprio teste, não é um método à parte);
2. **dry-run repetido** contra os **3 arquivos importáveis**: as rejeições por *"não numérico"* vão a
   **0** (72→0, 74→0, 27→0) — medido, não presumido.
   ⚠️ O TL1 `CANCELADO`, onde mora a 2ª conta da §2.1 (NN 71791), **fica fora desta conferência** por
   decisão do dono — ele não é importado, e a §5.4 da spec de sobrescrita de situação trava junto com
   ele. O que garante essa conta não é a conferência, é a guarda de vigência (§2.1);
   ⚠️ **O critério NÃO é "as 172 parcelas passam a ser criadas"** — a primeira versão desta spec pedia
   isso e era impossível de satisfazer: nenhuma parcela é criada pela correção (§7.1). O critério certo
   é o par **`Parcelas futuras criadas` e `principal que sai` IDÊNTICOS antes e depois**, com
   `Parcelas que já existiam` subindo 64 e `Contas originais reconstruídas` subindo 1;
3. o bloco *"Leitura NÃO fecha com o cabeçalho"* **encolhe** (33→2 e 20→10) — é o mesmo defeito visto
   pelo outro lado;
4. `/review` (`feature-review-agent`, read-only) contra esta spec;
5. correção do que a revisão apontar;
6. **segunda passada de `/review`** — exigência de risco ALTO. Nesta frente, **cinco revisões seguidas
   acharam defeito nas correções da revisão anterior**. A segunda passada não é formalidade.
