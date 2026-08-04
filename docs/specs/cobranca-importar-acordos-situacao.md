# SPEC — O importe de Acordos detalhados sobrescreve a situação do acordo

**Risco: ALTO** (mexe em status de acordo, e status de acordo decide o saldo exigível).
**Decisão do dono, 04/08/2026:** *"o importe sempre sobrescreve o sistema"* — reafirmada nesta sessão,
inclusive para o caso em que o sistema tem um acordo **não vigente** por decisão manual de alguém.

⚠️ **Escopo: a regra vale onde há importe, e hoje isso é o tenant 1.** O dono precisou o alcance em
04/08: *"apenas o tenant 1 se baseia 100% no importe, os outros podem trabalhar puramente pelo sistema."*
Nenhum código novo decorre disso — o importador roda por `--tenant-id`, e só o tenant 1 tem as carteiras
da contábil —, mas isso **preserva** o segundo argumento que o código original usava para não escrever o
status (*"o status é uma decisão MANUAL do escritório"*): ele continua verdadeiro para quem não importa,
e essas contas nunca passam por aqui.

Frente: `docs/gestao-cobrancas/HANDOFF_AUTOMATIZAR_DOWNLOADS.md` §6, item 2.
Spec-mãe do importador: `docs/specs/cobranca-importar-acordos-detalhados.md`.

---

## 1. Por que isto existe agora

O `ImportarAcordosDetalhadosUseCase` **nunca escreve o status do acordo**. Isso foi deliberado e está
justificado no próprio código ([`:598-631`](../../app/src/Cobranca/UseCase/ImportarAcordosDetalhadosUseCase.php#L598-L631)),
com dois argumentos que **eram verdadeiros quando foram escritos**:

1. *"A única situação que a fonte traz hoje (`Em andamento`) mapeia para `Ativo`, que já é o status de
   todo acordo nascido da importação — escrever seria no-op."*
2. *"O status do sistema é uma decisão MANUAL do escritório."*

O primeiro **caiu com a medição de 04/08**. O export manual saía com `Situação: Em andamento` e escondia
o resto. Baixando pela API, uma emissão por situação:

| carteira | Em andamento | Liquidado | Cancelado | total |
|---|---:|---:|---:|---:|
| TOP LIFE 1 | 66 | **259** | 99 | **424** |
| TOP LIFE 2 | 8 | 26 | 5 | **39** |

**`Liquidado` é a maioria do dado**, não uma exceção. Com o mapa atual ele cai em *"situação não
reconhecida"* e vira uma linha de aviso — 285 linhas de aviso, que ninguém lê.

O segundo argumento foi **decidido contra** pelo dono. O importe manda.

Precedente na casa: o `ImportarReceitasUseCase` **já faz isto** — decisão D6, *"o importe é a verdade
absoluta"*: reativa acordo não vigente (`:390-418`) e marca cumprido (`:239-244`). Os dois importadores
rodam em sequência sobre o mesmo dado e hoje **discordam sobre quem manda no status**. Esta spec alinha
o de Acordos ao de Receitas.

## 2. Escopo

**Entra:** o `ImportarAcordosDetalhadosUseCase` passa a aplicar a situação da planilha ao acordo do
sistema, com registro no histórico e medição do efeito no saldo.

**Não entra:** criação de acordo (continua §3.1 — quem cria é a inadimplência), baixa de pagamento
(continua §5), sobrescrita de valor lançado (continua §4), o validador da linha `Filtros:` do rodapé e
o comando agendado (frente seguinte).

## 3. O mapa de situações

Strings **medidas nos arquivos reais** de 04/08 (`xl/worksheets/sheet1.xml`, célula `Situação: …`):
`Em andamento` · `Liquidado` · `Cancelado`. Comparação em minúsculas e sem acento, como hoje.

| `Situação:` da planilha | `StatusAcordo` | vigente? |
|---|---|---|
| `Em andamento` | `Ativo` | sim |
| `Liquidado` | **`Cumprido`** | sim |
| `Cancelado` | **`Cancelado`** | **não** |
| qualquer outra | — | **reportada, nunca adivinhada** (comportamento atual, mantido) |

A fonte **não tem** situação equivalente a `Rompido` (o enum da API é
`TODOS·EM_ANDAMENTO·LIQUIDADO·CANCELADO`). O importe portanto **nunca produz `Rompido`** — só o consome
como estado de origem.

## 4. Onde a sobrescrita entra — e por que a ordem importa

Hoje `processarAba` faz, nesta ordem: acha o acordo → **pula a aba inteira se o acordo não é vigente**
([`:206`](../../app/src/Cobranca/UseCase/ImportarAcordosDetalhadosUseCase.php#L206)) → processa parcelas
e contas → confere a situação **no fim**, só para reportar.

A nova ordem:

1. acha o acordo (inalterado — nunca cria, §3.1);
2. **resolve a situação da planilha e calcula o `statusFinal`** (mapeado, ou o atual se não mapeada);
3. **aplica o `statusFinal`** (só na confirmação — ver §6);
4. a guarda de vigência passa a olhar o **`statusFinal`**, não o status que estava no banco;
5. processa parcelas e contas normalmente.

A guarda do passo 4 **continua existindo, com a mesma justificativa**: escrever parcela ou reconciliação
contra acordo não vigente **cria dívida** — a conta reconstruída nasce com `acordoSubstituto`, e
`doCasoExigiveis` só exclui o que está substituído por acordo **vigente**; com o acordo não vigente ela
entra no saldo e cobra de novo uma dívida renegociada. O que muda é **de onde vem o status que ela
consulta**: da planilha, não do banco.

Consequência direta e desejada: uma aba `Liquidado` cujo acordo estava `rompido` no sistema **deixa de
ser pulada** — vira `Cumprido` (vigente) e é processada.

## 5. As duas direções, e o dinheiro de cada uma

### 5.1 Para vigente (`Ativo` / `Cumprido`) — é a direção que o dono vai exercitar

O dono decidiu importar **apenas `Em andamento` + `Liquidado`** (handoff §5). Os dois destinos são
vigentes. Logo, **na operação real nenhuma transição devolve dívida ao saldo.**

- **`Ativo` ↔ `Cumprido`**: os dois são vigentes ([`StatusAcordo::ehVigente`](../../app/src/Cobranca/Enum/StatusAcordo.php#L42-L48)).
  A substituição das originais e as parcelas continuam exatamente como estavam. **O saldo não se move.**
  Escrita simples. Este é o caso mais comum previsto no dev: a Receitas marca `Cumprido` quem quitou as
  parcelas da janela de 2026, e a planilha de Acordos diz `Em andamento` porque ainda há parcela futura.
- **`Rompido`/`Cancelado` → `Ativo`/`Cumprido` = REATIVAÇÃO.** Mesmo efeito do D6 da Receitas: as
  originais voltam a "substituída" e **saem do exigível**. `CalculadoraSaldo` só abate alocação de
  obrigação exigível — então **dinheiro já recebido numa original para de abater o saldo**, e o devedor
  volta a ser cobrado por algo que pagou.
  **Obrigatório:** medir ANTES de escrever e **reportar**; nunca corrigir em silêncio.
  **Obrigatório:** limpar `motivoRompimento` e `motivoCancelamento` — senão a tela mostra motivo de
  rompimento num acordo ativo. É o que `reativarPorImportacao` já faz.

### 5.2 Para não vigente (`Cancelado`) — implementada, mas fora da operação

Por decisão do dono, **o arquivo de cancelados não é importado**. O ramo existe porque a regra é
*"sobrescreve sempre, sem exceção"*, e um mapa incompleto voltaria a esconder dado — foi assim que esta
frente nasceu. Mas ele **não é exercitado pela operação atual**, e isso está registrado aqui de
propósito.

Se aplicado, `vigente → Cancelado` devolve as originais ao exigível, e **dois efeitos medidos exigem
tratamento**:

- **§D5 — descongelamento.** Sem `RestauradorObrigacoesOriginais::restaurar($substituidas)` as originais
  voltam ao saldo **com os juros parados**. É exatamente o defeito que o dono reportou e que a frente
  `cobranca-cancelar-acordo` corrigiu. **O importe tem de chamar o restaurador**, como
  `CancelarAcordoUseCase` faz.
- **Parcela paga.** `CancelarAcordoUseCase` **recusa** cancelar acordo com parcela paga
  (`AcordoComParcelaPagaException`), porque o valor recebido para de abater o saldo. **Aqui não pode
  lançar** — derrubaria o lote inteiro por causa de uma aba. Segue a regra do dono: **aplica, mede e
  reporta**, na mesma linha do que a Receitas faz na reativação.

⚠️ O ramo nasce **code-complete e não exercitado** — o arquivo de cancelados não entra na importação.
Antes de qualquer execução contra `*_CANCELADO.xlsx`, isto volta ao dono.

### 5.3 ✅ A ÚNICA EXCEÇÃO a "sobrescreve sempre" — decisão do dono, 04/08

A versão anterior desta spec registrava como *"risco aceito"* o fato de o importe aplicar o cancelamento
mesmo com **parcela paga**, enquanto o caminho manual o **recusa**
(`CancelarAcordoUseCase::recusarSeAlgumaParcelaFoiPaga`, `AcordoComParcelaPagaException`). O dono decidiu
contra o risco:

> *"se por acaso alguém clicar em pagar uma parcela e depois o import informa que aquele acordo foi
> cancelado, sugiro apenas um aviso para excluir o pagamento para poder cancelar o acordo."*

**Regra:** desativação (`vigente → Cancelado`) de acordo com parcela paga **não é aplicada**. O status
fica como está e o lote ganha um aviso **acionável**, com o caminho da saída.

| | |
|---|---|
| por que | cancelar tira as parcelas do exigível **levando a alocação junto**: o dinheiro recebido para de abater o saldo e o devedor volta a ser cobrado por algo que pagou |
| o que acontece | status **mantido**; aba processada normalmente (o acordo segue vigente de fato) |
| o aviso | *"há PARCELA PAGA no sistema — status mantido em X. Para aplicar: exclua o recebimento da parcela … e importe de novo"* |
| por que não lança exceção | derrubaria o lote inteiro por causa de uma aba. Vira aviso, não erro |
| onde é decidido | **antes** do `$statusFinal`, para a guarda de vigência continuar enxergando o status real e a aba não ser pulada como se tivesse mudado |
| paridade | o mapa de parcelas pagas é fotografado **antes do laço**, sobre o banco intocado — mesma disciplina do §6 |

A saída existe e é a **etapa 1**: `cobranca_pagamento_excluir` apaga o recebimento e reabre a parcela;
feito isso, a importação seguinte cancela sem tocar em dinheiro recebido.

⚠️ Isto **alinha o importe ao caminho manual** em vez de contrariá-lo — some, portanto, a única
contradição que a §5.2 declarava. A regra *"o importe sobrescreve"* continua valendo para todo o resto:
reativação, `Ativo ↔ Cumprido` e cancelamento de acordo **sem** parcela paga.

## 6. Paridade prévia × confirmação — a invariável que não pode cair

`prever()` e `confirmar()` percorrem **o mesmo** `processar()`, com `$usuario === null` marcando o
dry-run. A sobrescrita de status tem de respeitar isso em dois níveis:

1. **A DECISÃO é sempre calculada** — o `statusFinal` e a guarda de vigência do §4 rodam **idênticos**
   nos dois modos. Se a prévia decidisse pelo status do banco e a confirmação pelo status sobrescrito,
   as duas processariam **conjuntos de abas diferentes**, e a prévia deixaria de projetar a confirmação.
   Este é o defeito mais caro possível aqui.
2. **A ESCRITA só acontece com `$usuario !== null`.** No dry-run o `Acordo` é entidade *managed*: chamar
   `setStatus` sujaria a UnitOfWork e um flush posterior gravaria a mudança que a prévia prometeu não
   fazer. **A prévia não toca na entidade.**

A medição do §5.1 (dinheiro parado pela reativação) é lida **antes do laço**, sobre o banco intocado,
nos **dois** modos — é o que impede que a confirmação inclua efeitos da própria execução e divirja da
prévia (`feedback_previa_precisa_de_estado`; o mesmo motivo de `mapearDinheiroParadoPelaReativacao`).

## 7. Histórico

Toda sobrescrita de status registra evento no caso **do acordo**, com
`origem: 'importacao_acordos_detalhados'`, `statusAnterior` e `statusNovo`. Mudança de status move
dinheiro; sem a linha do histórico ninguém descobre depois por que o estado mudou. Mesma convenção do
`reativarPorImportacao` da Receitas.

Não usar `MarcarAcordoCumpridoUseCase` / `RomperAcordoUseCase` / `CancelarAcordoUseCase`: os três exigem
`estaAtivo()` e lançam `AcordoNaoAtivoException` fora disso — recusariam justamente as transições desta
spec (`Cumprido → Ativo`, `Cancelado → Ativo`). A escrita é direta na entidade, como na Receitas.

## 8. Extrair o medidor da reativação

`dinheiroParadoPelaReativacao` e `mapearDinheiroParadoPelaReativacao` saem de `ImportarReceitasUseCase`
para um serviço compartilhado em `App\Cobranca\Service\Importacao\`, consumido pelos **dois**
importadores.

Motivo: é **a mesma decisão de dinheiro**, e o próprio código já registra o preço de duplicá-la — o
`ImportarAcordosDetalhadosUseCase` tem `prever()`/`confirmar()` com implementação única *"porque ali as
duas cópias já divergiram uma vez"* (`:98-104`). O método carrega quatro lições cicatrizadas em
comentário (canais separados, snapshot × ao vivo, régua de existir-alocação, piso em zero); copiá-las é
garantir que uma das cópias perca uma.

**A extração é movimento puro, sem mudança de comportamento** — provada pelos testes existentes da
Receitas passando sem alteração.

## 9. O que NÃO muda

Continuam valendo, sem exceção: o acordo nunca é criado aqui (§3.1) · casamento por **NN + competência**
(§3.2) · valor lançado nunca é sobrescrito (§4) · baixa de pagamento fora de escopo (§5) · obrigação
nunca é apagada (invariável 14) · INV-I (acordo não substitui parcela de acordo) · caso encerrado não
recebe obrigação · situação **não mapeada** continua sendo reportada e nunca adivinhada.

## 10. Testes exigidos

Unit, em `app/tests/Cobranca/Unit/ImportarAcordosDetalhadosUseCaseTest.php` (arquivo existente).
**Cada teste é provado reintroduzindo o defeito, conferindo QUAL assert fica vermelho** — asserts que
não podem falhar já foram encontrados três vezes nesta frente.

| # | Caso | Assert que tem de ficar vermelho ao reintroduzir o defeito |
|---|---|---|
| 1 | planilha `Liquidado`, sistema `ativo` | status vira `cumprido` |
| 2 | planilha `Em andamento`, sistema `cumprido` | status volta a `ativo` |
| 3 | planilha `Liquidado`, sistema `rompido` | status vira `cumprido` **e a aba é processada** (parcelas/contas), em vez de pulada |
| 4 | planilha `Em andamento`, sistema `cancelado` | status vira `ativo`, `motivoCancelamento` fica `null`, e o **aviso de reativação** aparece no resultado |
| 5 | reativação com pagamento alocado numa original | a lista de "dinheiro que para de abater" traz o NN e o valor |
| 6 | **dry-run** de qualquer um dos acima | o status no banco **não muda**, e o `ResultadoImportacaoAcordos` projeta a mesma decisão da confirmação |
| 7 | **prévia × confirmação processam as mesmas abas** (caso 3: sistema `rompido`, planilha `Liquidado`) | as duas devolvem o mesmo conjunto de `parcelasCriadas`/`contasMarcadas` |
| 8 | situação não mapeada (`"Suspenso"`) | status **inalterado** e linha em `situacoesDesconhecidas` |
| 9 | planilha `Cancelado`, sistema `ativo` | status vira `cancelado`, o restaurador é chamado (originais descongeladas) e a aba é **pulada** |
| 10 | idempotência: rodar duas vezes | a segunda execução não registra evento nem reporta divergência |

Funcional: o comando `app:cobranca:importar-acordos` imprime as sobrescritas e os avisos de reativação
na prévia, antes de qualquer escrita.

## 11. Como a entrega é conferida

1. suíte completa verde no container;
2. `/review` (`feature-review-agent`, read-only) contra **esta** spec;
3. correção do que a revisão apontar;
4. **segunda passada de `/review`** — exigência de risco ALTO, e nesta frente as duas passadas anteriores
   acharam defeito **nas correções da passada anterior**;
5. medição no dev restaurado (`saas_ux` a partir de `saas_ux_antes_etapa3`, 10 acordos / 3.431 obrigações
   / 0 pagamentos): Receitas → Acordos (`EM_ANDAMENTO` + `LIQUIDADO`, duas carteiras), conferindo o
   principal que sai e o que entra no saldo.
