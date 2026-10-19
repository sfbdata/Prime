# Contribua: adaptador de cartão do Mercado Pago

Desenho: "08 - Contribua - VS 18.10.26" (assinatura de espaço com cartão; "Link do pacote" fixo como
alternativa). Contrato: `App\Contribua\Gateway\GatewayDeAssinatura` e `TesteDeContratoDoGatewayDeAssinatura`
da contribua-c3 (f6). Risco: BAIXO no código (sem tela, sem banco); a cobrança real depende das chaves do dono.

## Estado da frente (19/10/2026)

**Feito**
- `App\Contribua\Gateway\MercadoPago\`: `GatewayMercadoPago` (código `mercado_pago`), `ClienteMercadoPago`
  (HTTP), `TradutorMercadoPago` (situação), `AssinaturaDoAvisoMercadoPago` (x-signature).
- `tests/Contribua/Unit/MercadoPago/MercadoPagoContratoTest` (contrato comum) e `GatewayMercadoPagoTest`
  (bordas), com `MockHttpClient` e os exemplos da referência.
- `public/js/contribua-mercado-pago.js`, variável global `ContribuaMercadoPago` com `montar`, `tokenizar`,
  `concluirAcao` (nada a fazer) e `desmontar`. Card Payment Brick só de crédito, com o botão escondido.
- Variáveis vazias no `.env`: `CONTRIBUA_MERCADO_PAGO_ACCESS_TOKEN`, `CONTRIBUA_MERCADO_PAGO_PUBLIC_KEY`,
  `CONTRIBUA_MERCADO_PAGO_WEBHOOK_SECRET`.

**Não confirmado na doc (testar no sandbox do dono)**
- Grafia de cancelada (`canceled` na doc; `cancelled` também é aceito).
- Se o `/preapproval` respeita o `X-Idempotency-Key` (mandamos mesmo assim).
- Corpo literal e `x-signature` dos avisos de assinatura (a doc só traz o de `payment`). A conferência usa o
  `data.id` do corpo, porque o contrato não entrega a query da URL; o Mercado Pago assina o da query, que é o
  mesmo recurso.

**Para o dono**
1. Credenciais: https://www.mercadopago.com.br/developers/panel/app, a aplicação, menu "Produção" >
   "Credenciais de produção" (ou "Testes" > "Credenciais de teste"). "Public Key" para
   `CONTRIBUA_MERCADO_PAGO_PUBLIC_KEY` e "Access Token" para `CONTRIBUA_MERCADO_PAGO_ACCESS_TOKEN`. Em
   produção é preciso "Ativar credenciais de produção" antes.
2. Webhook: na mesma aplicação, "Webhooks" > "Configurar notificações". URL `https://<domínio>/api/webhooks/mercado_pago`,
   eventos "Planos e assinaturas" e "Pagamentos", "Salvar". Revelar a "Assinatura secreta" e copiar para
   `CONTRIBUA_MERCADO_PAGO_WEBHOOK_SECRET`.
3. Teste: cartão Mastercard `5480 8328 0103 3311`, CVV `123`, validade `11/30`, nome do titular `APRO`
   (aprovado) ou `OTHE` (recusado), CPF `12345678909`. Credencial de teste e e-mail de usuário de teste
   misturados com conta real dão "Invalid users involved".
4. NFS-e: o Mercado Pago não emite; pendência do adaptador (a interface opcional da f6 é só do Asaas hoje).

## Decisões (19/10/2026, sem o dono)

- Assinatura sem plano (`POST /preapproval` com `status=authorized` e `card_token_id`), mensal, em reais,
  sem `start_date`/`end_date` (a doc diz que o início só vale com o fim, e a assinatura não tem fim).
- `iniciar` confere a credencial (`GET /users/me`) antes de abrir o formulário.
- Situação: `pending` Aguardando; `authorized` com semáforo amarelo ou vermelho Inadimplente, com cobrança
  feita Ativa, sem cobrança ainda Aguardando (a primeira sai até 1 h depois); `paused` Inadimplente;
  `canceled`/`cancelled` Cancelada; desconhecida é `FalhaNoGateway`.
- `pagaAte`: ativa até `next_payment_date`; inadimplente ou cancelada, um período depois de
  `summarized.last_charged_date` (inferência: a doc não tem campo "pago até").
- Aviso de fatura (`subscription_authorized_payment`): a assinatura vem de `GET /authorized_payments/{id}`;
  se o Mercado Pago não responder, `FalhaNoGateway` (ele reenvia o aviso). `payment` e o resto: null.
- Sem janela de tempo no aviso: o Mercado Pago reenvia por horas, e o Contribua ignora o repetido e reconsulta.
- Cancelar: `PUT` com `status=canceled`; já cancelada (4xx) é confirmada por consulta e vira sucesso.
