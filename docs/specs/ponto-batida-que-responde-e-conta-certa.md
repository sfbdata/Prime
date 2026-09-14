# Ponto: a batida responde ao toque e a conta usa a batida certa

**Risco:** ALTO (ponto eletrônico) · **Aberta em:** 14/09/2026
**Origem:** relato do dono sobre RAIMUNDO NONATO (user 9) — *"registrou o ponto de repouso mas
quando foi ver no retorno do repouso, o registro não havia batido"* e *"as horas extras dele estão
sumindo"*. Colaborador de idade, sem experiência com tecnologia.

Investigação completa em `memory/project_ponto_raimundo_batida_e_horas_sumindo.md`.
Antecedentes: `ponto-batida-nao-se-perde-no-navegador.md` (a frente que subiu em 14/09 09:10) e
`ponto-registro-incompleto-entrada-saida.md` (a regra do dia zerado).

**As duas queixas procedem e são defeitos diferentes.** O dono autorizou as quatro frentes abaixo.

---

## Frente A — o botão precisa responder ao toque

### O defeito

`app/templates/ponto/index.html.twig:511-525` desabilita o botão em cinco situações. Botão
`disabled` **não emite evento de clique**: a pessoa toca e não acontece nada — sem mensagem, sem
vibração. A guarda textual "Selecione o tipo de registro" (`:852-855`) é inalcançável por toque.

Os cinco caminhos mudos: (1) tipo não escolhido no `<select>`; (2) fora do raio das sedes — **novo
desde o deploy de hoje**; (3) GPS negado/falhando; (4) botão congelado numa leitura antiga de GPS
(o Android suspende o `watchPosition` com a tela apagada); (5) envio em andamento.

⚠️ **O deploy de hoje trocou recusa VISÍVEL por botão MORTO no caso "fora do raio".** Antes a batida
ia ao servidor e voltava 403 com mensagem vermelha logo acima do botão; agora a tela nem deixa
tentar, e o aviso vai para `#gps-status` (topo do card), não para `#batida-aviso` (logo abaixo do
botão, onde a pessoa está olhando). A tela já se esforça para explicar — o problema é que ela
explica num lugar e emudece no outro.

Por que morde ele em especial: QNF 03 e QND 14 estão a **788 m** uma da outra, raio de 100 m cada →
~590 m de corredor onde ninguém bate ponto. Ele se desloca entre os dois escritórios no meio do dia
(bate `entrada` na QND 14 e o `repouso` na QNF 03). As 6 justificativas de esquecimento dele são de
`saida` (4) e `retorno` (2) — os momentos de trânsito.

### A regra

> **O botão nunca fica desabilitado por impedimento de regra.** Ele aceita o toque, e quem impede
> diz o motivo em `#batida-aviso` — abaixo do botão, no mesmo lugar em que a pessoa lê o resultado
> de uma batida que deu certo.

- `envioEmAndamento` **continua** desabilitando: dura ~1,5 s, o próprio botão mostra "Registrando…",
  e reabilitar abriria caminho para batida duplicada (o motivo está documentado em `:531-535`).
- Todos os outros impedimentos passam a ser verificados **no clique**, cada um com sua mensagem:
  tipo não escolhido · fora do raio (com distância e sede) · sem posição do GPS.
- **A cerca continua bloqueando** — decisão do dono em 11/09, mantida. Muda só o feedback.
- O texto da cerca em `#gps-status` **permanece**: ele informa antes do toque e aponta o caminho
  (pedir liberação do dia). O que se acrescenta é a resposta ao toque.

🪤 **Não vale "só tirar o `disabled`".** Sem a verificação no clique, o toque enviaria batida fora do
raio ao servidor e a cerca do servidor recusaria — funciona, mas gasta uma ida ao servidor para
dizer o que a tela já sabe, e falha offline. A verificação no clique é parte da regra.

## Frente B — a conta usa a ÚLTIMA batida de cada tipo

### O defeito

`app/src/Ponto/Service/CalculadoraJornada.php:148-151`:

```php
foreach ($batidas as $batida) { $mapa[$batida->getTipo()] = $batida->getDataHora(); }
```

O repositório ordena `dataHora ASC` (`RegistroPontoRepository.php:36,107,125`), então **a última
batida de cada tipo sobrescreve as anteriores**. Uma batida de tipo errado horas depois destrói o
dia inteiro.

**11/08 do Raimundo:** `entrada 07:17 · repouso 12:50 · retorno 13:50 · retorno 17:12 · saida 17:30`.
O `retorno 17:12` é tipo errado (ele quis bater a saída). A conta usou o retorno das **17:12** → a
tarde virou **18 minutos**. Dia = 351 min contra meta de 528 → **−177 min**, quando deveria ser
**+25**. Agosto dele fecha devendo 1h07 em vez de creditar 2h15.

### A regra

> `entrada` = a primeira · `saida` = a última · o intervalo é o **par adjacente**: o primeiro
> `retorno` que tenha algum `repouso` antes dele, e o **último** `repouso` antes desse retorno.
> **Sem par válido não há intervalo mensurável** e o dia cai no span inteiro (entrada → saída).

A leitura por trás: a pessoa chega uma vez, sai para o almoço uma vez, volta uma vez e vai embora
uma vez. Batida repetida em segundos é duplo clique; batida de tipo errado horas depois é engano.
Em ambos, a cronologia diz qual é a verdadeira.

🔑 **As duas pontas do intervalo saem da MESMA decisão, e é isso que torna a regra simétrica.** A
primeira versão desta spec blindava só o `retorno` ("o primeiro posterior ao repouso") e deixava o
`repouso` como "o primeiro do dia" — a revisão derrubou isso com um contra-exemplo executado:
`entrada 08:00 · repouso 09:00 (errado) · repouso 12:00 · retorno 13:00 · saída 18:00` devolvia
**360** minutos num dia de 540, apagando três horas de manhã trabalhada. O mesmo engano que a regra
vinha consertar numa ponta, ela criava na outra.

🪤 **O caso degenerado precisa de teto físico.** Quando os tipos estão trocados (bateu `retorno`
antes de qualquer `repouso`), nenhuma escolha recupera a duração do almoço. A primeira versão caía
num fallback que somava as duas pontas escolhidas em separado e chegava a **509 minutos numa janela
de 508** — contando duas vezes o trecho entre o retorno e o repouso. O span inteiro credita o
almoço nesse dia, mas está preso ao tempo entre a entrada e a saída, e é a regra que o dono já
aprovou em 31/08 para quem não bate almoço. `testDiaSemParDeIntervaloNuncaExcedeOSpan` guarda esse
teto — ⚠️ **e só ele**: o teto vale no ramo sem par, não em toda a função (ver limitação 3).

### ⚠️ Limitações conhecidas — medidas, não corrigidas

Duas situações em que a regra nova ainda erra. Ambas foram **medidas em produção** antes de
decidir não tratá-las; tratá-las exigiria adivinhar o tipo de uma batida, e chutar tipo é
exatamente o que produz número errado com cara de certo.

1. **Duas `entrada` e nenhum intervalo batido** → o dia conta o span inteiro e credita o almoço.
   🔢 **2 dias em 5,5 meses** em toda a base. Num dos dois a regra nova é menos absurda: EDLUCIA
   20/05 (`entrada 13:00 · entrada 17:48 · saida 18:00`) passa de **12** para **300** minutos — as
   batidas provam que 12 estava errado, mas **não** provam que 300 esteja certo (ela pode ter saído
   e voltado às 17:48; só ela sabe). No outro (FARLEI 17/06) a segunda entrada é quase certamente um
   `retorno` mal batido, e o almoço entra como trabalhado: **703 min = 11h43** creditados num dia.
   Corrigir a batida resolve; heurística não.
2. **Saída no meio do dia** (`saida` intermediária + `retorno` + `saida`) → a ausência vira tempo
   trabalhado. 🔢 **0 ocorrências** na base. O modelo tem quatro tipos e não representa dois
   intervalos; inventar um quinto está fora desta frente.
3. **Batidas fora de ordem** (`saida` antes da `entrada`, `entrada` depois do almoço) → o `abs()`
   de `diffMinutos` credita ALÉM da janela física, porque `$inicio->diff($fim)` devolve componentes
   positivos mesmo invertido. 🔢 **8 dias e 5.461 minutos acima da janela** desde 01/04/2026, o pior
   com **1.552 minutos** a mais. Três deles são trabalho que atravessou a meia-noite e cuja `saida`
   caiu no dia seguinte (`saida 00:00 · entrada 08:36 · …`), que o agrupamento por data civil não
   representa. ⚠️ **Pré-existente e não tocado por esta frente:** a regra antiga produzia os mesmos
   números nesses 8 dias. É a maior distorção que sobra no cálculo e merece frente própria.

### 🔴 O que a regra final ABANDONOU em relação à primeira versão desta spec

Registro obrigatório, porque é dinheiro e porque o dono já tinha conferido o caso oposto. A v1
tomava o **primeiro** repouso do dia; a final toma o **último antes do retorno**. Onde há dois
repousos antes do retorno, a final assume o almoço MENOR e credita a diferença como trabalhada.

🔢 Medido em produção: **10 dias** com repousos múltiplos, e só **1 é material**:

| dia | batidas | almoço v1 | almoço final | a mais |
|---|---|---|---|---|
| **FARLEI 19/06** | `entrada 08:32 · repouso 12:28 · repouso 13:30 · retorno 14:56 · saida 19:20` | 147 min | 85 min | **+62 min** |
| YLKA 28/08 | duplo clique de 5 min | 66 | 61 | +5 |
| RAIMUNDO 04/05 e 30/04 | duplo clique de 1-2 min | 60 | 58-59 | +3 |
| outros 6 | duplo clique de segundos | — | — | 0 |

**Os 62 minutos do FARLEI 19/06 são genuinamente ambíguos** e o sistema não tem como desempatar:
os dois repousos estão a 1h02 um do outro, então não é duplo clique. Ou ele saiu 12:28 e voltou
14:56, ou bateu 12:28 por engano e almoçou 13:30→14:56. 🔑 Pelo princípio da casa — *"quem
registrou o repouso provou que saiu, mas não por quanto tempo"* — o `repouso 12:28` é prova de que
ele saiu às 12:28, e creditar 12:28→13:30 como trabalho contradiz essa prova. Pelo mesmo princípio
da casa, porém, a v1 apagava 3h de manhã de quem bate um repouso cedo por engano.

⏳ **DECISÃO DO DONO, pendente:** aceitar os +62 min de um dia dele próprio como preço da simetria,
ou tratar repousos distantes (digamos, > 15 min entre si) como intervalos distintos. Nenhum limiar
foi escolhido aqui de propósito — limiar chutado é a classe de erro que este projeto já registrou
como "número errado com cara de certo".

### 🔢 O número que o dono viu antes de decidir (PROD, desde 01/04/2026)

Medido com a regra FINAL (par adjacente). ⚠️ A primeira versão da regra dava 62h — **o número
mudou junto com a regra**, e quem repetir a medição precisa refazê-la com a regra vigente, não
reaproveitar o total.

| | |
|---|---|
| dias com conta errada | **30**, em **7** pessoas |
| líquido **a devolver** aos colaboradores | **+3.867 min = 64,5h** |
| dias que devolvem horas | 26 |
| dias que **tiram** horas | 4 (−681 min) |

Por pessoa: JÉSSICA LORENNA **20,1h** · RAIMUNDO **17,0h** · SAMUEL **11,3h** · JÉSSICA MARTINS
**7,7h** · EDLUCIA **7,2h** · FARLEI **1,3h** · YLKA **−0,03h**.

🔑 **Os 4 dias que "tiram" horas foram conferidos um a um, e a correção está certa nos 4** — em
todos, o que o sistema perde é crédito que ele não devia ter dado:

- **FARLEI 21/08, −538 min.** `retorno 04:02 · entrada 09:32 · repouso 13:00 · saida 19:20`. O
  `retorno` das 4 da manhã é lixo, e a regra de hoje o usa como início da tarde: o dia vale
  **1.126 minutos — 18h46**. A regra nova não acha par válido, cai no span e devolve 588 (9h48).
- **EDLUCIA 21/08 (−72) e JÉSSICA LORENNA 18/05 (−60):** `repouso` batido *depois* do `retorno`, e
  o almoço inteiro entrando como trabalhado.
- **YLKA 31/08 (−11):** tipos trocados; o dia passa a valer exatamente o span físico.

⚠️ **Efeito retroativo:** folha de mês já emitido sai diferente da assinada — mesma classe de
impacto do deploy de 05/08 e da decisão de 31/08. Caminho único (`FolhaPontoBuilder::buildRows`
alimenta PDF, XLSX, tela e ficha do admin), então não há segunda fonte para divergir.

🪤 **Armadilha medida na própria investigação:** a primeira medição deu **71h** porque o SQL não
replicou o `abs()` de `diffMinutos` (`:174-178`), que em PHP devolve componentes sempre positivos
mesmo com o intervalo invertido — `$inicio->diff($fim)` ignora o `invert`. Número errado com cara de
certo. **Quem repetir a medição tem de usar `abs()` nos dois termos.**

⏳ **Fora do escopo desta frente, registrado:** esse mesmo `abs()` faz intervalo invertido virar
crédito em vez de débito. Não foi o que mordeu ninguém aqui (o pareamento cronológico remove a
inversão nos 30 casos), então **não** será mexido agora — medir antes de tratar como problema.

## Frente C — devolver o dado retroativo do Raimundo

Ação em produção, executada pelo dono. **Não muda código.**

1. **11/08** — apagar o `retorno 17:12` (batida de tipo errado; a saída real já está lançada às
   17:30). Devolve **+202 min** ao dia. 🪤 Apagar **por id**, nunca por horário.
2. **24/08** — dia sem `entrada`, zerado por registro incompleto, **sem justificativa até hoje**.
   Ele tem `repouso 12:48 · retorno 13:51 · saida 17:07`, então trabalhou. Precisa de esquecimento
   de registro aprovado, ou lançamento manual da entrada.
3. **14/09 (hoje)** — falta o `repouso`. Se ele bater retorno e saída sem ele, o dia todo é zerado.

🪤 Justificativa abonada **não conserta dia incompleto** (`FolhaPontoBuilder.php:178` bloqueia o
tratamento antes). Para os itens 2 e 3 **é preciso corrigir a batida**, não abonar o dia.

## Frente D — a tela sugere o próximo tipo

O `<select>` nasce em `Selecione...` e **volta a nascer vazio a cada recarga** — e a tela recarrega
sozinha 1,5 s depois de cada batida que dá certo. Nada sugere o próximo tipo, embora
`JornadaResolver::resolverBatidasEsperadasHoje()` (`app/src/Ponto/Service/JornadaResolver.php:111`)
já exista: hoje ele só alimenta a previsão de saída e a notificação de alerta.

É a raiz dos tipos trocados, que causam tanto a Frente B quanto o volume de esquecimentos:
**EDLUCIA 44 · YLKA 35 · RAIMUNDO 6 · SAMUEL 4** desde 01/08. A YLKA bateu `retorno 12:15` **antes**
do `repouso 12:16` em 31/08, e hoje bateu `entrada 12:10` no lugar de `repouso`.

### A regra

> O tipo sugerido vem das **batidas já registradas hoje**, na ordem `entrada → repouso → retorno →
> saida`. A primeira ainda não batida é a sugerida. Com as quatro batidas, nada é pré-selecionado.

- A sugestão é **pré-seleção, não trava**: os quatro tipos continuam disponíveis.
- 🔑 **O botão passa a dizer o que vai registrar** (`Bater Ponto — Repouso`). Pré-selecionar sem
  isso troca um erro por outro: hoje a pessoa erra por não escolher, e passaria a errar por não
  perceber o que estava escolhido. O que torna a pré-seleção segura é ela ficar visível no próprio
  botão que a pessoa aperta.
- 🪤 **A virada do dia precisa LIMPAR a sugestão, e isso não vinha de graça.** A pré-seleção é
  renderizada no servidor, só na carga. `conferirViradaDoDia` tratava a *lista* de batidas, não o
  select — a revisão pegou. Página aberta da noite para o dia, de quem esqueceu a saída: o select
  amanhecia em `saida`, o botão dizia "Bater Ponto — Saída", um toque gravava `saida` no dia novo
  e a **interjornada de 11 h do servidor passava a recusar a entrada dela pelo resto da manhã**.
  Agora a virada devolve o select ao vazio, e o toque cai na guarda que responde.
- 🪤 **O botão nasce `disabled` no HTML e é o JS que o liga.** Tirar o `disabled` do HTML perdia o
  único sinal honesto que ele dava: script não rodou. Com o script quebrado, um botão vivo que não
  responde é o defeito que esta frente existe para matar — cinza ali é a verdade.

---

## Ordem de execução

`B` (conta) → `A` + `D` (tela, mesmo arquivo, uma frente só) → `C` (dado, pelo dono).
B primeiro porque é o que já está errando o saldo de todo mundo hoje.

## Testes que provam

**Frente B** (`app/tests/Ponto/Unit/CalculadoraJornadaTest.php`):
- o 11/08 real do Raimundo (`retorno` extra às 17:12) → 553 min, não 351
- `repouso` batido DEPOIS do `retorno` (EDLUCIA 21/08) → o almoço não é creditado
- duplo clique em cada um dos quatro tipos → não muda o resultado
- `entrada` batida no meio do dia (RAIMUNDO 14/04) → a manhã não é destruída
- dia normal de 4 batidas → inalterado (proteção contra regressão)

**Frentes A e D** (`app/tests/Ponto/Unit/BatidaNaoTravaNoGpsTest.php` e
`app/tests/Ponto/Functional/CercaNaTelaDoPontoTest.php`):
- o `disabled` do botão não depende mais de tipo, posição ou cerca — só de `envioEmAndamento`
- cada impedimento escreve em `#batida-aviso`
- a cerca continua impedindo o **envio** (a regra não afrouxou)
- o tipo sugerido sai das batidas do dia e o botão exibe o tipo

🪤 **Asserte sobre o arquivo inteiro, não sobre um recorte** — o furo se repetiu 3× neste mesmo
template: assertar de um handler até o fim deixa o defeito voltar por função declarada fora do
recorte. Ver `feedback_provar_teste_reintroduzindo_defeito`.

🪤 **Suíte verde é cega para a tela.** Nenhum teste de PHPUnit prova que o botão responde ao toque
num Android — isso é smoke do dono, no celular.
