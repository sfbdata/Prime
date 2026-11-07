---
tipo: correcao
---
## Changelog
- Contribua (Asaas): o checkout da assinatura de espaço vai sem `customer` e sem `customerData`. O Asaas recusava com HTTP 400 o checkout com um cliente pré-criado só com nome, CPF/CNPJ e e-mail (pedia telefone e endereço), e toda assinatura no cartão falhava. Agora o pagador preenche os próprios dados na página do Asaas.
- O checkout leva a referência do pedido em `externalReference`; a consulta da assinatura lê o `checkoutSession` e a liga ao pedido exato pelo id do checkout que o pedido já guardava. O BlueJus não cria nem atualiza mais cliente no Asaas.
- Checkout de pedido cancelado ou de outro escritório não liga a assinatura a outro pedido; o Master recebe o aviso de "assinatura paga sem pedido" com o escritório do checkout.

## Para o cliente
- A assinatura de mais espaço no cartão volta a abrir a página de pagamento. Nela você informa os dados do titular (telefone e endereço também) e o pagamento é ligado ao seu pedido assim que for confirmado.
