# Contribua: adaptador de cartão do Stripe

Desenho: "08 - Contribua - VS 18.10.26" (assinatura de espaço com cartão; "Link do pacote" fixo como
alternativa). Contrato: `App\Contribua\Gateway\GatewayDeAssinatura` e o teste
`TesteDeContratoDoGatewayDeAssinatura` da contribua-c3 (f6). Risco: BAIXO no código (sem tela, sem
banco); a cobrança real depende das chaves do dono.

## Estado da frente (19/10/2026)

**Feito**
- `App\Contribua\Gateway\Stripe\`: `GatewayStripe` (código `stripe`), `ClienteStripe` (HTTP, versão da API
  fixa `2026-09-30.endive`), `TradutorStripe` (situação e avisos), `AssinaturaDoAvisoStripe` (Stripe-Signature).
- `tests/Contribua/Unit/Stripe/StripeContratoTest` (contrato comum) e `GatewayStripeTest` (bordas), com
  `MockHttpClient` e respostas dos exemplos da doc.
- `public/js/contribua-stripe.js`, variável global `ContribuaStripe` com `montar`, `tokenizar`, `desmontar`.
- Variáveis vazias no `.env`: `CONTRIBUA_STRIPE_SECRET_KEY`, `CONTRIBUA_STRIPE_PUBLIC_KEY`,
  `CONTRIBUA_STRIPE_WEBHOOK_SECRET` (lidas com `string:default::`, porque o build de produção apaga o `.env`).

**Pendente com a f6**
- 3DS: o contrato não tem como devolver ao navegador o `client_secret` da primeira fatura. Cartão que
  pede autenticação fica `incomplete` (Aguardando) e expira em 23 h (Cancelada). Proposto à f6: campo
  opcional em `SituacaoNoGateway` para a ação no navegador (`stripe.handleNextAction`).
- A f6 liga o JS no checkout e registra o adaptador pelo código (a tag já vem da interface).

**Para o dono**
1. Chaves: painel do Stripe, https://dashboard.stripe.com/apikeys. Copiar a "Chave publicável" (`pk_`) para
   `CONTRIBUA_STRIPE_PUBLIC_KEY` e criar uma "Chave restrita" (`rk_`) com escrita em Customers, Products,
   Subscriptions e PaymentMethods para `CONTRIBUA_STRIPE_SECRET_KEY`. Em teste, as de "área restrita".
2. Webhook: https://dashboard.stripe.com/webhooks, "Criar um destino de evento", "Sua conta", formato
   Snapshot, versão da API `2026-09-30.endive`; eventos `customer.subscription.created`,
   `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`,
   `invoice.payment_failed`, `invoice.payment_action_required`; URL `https://<domínio>/api/webhooks/stripe`.
   Na página do endpoint, "Revelar segredo" e copiar o `whsec_` para `CONTRIBUA_STRIPE_WEBHOOK_SECRET`.
3. Teste no sandbox (depois da f6 ligar o checkout): cartão `4242 4242 4242 4242` (aprovado),
   `4000 0000 0000 0002` (recusado), qualquer validade futura e qualquer CVC.

## Decisões (19/10/2026, sem o dono)

- Tokenização: Payment Element com `paymentMethodCreation: 'manual'` e `createPaymentMethod`; o servidor
  recebe o `pm_`. É o que o contrato da f6 pede (token no navegador, assinatura no servidor).
- Produto: um por plano, com id fixo `bluejus_espaco_<plano>`, criado na primeira vez; o preço vai na
  assinatura (`price_data`), então mudar o valor do plano não exige mexer no Stripe.
- `payment_behavior=allow_incomplete` (padrão do Stripe): recusa vira assinatura `incomplete`, não erro.
- Cancelar é imediato e sem reembolso proporcional; o espaço vale até `pagaAte`. Cancelar o que já está
  cancelado (4xx) é confirmado por consulta e vira sucesso.
- `pagaAte`: fim do período quando ativa; começo do período não pago quando inadimplente; fim do período
  quando cancelada com a última fatura paga; nada nos demais casos.
- Situações: `incomplete` Aguardando; `active`/`trialing` Ativa; `past_due`/`unpaid`/`paused` Inadimplente;
  `canceled`/`incomplete_expired` Cancelada; desconhecida é `FalhaNoGateway`.
- Aviso: assinatura v1 com tolerância de 5 minutos; reconsulta pela assinatura, referência ou cliente.
