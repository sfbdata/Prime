# Correção: "Aprovar todos" não grava a batida do esquecimento

Risco: ALTO (ponto eletrônico). Sem migration.

## Defeito

Na aba "Justificativas" do colaborador (admin do escritório), o botão "Aprovar todos" de um lote
abona os pedidos de esquecimento de registro, mas não grava a batida pedida. O pedido some das
pendências e o dia continua incompleto na folha.

## Causa

`TenantController::aprovarTodosJustificativas` só troca o status para `abonado` e notifica. A
gravação da batida (`RegistroPonto` com a data do pedido e a hora esquecida) existia apenas, em
código embutido, na aprovação individual (`aprovarJustificativa`).

## Correção

- Extrair a gravação da batida para `App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase`
  (`__invoke(JustificativaPonto, Tenant): ?RegistroPonto`). Ele só persiste (quem chama faz o
  `flush`), devolve `null` quando a justificativa não é esquecimento ou falta tipo/hora/data, e
  recusa justificativa de outro escritório (guarda de tenant).
- A aprovação individual e o "aprovar todos" chamam o mesmo UseCase. A observação da batida
  continua a mesma da aprovação individual.
- Justificativas que não são de esquecimento seguem como hoje (só abonadas, sem batida).
- Guardas de tenant, vínculo, permissão (`admin.users.manage`) e CSRF ficam como estão; o
  `findBy` do lote segue escopado ao escritório da URL.
- Fora do escopo: a recusa de batida duplicada do mesmo tipo no dia (spec
  `ponto-esquecimento-nao-trava.md`, R1). Ao integrar com aquela frente, o "aprovar todos" deve
  receber a mesma recusa.

## Testes

Functional `tests/Ponto/Functional/AprovarTodosEsquecimentoControllerTest.php`:

1. Lote com 2 esquecimentos pendentes e 1 justificativa comum; "aprovar todos" grava as 2 batidas
   com data, hora, tipo e observação iguais às da aprovação individual; os 3 pedidos ficam
   `abonado`; a comum não gera batida.
2. Isolamento: pedido de esquecimento de outro escritório, mesmo `batchId` e mesma pessoa, segue
   `pendente` e sem batida.
3. Aprovação individual continua gravando a batida (regressão da extração).
