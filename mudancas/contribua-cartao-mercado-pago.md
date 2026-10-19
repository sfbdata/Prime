---
tipo: novidade
---
## Changelog
- Contribua: adaptador de cartão do Mercado Pago (`App\Contribua\Gateway\MercadoPago`) para a assinatura de espaço, cumprindo o contrato `GatewayDeAssinatura` da C3: assinatura mensal sem plano com o cartão tokenizado, consulta pelo semáforo de cobrança, cancelamento e avisos com a assinatura x-signature conferida. Card Payment Brick no navegador (`public/js/contribua-mercado-pago.js`, `ContribuaMercadoPago`). Chaves vazias no `.env` até o dono configurar.

## Para o cliente
- Ainda nada visível: o pagamento por cartão do Mercado Pago entra quando o checkout da assinatura for ligado.
