# Spec: Service Desk (Chamados e Saldo) e "Chamados recebidos" no Master

Desenho: `docs/design/redesign-25-10-26/12 - Service Desk - VS 25.10.26.dc.html`.
Risco: **MÉDIO com rigor de ALTO nas partes de dinheiro** (saldo, débito, estorno): revisão
read-only antes de cada PR que toque o razão.

## Motivo
O escritório compra saldo e gasta em chamados para a equipe técnica do BlueJus (suporte e
treinamento agendado). A equipe (Master) recebe os chamados, atende e debita o valor ao concluir.
O `ServiceDesk` legado (`/servicedesk`, categorias de TI, `Chamado`) é outro produto: help desk
interno do escritório. **Não se mexe nele neste trabalho**; o novo módulo usa tabelas e rotas próprias.

## Regras de negócio (do desenho)
- Saldo por escritório (tenant). Mínimo **R$ 20,00** para abrir chamado (`< 20` bloqueia com
  "Saldo insuficiente para abrir o chamado.").
- Tipos: `suporte` (a partir de R$ 20,00) e `agenda`/Treinamento (a partir de R$ 60,00; exige data e horário).
- Prioridade: baixa, média, alta, urgente. Status: aberto, em andamento, resolvido, fechado.
- Abrir chamado **não debita**; guarda `valor` estimado. O Master debita ao concluir (`debitado`).
  Se o atendimento não for realizado, o valor volta (estorno). Débito nunca deixa o saldo negativo.
- Recarga: valores 20/50/100/200; PIX ("Já paguei") ou cartão ("Pagar"). Ambos criam movimento
  **pendente**; saldo só sobe quando confirmado. Confirmação = Master (manual) ou, no futuro, o gateway.
- Extrato: crédito, débito, reembolso, aguardando. Novo chamado avisa o Master (notificação).

## Modelo (domínio `App\ServiceDesk`, tudo com `tenant_id`)
- `SaldoDoEscritorio`: tenant único, `saldo_centavos` (inteiro). Atualizado só pelo razão, com
  `SELECT ... FOR UPDATE` (lock) para evitar corrida.
- `MovimentoDeSaldo`: razão imutável salvo a confirmação. Tipo (credito, debito, reembolso), `valor_centavos`,
  motivo, chamado opcional, `referencia` única (id da recarga, vai como txid ao gateway), `id_externo` único
  (end_to_end_id do PIX, idempotência do pagamento), `confirmado_em` (nulo = pendente, "Aguardando"),
  autor. **Rastro do pagamento** (decisão do dono, 26/10): quem pediu (`autor`, escritório), quando
  (`criado_em`, `confirmado_em`), quanto (`valor_centavos`), forma (`pix`|`cartao`), origem da confirmação
  (`manual` ou o provedor) e `confirmado_por` (usuário do Master na confirmação manual). Valor pago
  MENOR que o pedido grava `valor_recebido_centavos` e `divergente_em` e mantém a recarga pendente; MAIOR
  credita o que foi pago e grava a diferença nos mesmos campos (decisão do dono, 27/10: libera e avisa o Master).
  Saldo = soma dos confirmados; a coluna de saldo é cache conferido por teste.
- `ChamadoDeSuporte`: tenant, solicitante, número sequencial por tenant (#0001), tipo, prioridade,
  assunto, descrição, data/hora (agenda), status, `valor_estimado_centavos`, `valor_debitado_centavos`,
  histórico (`HistoricoDoChamado`: texto + data).
- Valores sempre em centavos inteiros; formatação só na borda.

## UseCases
`AbrirChamadoDeSuporte`, `SolicitarRecargaDeSaldo` (grava o pendente antes de chamar o provedor; desfaz se ele falhar; máx. 5 pendentes),
`ConfirmarRecargaDeSaldo` (Master ou webhook; devolve `ResultadoDaConfirmacao`, nunca lança por regra: exceção
fecharia o EntityManager),
`DebitarChamado` (Master, valida saldo), `EstornarChamado` (Master), `AlterarStatusDoChamado` (Master),
`ListarChamadosDoEscritorio`, `ListarChamadosRecebidos` (Master).

## Pagamento: ponto de integração isolado
Interface `ProvedorDeRecarga` (`iniciar(pedido): InstrucaoDePagamento`) com um único adaptador
`RecargaManual` (gera o PIX copia e cola do desenho e deixa pendente). **Nenhum gateway escolhido.**
Um gateway futuro implementa a interface e chama `ConfirmarRecargaDeSaldo` pelo webhook
(idempotente por `referencia` e por `id_externo`). Decisão do dono: **todos os métodos de pagamento migram
para um gateway** com rastreio automático de quem, quando e quanto; o adaptador manual é transitório. Cartão: sem adaptador real, a opção só registra pedido
pendente (como o desenho); número de cartão **nunca** é gravado nem trafega para o servidor
(só bandeira e 4 últimos dígitos, se o adaptador futuro devolver).
Ponto aberto ao dono: o desenho tem CNPJ e chave PIX fixos; confirmar que são os do BlueJus.

## Telas
1. Escritório (`/service-desk`): cabeçalho, saldo + extrato, KPIs filtro, lista, modais abrir
   chamado / adicionar saldo / ver chamado, toast. Igual ao desenho (skill `desenho-fiel`).
2. Master "Chamados recebidos" (`/master/chamados-recebidos`): lista de todos os escritórios,
   mudar status, debitar, estornar, confirmar recargas pendentes. Desenho omisso: segue a
   convenção das telas do Master.

## Fatias (PR pequeno cada)
1. Razão + saldo + entidades + migration + UseCases de saldo (+ testes unit).
2. Chamado de suporte (abrir, listar, histórico) + UseCases.
3. Tela do escritório conforme o desenho.
4. Ponta Master "Chamados recebidos" (+ confirmar recarga, debitar, estornar).
5. Recarga (PIX copia e cola / pedido de cartão) via `ProvedorDeRecarga`.

## Chamado de suporte (fatia 2)
`AbrirChamadoInput` traz `tenant` e `solicitante` separados: o **controller** garante que o solicitante é o
usuário logado do escritório (o UseCase não reconfere). Abrir valida o pedido antes da transação, trava o saldo,
confere R$ 20,00, reserva o número (`proximoNumero` com o saldo travado + único `tenant, numero`) e grava a
linha "Chamado aberto por ...". Preço por tipo: `PrecoDoAtendimento` (global, Master; sem linha = sem estimativa).
Chamado de outro escritório responde `ChamadoNaoEncontrado` (404).

## Exclusão do escritório (purga)
`service_desk_movimento` e `service_desk_saldo` entram na `ORDEM_DELECAO` (apagados), como o apoio do
Contribua: vale a decisão do dono de que a exclusão da conta apaga na hora (dado só do escritório, sem
nota fiscal emitida por este módulo). **Ponto aberto ao dono**: se o contador exigir guarda do registro de
recebimentos por 5 anos, passa para `TABELAS_RETIDAS` e a FK de tenant deixa de ser CASCADE (migration nova).
Ambas as entidades são `Auditavel` (todo crédito, débito e confirmação entra na trilha).

## Permissões / tenant
Escritório: módulo `servicedesk` (existente) para ver e abrir. Master: gate de Master existente.
Todo repositório filtra por tenant; teste de isolamento entre dois escritórios obrigatório.

## Arquivos quentes previstos
Migration (1, fatia 1), `translations/messages.pt_BR` (fim do bloco), `_sidebar.html.twig` (link),
CSS global só se o desenho exigir (preferir CSS escopado da tela).
