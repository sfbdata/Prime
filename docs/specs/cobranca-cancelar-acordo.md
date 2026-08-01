# Cancelar acordo faz o acordo SUMIR para o gestor; romper mantém o histórico

**Risco:** ALTO (mexe em dinheiro — exclusão de obrigações e restauração de saldo)
**Data:** 2026-08-01 · **Origem:** achado do dono cancelando o acordo #1 no dev (01/08)

---

## 1. O problema

Ao cancelar o acordo #1 (unidade QUADRA 11 CHACARA 02/11, caso 193, carteira TOP LIFE II), o dono
observou dois defeitos:

1. as 4 taxas originais voltaram ao saldo **com os juros parados**;
2. as 30 parcelas do acordo cancelado ficaram **misturadas** com as originais na lista de dívida.

Expectativa, nas palavras dele: *"quando cancela um acordo, as obrigações devem voltar como estavam
antes de criar acordo, sem congelar nada e nem misturar com as parcelas do acordo cancelado"*. E,
depois: *"cancelado perde tudo do acordo, não tem nem como abrir um acordo cancelado; no máximo
aparecer no histórico algo como 'acordo x de 30 parcelas foi cancelado'"*.

## 2. Diagnóstico medido (01/08, banco `saas_ux` e produção)

### 2.1 Os juros parados são resíduo de dado

`CriarAcordoUseCase:120-139` materializa os encargos da obrigação substituída **sem congelar** — de
propósito, e o docblock dele diz isso: *"se o acordo for rompido, ela volta ao exigível e recomeça a
crescer ao vivo (sem precisar descongelar, pois não foi congelada — só materializada)"*.

Quem congelou as 4 foi a migration `Version20260719140000` (executada em 21/07 21:04:26). O critério
dela — `encargos_congelados_em IS NULL AND (juros + multa + correcao) > 0` — pegou exatamente essas 4
porque, naquele instante, eram as **únicas** obrigações do banco com encargo ≠ 0, justamente por causa
da materialização do acordo três dias antes.

**Ponto que estava em aberto e ficou resolvido:** `encargos_atualizados_em = 01/08 11:47:33` **não**
contradiz o diagnóstico. Quem escreveu foi o importador de inadimplência, não a hidratação:

- `EncargosVivos::hidratar` (`Service/EncargosVivos.php:88-90`) faz `continue` na congelada **antes**
  de `definirEncargos` — é estruturalmente incapaz de escrever aquele carimbo numa congelada;
- exatamente **527** obrigações compartilham o timestamp `01/08 11:47:33` — o tamanho da TOP LIFE II;
- `ImportarRelatorioCarteiraUseCase::materializarEncargosImportados` (`:255-265`) chama
  `definirEncargos` **sem checar** `encargosCongelados()`.

Estado medido: **4 congeladas vivas · 0 liquidadas** no dev **e em produção** (3380 e 3482 obrigações
com encargo > 0 estão livres). O cenário "milhares congeladas em prod" foi medido e **descartado**.

Os valores congelados hoje estão **certos** (juros de 4,70% em 141 dias a 1%/mês bate com a fórmula):
o prejuízo é **futuro** — as 4 param no tempo enquanto a 5ª taxa do mesmo caso continua crescendo.

### 2.2 A mistura na tela

`MontarDetalheCasoUseCase::agruparPorAcordo:349-410` tem três testes; os dois primeiros exigem acordo
**vigente**. Com o acordo cancelado ambos dão falso para tudo e o teste (3) joga as 35 obrigações do
caso na mesma lista solta (`obrigacoesAvulsas`), renderizada numa tabela plana ordenada por
vencimento (`_divida.html.twig:155`): **30 parcelas mortas + 5 dívidas reais**.

O saldo está **correto**: exigíveis = 5 · principal R$ 850,00 · exigível R$ 889,61 (replicado em SQL
contra `doCasoExigiveis`). É defeito de exibição, não de cálculo.

## 3. Decisões (do dono, 01/08)

| # | Decisão |
|---|---|
| D1 | **Cancelar faz o acordo SUMIR para o gestor**: fora de todas as listas, e a rota dá 404. |
| D2 | **Romper NÃO some.** Romper é "o devedor descumpriu" — aconteceu, e continua em "Acordos encerrados". |
| D3 | O rastro do cancelamento é **uma linha no histórico**, autocontida. |
| D4 | **Acordo com parcela paga não se cancela** — desfaz-se o pagamento primeiro. |
| D5 | Nas duas operações, as originais voltam a **crescer**: descongeladas. |
| D6 | **O importe é a verdade absoluta.** Em carteira importada, o ESTADO do acordo pertence à planilha: se ela traz um acordo que o sistema tem como rompido ou cancelado, o acordo volta a ativo. Ver §3.2 — especificada, **não implementada nesta frente**. |
| D7 | **Nenhuma ação de dinheiro pode ser irreversível** — inclusive para escritórios que não importam planilha. |

### 3.0 ⚠️ Por que "sumir" e não "apagar" — a correção que a revisão forçou

A primeira versão desta spec dizia **apagar** (acordo, parcelas e vínculos). A revisão mostrou que isso
criava um defeito de dinheiro, e a medição confirmou:

`ImportarRelatorioCarteiraUseCase:293-308` procura o acordo por número externo
(`AcordoRepository::findOnePorNumeroExternoNaCarteira`, que **não filtra status**) e, não o achando,
**cria um novo já ATIVO** e recria as parcelas. Com a linha apagada, a próxima importação ressuscitava
o acordo enquanto as originais seguiam exigíveis: **a mesma dívida contada duas vezes**. Os 8 acordos
do dev têm `numero_externo` — acordo importado é o caso dominante, não a exceção.

E um acordo que voltasse a valer sem saber quais originais substituiu não teria como tirá-las do
saldo — essa informação morre junto com a linha apagada. É também o que D7 proíbe: apagar é a escolha
irreversível.

Daí o desenho atual: **a linha do acordo e as parcelas permanecem no banco; o que muda é a
visibilidade.** Para o gestor o resultado observável é idêntico ao que ele pediu. E o vínculo
`acordoSubstituto` é preservado justamente para que, se o acordo voltar, ele volte **certo**.

### 3.2 D6 — "o importe é a verdade absoluta" (especificada, NÃO implementada aqui)

**Palavras do dono (01/08, ao fechar a frente):**

> *"No caso de importação, os dados da planilha vão sobrescrever acordo rompido. O acordo do sistema
> tem que estar alinhado com o da planilha. O importe é sempre a verdade. E nesse caso também vai
> constar no histórico que houve um acordo rompido — não precisa nem dizer que o importe mudou esse
> estado, pois já é implícito que o importe é a verdade absoluta."*

Semântica, então: em carteira importada o **estado do acordo é da planilha**, não do sistema. Rompido
ou cancelado, se a planilha trouxer o acordo ele **volta a ativo**. Sem aviso — o histórico já guarda
que houve rompimento/cancelamento, e "o importe manda" é regra do produto, não exceção a sinalizar.

**Ela chegou a ser implementada nesta frente e foi removida** — não por estar errada, mas porque a
revisão achou um furo de dinheiro que ela ainda não resolvia (ver abaixo). O importador voltou byte a
byte ao original. Reimplementar é **frente própria**, com spec e revisão.

#### ⚠️ O furo que a reimplementação TEM de resolver

Medido no dado real (`saas_ux`, acordo #1, flip de status por SQL): exigíveis **5 → 31**, bruto
**88961 → 88445**.

Cadeia: acordo desfeito → as originais voltam ao saldo e a tela **oferece "Receber"** nelas → o gestor
recebe R$ X numa original → a planilha traz o acordo → reativação → a original sai do exigível → e
`CalculadoraSaldo` **só abate alocação de obrigação EXIGÍVEL**, então o R$ X **para de abater**.

Consequência: **o devedor passa a ser cobrado por dinheiro que já pagou.** É o erro mais grave possível
neste domínio — pior que subcobrança —, e acontece em silêncio.

"O importe é a verdade" resolve *quem manda no estado do acordo*. Para o dinheiro que já entrou, o
dono acrescentou a peça que faltava (01/08):

> *"é o estado ATUAL, porque temos que implementar o importe da planilha de receita também."*

🔑 **Isso dispensa o rateio adivinhado.** A *Receitas detalhadas por unidade/cliente* traz **NN, data e
valor recebido** — ela identifica o pagamento **por obrigação**, não por regra. Com ela importada, a
pergunta "para onde vai o dinheiro quando o acordo volta" deixa de ser uma decisão do sistema e passa a
ser um dado da contabilidade: os recebimentos das PARCELAS chegam com o NN das parcelas.

Consequência para o planejamento: **D6 não deve ser escrita antes do importe de Receitas**, ou será
escrita duas vezes — uma adivinhando (FIFO) e outra lendo a verdade. A ordem que decorre disso está em
§7.

Enquanto Receitas não existir, um pagamento lançado à mão numa original é um artefato só do sistema, que
a contabilidade não conhece — e é justamente por isso que ele precisa ser **apagável** (D7).

### 3.1 Ressalva registrada sobre D4

**Não existe desfazer pagamento no sistema.** Só há `cobranca_pagamento_registrar` e
`cobranca_pagamento_corrigir`; a correção exige `valorPago` **positivo**
(`CorrigirPagamentoInput.php:29`) e o docblock diz *"NÃO há estorno no MVP"*. Não há rota de exclusão.

Logo D4 é hoje um **beco sem saída** se alguém lançar um pagamento por engano. Decisão consciente:
implementa-se o bloqueio (é o que a contabilidade manda) e o **estorno fica como frente separada**.
Medido: **zero parcelas de acordo têm pagamento** no dev e em produção — ninguém trava agora.

## 4. Comportamento especificado

### 4.1 Cancelar (`CancelarAcordoUseCase`)

Ordem obrigatória:

1. Guardas existentes (tenant, `estaAtivo()`, parcelas renegociadas por acordo vigente).
2. Carregar parcelas e substituídas **por QUERY** (INV-C1).
3. **Guarda nova (D4):** se qualquer parcela tiver alocação de pagamento →
   `AcordoComParcelaPagaException`. Nada é escrito.
4. `status = Cancelado` + motivo.
5. **Descongelar as originais (D5)**, pulando as liquidadas.
6. Registrar o evento (D3) e fechar a transação com o flush único.

Nada é apagado. Nenhum vínculo é desfeito.

**INV-C1 — os dois conjuntos vêm por QUERY, nunca das coleções inversas do acordo.**
`Acordo::getParcelas()` e `getObrigacoesSubstituidas()` são o lado INVERSO; quando o acordo foi criado
na mesma unidade de trabalho (`CriarAcordoUseCase` só escreve o lado dono), elas nascem **vazias**. O
laço de descongelamento então não faria nada, **em silêncio**, e a guarda de pagamento receberia lista
vazia — deixando passar um acordo com dinheiro recebido. Em produção o cancelamento é outro request e
a coleção carrega do banco, o que faz o defeito passar despercebido justamente onde há teste. Foi um
teste funcional que o expôs. Daí `ObrigacaoRepository::parcelasDoAcordo` e `substituidasPorAcordo`.

**INV-C2 — a guarda da liquidada é obrigatória.** `liquidar()` é o único ponto do código que congela
e `reabrir()` é quem desfaz; descongelar uma liquidada poria juros a correr sobre dívida paga.

**INV-C3 — descongelar basta.** Não é preciso desfazer a materialização: descongelada, a próxima
leitura hidrata do zero (vencimento → hoje × taxa) e sobrescreve o snapshot da data do acordo.

**INV-C4 — o evento é a única coisa que sobra na tela.** `cobranca_evento_historico` referencia o
**caso**, não o acordo. Como o acordo some das listas e a rota dá 404, a linha precisa ser AUTOCONTIDA:
número, quantidade de parcelas e valor ficam na descrição e no payload.

**INV-C5 — o vínculo `acordoSubstituto` é PRESERVADO.** Contra-intuitivo, e é o que impede dívida em
dobro (§3.0). A original já volta ao saldo sem ele — `doCasoExigiveis` inclui a substituída por acordo
não vigente — e a tela já a trata como dívida normal (`substituidaPorAcordo` é vigente-aware). Apagá-lo
não traria benefício nenhum e destruiria a única informação de quais originais aquele acordo substituiu.

### 4.2 Romper (`RomperAcordoUseCase`)

Idêntico, menos a guarda D4 e o sumiço da tela: romper preserva as parcelas, logo preserva o pagamento;
e o acordo continua acessível em "Acordos encerrados". Ganha o passo 5 (descongelar), porque a
expectativa "volta a contar" vale igual.

### 4.3 Tela

1. **Acordo CANCELADO some** — filtrado em `MontarDetalheCasoUseCase` antes de virar `AcordoOutput`,
   logo fora dos grupos e de "Acordos encerrados"; e `cobranca_acordo_show` responde **404**.
2. **Parcela de acordo desfeito** (rompido ou cancelado) sai da seção de dívida (`agruparPorAcordo`).
   A do rompido segue acessível abrindo o acordo; a do cancelado não, porque o acordo sumiu.

Efeitos derivados, todos desejados:
- o rodapé da aba Honorários passa a somar só o que a aba lista — a invariante *"rodapé = soma das
  linhas visíveis"* é **preservada**, com conjunto menor;
- a guarda de `MontarDetalheCasoUseCase` que exclui `parcelaDeAcordoDesfeito` dos cards fica
  inalcançável e **permanece** como defesa em profundidade (é caminho de dinheiro).

## 5. Dados existentes

Migration `Version20260801150000` (escrita à mão — é dado, não schema), com **um único comando**:

> desfaz o congelamento acidental — `encargos_congelados_em = NULL WHERE encargos_congelados_em IS NOT
> NULL AND liquidada_em IS NULL`. Alcance medido em 01/08, ANTES de rodar: **4 linhas no dev, 4 em prod,
> 0 liquidadas nos dois**.

⚠️ **Quem for reconferir no dev vai medir ZERO**, e não é a spec mentindo: a migration já foi executada
no `saas_ux` em 01/08 14:35:29 (`doctrine_migration_versions`) durante o desenvolvimento. Em produção
ela ainda não rodou — o número de lá continua valendo até o deploy.

**Ela NÃO apaga acordo cancelado nem parcelas** — uma versão anterior apagava, pelo motivo derrubado em
§3.0. Como o cancelamento agora só esconde, não há dado velho a converter: o acordo #1 do dev já está
`cancelado` e passa a se comportar pela regra nova sem tocar em nenhuma linha.

`down()` é **no-op documentado**: recongelar pararia juros de obrigação viva (dano ativo). Mesma
"limitação honesta" que a `Version20260719140000` assume no próprio docblock.

## 6. Como se prova

Todo teste é validado **reintroduzindo o defeito que ele guarda** — e a própria prova precisa valer:
o primeiro assert de "as originais voltam vivas" passava com o defeito reintroduzido, porque no
cenário elas nunca estavam congeladas. Só virou prova depois de congelá-las antes de cancelar,
reproduzindo o estado real que a migration de 21/07 deixou.

- **o saldo do caso volta a ser exatamente o de antes do acordo** — nem a mais (parcela que sobrevive
  solta) nem a menos (original que não volta). É o teste decisivo, e foi ele que expôs INV-C1;
- cancelar **não apaga** acordo nem parcelas, e o vínculo `acordoSubstituto` sobrevive (INV-C5);
- as originais voltam **descongeladas**; a **liquidada** continua congelada (INV-C2);
- parcela com pagamento → recusa, e **nada** é escrito;
- romper não apaga e descongela;
- a parcela de acordo desfeito **não aparece** na seção de dívida (inverter o teste que afirmava o
  contrário, não afrouxá-lo);
- o acordo cancelado some da tela do objeto e `cobranca_acordo_show` dá **404** — com um assert
  positivo antes do cancelamento, para o assert de ausência não passar por vazio.

**Viés de confirmação = a contabilidade.** O estado final do caso 193 tem de bater com o relatório de
inadimplência atualizado (as 4 taxas de R$ 170,00 abertas + a de 07/2026), não só com a suíte.

## 7. Fora de escopo

- **Excluir recebimento** — é o que destrava D4 (hoje a recusa é um beco sem saída) e o que cumpre D7.
  **Próxima frente**, já aprovada pelo dono. A lista de recebimentos já mostra TODOS os pagamentos do
  caso, inclusive os de parcela de acordo (`PagamentoRepository::doCaso`); falta a ação de apagar, com
  registro no histórico e reconciliação da obrigação.
- **Reativação por importação (D6)** — especificada em §3.2. Frente própria, e **depois** do importe de
  Receitas: é ele que responde para onde vai o dinheiro quando o acordo volta, sem o sistema adivinhar.

### Ordem das frentes que saem daqui

1. **Excluir recebimento** (D7) — o desfazer; destrava a recusa de cancelar acordo com parcela paga.
2. **Importar Receitas detalhadas** — o 4º relatório; dá conferência externa aos pagamentos e passa a
   dizer, por NN, o que foi pago.
3. **D6, reativação por importação** — só depois de (2), senão o rateio é chute.
- ⚠️ **Cancelar acordo SEM obrigações substituídas** (a forma que o importador cria: *"o import não
  substitui nada, só materializa a parcela"*) tira as parcelas do saldo E da tela, e não há original
  para voltar no lugar — o caso pode ficar visualmente sem dívida. Medido em 01/08: os 9 acordos do dev
  têm de 1 a 6 substituídas cada, **ninguém está nessa forma hoje**. Não há guarda nem aviso; o
  histórico registra quantidade e valor das parcelas, mas o gestor não é avisado na hora. Fica como
  risco conhecido — a saída natural é a mesma frente de "excluir recebimento"/desfazer (D7).
- ⚠️ **Parcela reimportada de acordo ROMPIDO** cai no mesmo ponto cego: fora do exigível (comportamento
  pré-existente) e, desde esta frente, fora da seção de dívida. Continua acessível abrindo o acordo em
  "Acordos encerrados" — menos grave que o caso acima, mas é dívida fora do saldo sem sinalização.
- **Importar "Receitas detalhadas por unidade/cliente"** — o 4º relatório da contábil, que confirma
  pagamentos (NN, data, valor). Enquanto não existir, todo recebimento é digitado à mão e sem
  conferência externa.
- `ImportarRelatorioCarteiraUseCase:255-265` sobrescreve encargos de obrigação congelada sem checar —
  o congelamento não protege contra o importador, ao contrário do que a migration de 21/07 supôs. O
  comentário de `:237-238` ainda afirma "RE-CONGELA na data nova", coisa que o código não faz desde a
  decisão D6. Contradição real em arquivo de dinheiro — outra frente.
