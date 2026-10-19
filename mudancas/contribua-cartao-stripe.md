---
tipo: novidade
---
## Changelog
- Contribua: adaptador de cartão do Stripe (`App\Contribua\Gateway\Stripe`) para a assinatura de espaço, cumprindo o contrato `GatewayDeAssinatura` da C3: cliente com o cartão padrão, assinatura mensal em BRL com a referência do pedido, consulta, cancelamento e avisos com a assinatura Stripe-Signature conferida. Cartão tokenizado no navegador pelo Payment Element (`public/js/contribua-stripe.js`, `ContribuaStripe`). Chaves vazias no `.env` até o dono configurar.

## Para o cliente
- Ainda nada visível: o pagamento por cartão do Stripe entra quando o checkout da assinatura for ligado.
