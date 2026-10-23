---
tipo: correcao
---
## Changelog
- Ponto: o "Aprovar todos" do lote de justificativas passa a gravar a batida dos pedidos de esquecimento de registro, igual à aprovação individual. A gravação saiu do `TenantController` para `App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase`, chamado pelos dois caminhos, com guarda de escritório. As outras justificativas seguem só abonadas. Sem migration.

## Para o cliente
- Ao aprovar de uma vez todos os pedidos de um lote, as batidas esquecidas agora entram na folha de ponto, como já acontecia ao aprovar um pedido por vez.
