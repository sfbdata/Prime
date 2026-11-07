---
tipo: correcao
---
## Changelog
- Contribua (Asaas): o checkout da assinatura de espaço vai sem `customer` e sem `customerData`. Com um cliente pré-criado só com nome, CPF/CNPJ e e-mail, o Asaas recusava o checkout com HTTP 400 (pedia telefone e endereço; provado no sandbox). Agora o pagador preenche os próprios dados na página do Asaas, e o BlueJus não cria nem atualiza cliente no Asaas.
- O checkout leva a referência do pedido em `externalReference`; a consulta da assinatura lê o `checkoutSession` e a liga ao pedido exato pelo id do checkout que o pedido já guardava.
- Checkout de pedido cancelado ou de outro escritório não liga a assinatura a outro pedido. Toda assinatura paga sem pedido avisa o Master: com o escritório da referência ou, sem ela, o do pedido que abriu o checkout; sem nenhum dos dois, o aviso sai sem escritório.

## Para o cliente
- A assinatura de mais espaço no cartão passa a abrir a página de pagamento do Asaas, onde o titular preenche os próprios dados (inclusive telefone e endereço) e paga. O pagamento é ligado ao seu pedido assim que o Asaas confirma.
