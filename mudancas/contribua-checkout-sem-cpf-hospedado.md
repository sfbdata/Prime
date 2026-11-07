---
tipo: alteracao
---
## Changelog
- Contribua: no checkout de página hospedada (Asaas), a janela de assinatura de espaço deixa de mostrar e de exigir o campo "CPF/CNPJ do titular"; o pagador o informa na página do gateway. No formulário de cartão (Stripe, Mercado Pago, Pagar.me) o campo continua obrigatório. O `Pagador` aceita documento nulo e o modo da janela sai de `MontarPagamentoDoContribuaUseCase::modoDoGateway`, usado também pelo `IniciarAssinaturaUseCase`.

## Para o cliente
- Na assinatura de espaço pelo cartão, o CPF/CNPJ passa a ser pedido só uma vez, na página de pagamento.
