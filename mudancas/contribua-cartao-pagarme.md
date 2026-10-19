---
tipo: novidade
---
## Changelog
- Contribua: adaptador de cartão do Pagar.me (`App\Contribua\Gateway\Pagarme`) para a assinatura de espaço, cumprindo o contrato `GatewayDeAssinatura` da C3: assinatura avulsa mensal com o cartão tokenizado, situação lida pelas faturas, cancelamento e avisos com a assinatura RSA (JWKS) conferida. Campos do cartão na página, tokenizados direto no Pagar.me (`public/js/contribua-pagarme.js`, `ContribuaPagarme`). Chaves vazias no `.env` até o dono configurar.

## Para o cliente
- Ainda nada visível: o pagamento por cartão do Pagar.me entra quando o checkout da assinatura for ligado.
