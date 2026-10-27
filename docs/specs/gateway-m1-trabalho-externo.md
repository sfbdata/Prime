# Gateway M1: Trabalho Externo com PIX dinâmico e confirmação automática

> Spec de risco **ALTO** (dinheiro). Etapa M1 da `docs/specs/gateway-pagamentos.md` (§5.1 e §5.3). Nenhum código escrito.
> Pré-requisitos: M0 (#471, registro único `pagamento`) e C4 da Cora (#477, `BancoPix`/`BancoCora`) no master.

## 1. O que muda para quem usa

Hoje a aba Pagamento da demanda mostra um PIX **estático** (`PixDaDemanda`, txid = código da demanda), o cliente envia o comprovante e o Master ou a Equipe clica em "Confirmar recebimento". Com o M1, quando a integração com a Cora está ligada:

- o QR Code é uma **cobrança dinâmica de valor fixo** (decisão do dono 1b): o pagador não consegue alterar o valor;
- o banco avisa, a BlueJus reconsulta e a demanda vira **Pago** sozinha, com a mensagem "Pagamento de R$ x confirmado via PIX" já existente (autor "Sistema (banco)");
- o botão manual e o comprovante **continuam** como plano B, o manual só com motivo registrado (decisão do dono 5);
- sem credencial da Cora, nada muda: cai no PIX estático de hoje (dev igual a produção).

## 2. Estado real do código (medido em `origin/master` 4577a20ad + M0)

| Peça | Onde | Situação |
|---|---|---|
| Aba Pagamento | `TrabalhoExterno/Service/ApresentadorDoPagamento::bloco()` | Valor sai de `DemandaExterna::valorEfetivoDoPagamento()` (valor ajustado pelo Master ou proposta aceita). QR e copia e cola de `PixDaDemanda::gerar()`. |
| Confirmação manual | `ConfirmarRecebimentoDoPixUseCase` | Trava a demanda `FOR UPDATE`, confere situação e `pagoEm`, chama `DemandaExterna::confirmarRecebimentoDoPix($autor, $valorFormatado)` e `AvisosDoTrabalhoExterno::andamentoDaDemanda`. |
| Ajuste de valor | `AjustarValorDoPagamentoUseCase`, `podeAjustarValorDoPagamento` | Master ajusta o valor a cobrar (`valorPagamentoAjustado`); spec `trabalho-externo-valor-editavel.md`. |
| Registro único | `App\Pagamento` (M0) | `CriarPagamentoUseCase` (idempotente por `referencia`, congela o valor), `ConfirmarPagamentoUseCase` (trava, valor menor = `divergente`, maior = libera com diferença, despacha `PagamentoConfirmado` só depois do commit), `Pagamento::cancelar()` e `expirar()`. Origem `OrigemDoPagamento::TrabalhoExterno`. |
| Banco | `App\Contribua\Banco\BancoPix` (#477) | `criarCobranca(PedidoDeCobrancaPix)`, reconsulta mTLS, `ConciliarCoraCommand`. |

O M0 não tem webhook, conciliação nem consumidor: o M1 liga o primeiro consumidor, e por isso precisa dos três (§6).

## 3. Decisões já tomadas (não reabrir)

Do `decisoes-27-10.md` e da spec mãe §9: Cora para PIX; PIX dinâmico de valor fixo (1b); pagamento a menor fica `divergente` e não libera, a maior libera e avisa o Master; estorno decidido caso a caso pelo Master; botão manual só com motivo; validade **24 h** no Trabalho Externo; a BlueJus absorve a tarifa; histórico começa do zero.

## 4. Fluxo

### 4.1 Pedir o QR (aba Pagamento)

1. `ApresentadorDoPagamento::bloco()` hoje é leitura pura. Gerar cobrança no banco numa leitura (GET) é efeito colateral e pode disparar chamada externa a cada abertura da aba. Por isso a cobrança é criada por **ação explícita** na entrada da aba: novo `PrepararPagamentoDaDemandaUseCase`, chamado pelo controller antes de montar o bloco, **só quando** a integração está ligada, há valor (> 0), a situação permite pagar e não existe cobrança `aguardando` válida para o valor atual.
2. Referência estável: `TE<codigo>-<n>` (n = tentativa, 1, 2...). `CriarPagamentoInput` com `origem = TrabalhoExterno`, `origemId = id da demanda`, `valorCentavos = valorEfetivoDoPagamento()` em centavos, `metodo = pix`, `provedor = cora`, `expiraEm = agora + 24 h`.
3. Ordem obrigatória (spec mãe §3.1): grava o `pagamento` (com `idempotency_key`) **antes** de chamar o banco; só então `BancoPix::criarCobranca` com a mesma chave. Timeout no banco: o `pagamento` fica `aguardando` sem `brCode`; a próxima abertura da aba repete a chamada com a **mesma** chave (o banco devolve a cobrança se já criou).
4. Falha certa do banco (4xx, integração desligada no meio): o `pagamento` é `cancelar()`ado e a aba cai no PIX estático com o aviso "Confirmação automática indisponível, envie o comprovante". Nunca mostra erro cru nem some o QR.
5. O `brCode` e o `idNoBanco` voltam por `registrarIdExterno` e por um campo `brCode` a acrescentar no registro. **Ponto de contrato com o M0:** `pagamento` hoje não guarda o copia e cola. Decisão proposta: coluna `br_code` (TEXT, nula) na **migration do M1**, preenchida ao criar a cobrança; evita reconsultar o banco a cada abertura. Alternativa (reconsultar) rejeitada: custa uma chamada mTLS por visita.

### 4.2 Confirmar

1. O banco avisa em `POST /api/webhooks/cora/pix` (#477, `WebhookDoBancoController`). O aviso **nunca confirma**: grava `NotificacaoRecebida` e enfileira a reconsulta mTLS.
2. A reconsulta resolve a cobrança pela **referência** (`PagamentoRepository::porReferenciaDoAviso`), nunca por dado do aviso, e chama `ConfirmarPagamentoUseCase` (M0). Hoje o handler da #477 confirma o `Apoio` do Contribua; o M1 acrescenta a ramificação por origem: referência `TE...` vai para o `Pagamento`, `BJ...` continua no Apoio (a mudança no handler é do M1, ver §6.2).
3. `PagamentoConfirmado` (despachado depois do commit) é ouvido por `ConfirmarDemandaPeloBancoListener` no domínio TrabalhoExterno, que chama o miolo reutilizável (§4.3). O listener filtra `origem === TrabalhoExterno` e ignora as demais.
4. Resultados: `Confirmado` e `ConfirmadoComDiferenca` liberam (a diferença vai no aviso ao Master); `ValorMenor`, `CobrancaInativa` e `PagamentoRepetido` deixam a demanda como está, o `pagamento` fica `divergente` e o Master é avisado (central de avisos).

### 4.3 Miolo reutilizável (refatoração do `ConfirmarRecebimentoDoPixUseCase`)

Extrair o trecho que muda a demanda (`confirmarRecebimentoDoPix`, `andamentoDaDemanda`, `salvar`) para `RegistrarPagamentoDaDemandaService::registrar(DemandaExterna, string $autor, string $valorFormatado, ?int $pagamentoId)`. O caso de uso manual e o listener o chamam. Regras do miolo:

- trava a demanda `FOR UPDATE` e relê; `pagoEm !== null` = no-op (duas confirmações, manual e banco, simultâneas não pagam duas vezes);
- situação fora de `AcaoDemanda::RegistrarPagamento->origens()` (cancelada, por exemplo): não muda a demanda, o `pagamento` fica `divergente` e o Master decide (devolver ou aceitar). Nunca lança exceção dentro da transação do banco;
- autor da mensagem: "Sistema (banco)" no automático, o nome de quem clicou no manual.

### 4.4 Botão manual com a cobrança viva

`ConfirmarRecebimentoDoPixUseCase` passa a **recusar** (409, mensagem "Há um PIX dinâmico aguardando o banco") quando existe `pagamento` `aguardando` ainda dentro da validade para a demanda, **exceto** com motivo preenchido (Master ou Equipe com "Alterar a situação"). Com motivo: confirma a demanda, registra `Pagamento::aceitarDivergencia`-equivalente de pagamento manual (`confirmado_por = manual:<userId>`, `motivo_manual`) e **cancela** a cobrança no banco, para um PIX tardio cair em `divergente` em vez de pagar duas vezes sem ninguém ver. A tela ganha o campo "Motivo" só nesse caso (desenho a pedir ao dono, §9.1).

### 4.5 Valor editável (decisão do dono, 26/10)

O `AjustarValorDoPagamentoUseCase` passa a:

- recusar (409) quando existe `pagamento` `paga` da demanda: quem já pagou mantém o valor; o Master usa devolução + nova cobrança;
- com `pagamento` `aguardando`: `cancelar()` (e cancelar no banco), registrar em `pagamento_evento` o valor antigo e o novo e quem editou, e deixar a próxima abertura da aba gerar a tentativa `TE<codigo>-<n+1>` com o valor novo;
- QR antigo já impresso paga a cobrança cancelada: o aviso cai em `CobrancaInativa` e fica `divergente` para o Master decidir.

### 4.6 Expiração

Cobrança vence em 24 h (`Pagamento::expirar`, chamado pela conciliação; `PagamentoRepository::vencidasAguardando` já existe). Aba mostra "Cobrança vencida, gerar novo QR" (botão que dispara a tentativa n+1); nunca reabre a antiga.

## 5. Modelo de dados

Migration **única** do M1 (número a pedir ao Coordenador; série 20261027):

- `pagamento.br_code TEXT NULL` (§4.1.5);
- sem tabela nova; sem coluna na demanda: o vínculo é `pagamento.origem = trabalho_externo` + `origem_id`, lido por `PagamentoRepository::ultimoDaOrigem`. A demanda continua com `pagoEm` como verdade de leitura da tela; o `pagamento` é a verdade do "quem, quando e quanto".

Nenhuma alteração em `DemandaExterna` além do que o miolo já chama.

## 6. O que o M1 precisa e ainda não existe

1. **Ponto de entrada de aviso e conciliação do Pagamento** (a spec mãe põe no M0, mas o #471 não os trouxe): reconsulta mTLS por referência e `app:pix:conciliar` a cada 15 min. Pergunta ao Coordenador: entram no M1 como primeiro PR, ou ficam em "M0b" antes do consumidor? **Recomendação: M0b separado**, porque o webhook é público e merece revisão própria de ALTO risco.
2. **Handler da reconsulta** da #477 hoje fala só em `Apoio`. Precisa virar um roteador por prefixo da referência (`TE`, `BJ`, `SD`).
3. **Chave liga/desliga** `ConfiguracaoDePagamento` ("PIX dinâmico no Trabalho Externo"), padrão desligada. Existe na C3 (#95, já no master); confirmar o nome do recurso antes de criar outro.
4. **Credencial da Cora em produção** (pendência C5 do dono). Sem ela, o botão dinâmico some e o estático vale.

## 7. Segurança

- Valor, referência e escritório vêm do servidor e da própria cobrança; o aviso do banco nunca é fonte (spec mãe §6).
- Tenant da cobrança = tenant da demanda. `CriarPagamentoUseCase` já recusa referência de outro escritório; o `PrepararPagamentoDaDemandaUseCase` carrega a demanda pelo `LocalizadorDeDemanda` (404 em outro escritório) antes de criar.
- Convidado nunca vê QR nem `br_code` (o bloco já devolve `null` para ele).
- Três camadas de idempotência (`NotificacaoRecebida`, `FOR UPDATE` do `pagamento`, `end_to_end_id` único) mais a trava da demanda no miolo.
- Nenhuma chamada ao banco dentro da transação de confirmação.

## 8. Testes (escritos antes do código)

- Unit: `PrepararPagamentoDaDemandaUseCase` (cria uma vez, reusa a viva, nova tentativa após vencer, integração desligada cai no estático, valor zero não cria); `RegistrarPagamentoDaDemandaService` (já paga = no-op, situação cancelada = não muda, autor "Sistema (banco)"); `AjustarValorDoPagamentoUseCase` (recusa com `paga`, cancela a `aguardando` e gera evento).
- Functional: aba com banco falso (`CoraFalsa`): QR dinâmico aparece e não aparece sem integração; confirmação pelo banco leva a demanda a Pago com a mensagem do desenho; valor menor deixa `divergente` sem pagar; aviso repetido não duplica; corrida banco x botão manual paga uma vez; manual sem motivo com cobrança viva = 409; manual com motivo cancela a cobrança; outro escritório = 404; Convidado não recebe o bloco.
- Arquitetura: `TrabalhoExterno` não importa `Pagamento\Entity`, só os casos de uso e o evento (mesma regra do `EspacoDoEscritorioSoNoContribuaArquiteturaTest`).

## 9. Perguntas em aberto

1. **Desenho do campo "Motivo"** e da mensagem "PIX dinâmico aguardando o banco" na aba Pagamento: não existe no desenho de 25/10. Pedir ao dono antes de implementar a tela (regra do desenho manda; não inventar).
2. **M0b** (webhook + conciliação do `Pagamento`) separado do M1? Recomendação: sim (§6.1).
3. Nome do recurso liga/desliga em `ConfiguracaoDePagamento` (§6.3).
4. Número da migration do M1.

## 10. Rollout

1. Sandbox da Cora com um escritório de teste; botão manual ativo.
2. Produção com **um** escritório e o manual ainda sem trava.
3. Trava do manual (§4.4) ligada por chave separada, só depois de 2 semanas sem divergência não explicada.
4. Passos de revisão obrigatórios antes de cada degrau: `/review` contra esta spec; em ALTO, segunda revisão independente.
