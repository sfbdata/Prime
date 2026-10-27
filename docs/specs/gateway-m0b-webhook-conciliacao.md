# Gateway M0b: aviso do banco e conciliação do registro único de pagamentos

> Spec de risco **ALTO** (dinheiro, endpoint público). Etapa entre o M0 (#471) e o M1 (`gateway-m1-trabalho-externo.md`), decidida em 27/10 (Coordenador). Nenhum código escrito.
> Pré-requisito: C4 da Cora (#477) no master. Sem consumidor novo: o M0b só faz o `Pagamento` ser **confirmado e vencido pelo banco**.

## 1. Problema

O M0 (#471) criou `pagamento` e `ConfirmarPagamentoUseCase`, mas nada o chama a partir do banco. A C4 (#477) já tem webhook, reconsulta e conciliação, porém só para o `Apoio` do Contribua (`CobrancaPixDaConta`). O M0b liga os dois lados sem mexer no comportamento do Contribua.

## 2. Estado real (medido nas branches #471 mergeado e #477)

| Peça | Hoje |
|---|---|
| `WebhookDoBancoController::cora` (`POST /api/webhooks/cora/pix`, #477) | Lê `webhook-event-id`, `-type`, `-resource-id`. **Só dispara se o recurso for uma `CobrancaPixDaConta`** (`$this->cobrancas->doBanco('cora', $recurso)`); caso contrário responde 200 sem gravar. Grava `NotificacaoRecebida` (único por origem + evento) e enfileira `ReconsultarCobrancaNoBanco`. |
| `ReconsultarCobrancaNoBancoHandler` | Chama `ConfirmarCobrancaPeloBancoUseCase` (Apoio) e marca o aviso processado. |
| `ConciliarCoraCommand` (`app:cora:conciliar`) | Reconsulta as `CobrancaPixDaConta` abertas. |
| `BancoPix` | `criarCobranca`, `consultar(idNoBanco): SituacaoDaCobranca` (`paga`, `cancelada`, `valorPagoCentavos`, `pagaEm`, `codigo`). |
| `ConfirmarPagamentoUseCase` (M0) | Recebe `referencia`, `valorPagoCentavos`, `endToEndId`, `pagoEm`, `confirmadoPor`. Idempotente, nunca lança por regra. |
| `PagamentoRepository` | `porReferenciaDoAviso`, `vencidasAguardando`, `ultimoDaOrigem`. **Não tem busca por `id_externo`** (o que o aviso da Cora traz). |

## 3. Escopo

1. **Roteamento do aviso por recurso.** O webhook passa a reconhecer também o `id_externo` de um `pagamento` `aguardando` (ou recém `expirado/cancelado`, para o dinheiro tardio cair em `divergente`). Ordem: primeiro `CobrancaPixDaConta` (Contribua, comportamento atual intacto), depois `Pagamento`. Recurso desconhecido continua respondendo 200 sem gravar nem enfileirar.
2. **Reconsulta do `Pagamento`.** Nova mensagem `ReconsultarPagamentoNoBanco(banco, idExterno, idEvento)` e handler `ReconsultarPagamentoNoBancoHandler` -> `ConfirmarPagamentoPeloBancoUseCase`:
   - carrega o `pagamento` por `id_externo` (nova consulta no repositório), confere provedor e **ambiente** configurado agora (como o Contribua: credencial é do ambiente);
   - `BancoPix::consultar` por mTLS; só a resposta do banco confirma;
   - `cancelada` no banco: `Pagamento::cancelar()` se ainda `aguardando`;
   - `paga`: confere que o `codigo`/txid devolvido bate com a `referencia` do pagamento (senão registra evento `inconsistente`, loga o id e **não** confirma), e chama `ConfirmarPagamentoUseCase` com `confirmadoPor = 'banco'`, `endToEndId` do banco e `pagoEm` do banco;
   - sandbox: nenhuma liberação de consumidor; o `pagamento` fica `paga` só se a cobrança for de um escritório de teste do Master (mesma regra do Contribua). A forma exata é decisão do §8.1.
3. **Conciliação.** `app:pagamento:conciliar`, nova rotina do Scheduler a cada 15 min (padrão da `app:cora:conciliar`; seguir o catálogo `AgendaDeTarefas` e o teto de tenant-aware do Scheduler):
   - reconsulta `pagamento` `aguardando` com `id_externo`, criados há mais de 2 min e vencidos há menos de 7 dias (cobre aviso perdido);
   - **expira** os `aguardando` vencidos sem pagamento (`PagamentoRepository::vencidasAguardando` + `Pagamento::expirar`), depois de uma última reconsulta (um pagamento no último minuto não vira `expirada`);
   - sem `cobraPorConta()` ou com a integração desconectada, não consulta o banco e só registra no log que pulou;
   - limita o lote (200 por rodada) e a taxa de chamadas ao banco.
4. **Relatório diário ao Master** (`divergente` e `aguardando` vencidas): aviso na Central pelo mesmo `AvisoAoMaster` do Contribua, com novo tipo `pagamento.divergente`. Sem tela nova.
5. **Estorno** continua fora (spec mãe §3.6, decisão 3: Master decide caso a caso, sem automático).

Fora de escopo: qualquer consumidor (TE, Service Desk, Contribua) e qualquer tela. O `PagamentoConfirmado` é despachado e **ninguém o ouve ainda**; isso é o M1.

## 4. Segurança

- O aviso **nunca confirma**: só grava e enfileira (a Cora não assina). Autenticidade vem da reconsulta mTLS.
- O endereço é público: aviso para recurso que não é nosso não grava nem enfileira (não enche a fila que o Drive e a Inteligência dividem).
- Tenant resolvido **pelo `pagamento`**, nunca pelo aviso. Cobrança de outro tenant não aparece em lugar nenhum.
- Idempotência em quatro camadas: `NotificacaoRecebida` (origem + evento), `FOR UPDATE` do `pagamento` (já no M0), `end_to_end_id` único, e o handler marcar o aviso processado só depois de terminar.
- Valor e referência do servidor: o pago é comparado ao cobrado pelo `ConfirmarPagamentoUseCase` (menor = `divergente`).
- Nenhuma chamada ao banco dentro de transação; falha do banco (timeout) deixa o `pagamento` como está e a conciliação repete. Falha repetida de **4xx** de autenticação (certificado vencido) vira aviso ao Master (uma vez por dia), não loop silencioso.
- Logs sem corpo, sem headers completos e sem CPF/CNPJ do pagador; só ids internos.
- Rate limit do endpoint público (já existe a regra `^/api/webhooks/`; conferir se há limiter por IP; se não, criar o da Cora).

## 5. Modelo de dados

Sem tabela nova. Possível **índice** em `pagamento (provedor, id_externo)` (busca do aviso). Se faltar, entra em migration própria do M0b (número a pedir ao Coordenador; **uma migration por vez entre os PRs abertos**). `PagamentoRepository::porIdExterno(string $provedor, string $idExterno)`.

## 6. Contratos que mudam (avisar antes)

- `WebhookDoBancoController` e `ReconsultarCobrancaNoBancoHandler` são da C4 (#477). O M0b só **acrescenta** um ramo; não altera o fluxo do Apoio. Depende do #477 mergeado: não escrever código antes.
- Catálogo do Scheduler (`AgendaDeTarefas`) e teto tenant-aware: arquivo quente. Avisar antes de editar.

## 7. Testes (antes do código)

- Unit: `ConfirmarPagamentoPeloBancoUseCase` (paga, cancelada, a menos, a mais, código do banco que não bate, ambiente trocado, já paga, expirada que recebeu dinheiro), com `CoraFalsa`.
- Functional: webhook (recurso de `Pagamento` enfileira; recurso do Contribua segue igual; desconhecido responde 200 sem gravar; evento repetido uma vez só); conciliação (aviso perdido, expira vencida só depois da reconsulta, integração desligada pula, lote limitado); corrida webhook x conciliação confirma uma vez.
- Arquitetura: `Pagamento` não importa `Contribua\Entity`; a ponte é `Contribua\Banco\BancoPix` (interface), conforme a spec mãe.

## 8. Perguntas em aberto

1. **Sandbox:** o `Pagamento` deve ficar `paga` no ambiente de testes da Cora? Recomendação: **não**, só registrar evento `teste` e avisar o Master, como o Contribua (nenhum consumidor libera por dinheiro de teste).
2. **Onde mora o código da ponte** `Pagamento` x `BancoPix` (hoje `BancoPix` é do domínio `Contribua`): mover a interface para `Pagamento\Banco` ou deixar? Recomendação: **deixar** no M0b e decidir a mudança de lugar quando entrar o segundo banco; mover agora quebra a C4 recém-aberta.
3. Número da migration (se o índice for preciso).

## 9. Rollout

Ligado só quando houver pelo menos um `pagamento` com `id_externo` (ou seja, depois do M1). Antes disso, o webhook e a conciliação rodam sem achar nada e não têm efeito. Revisão independente obrigatória (ALTO) antes do merge.
