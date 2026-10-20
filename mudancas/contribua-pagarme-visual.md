---
tipo: alteracao
---
## Changelog
- Contribua: o formulário de cartão do Pagar.me segue o desenho 08 (checkout "Pagamento seguro"): rótulos em maiúscula, número com máscara em grupos de 4 (Amex 4-6-5) e selo da bandeira (Visa, Mastercard, Elo, Amex, Hipercard), borda vermelha quando o Luhn falha, nome em caixa alta, CVV oculto e a grade Validade/CVV/CPF. Os erros passam a ser os do desenho, um por campo, no lugar do genérico "Confira os dados do cartão.". O cartão continua indo só ao Pagar.me; o BlueJus recebe o token.

## Para o cliente
- Ainda nada visível: o pagamento por cartão do Pagar.me entra quando o checkout da assinatura for ligado.
