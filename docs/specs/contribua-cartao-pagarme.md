# Contribua: adaptador de cartão do Pagar.me

Desenho: "08 - Contribua - VS 18.10.26" (assinatura de espaço com cartão; "Link do pacote" fixo como
alternativa). Contrato: `App\Contribua\Gateway\GatewayDeAssinatura` e `TesteDeContratoDoGatewayDeAssinatura`
da contribua-c3 (f6). Risco: BAIXO no código (sem banco); a cobrança real depende das chaves do dono.

## Estado da frente (19/10/2026)

**Feito**
- `App\Contribua\Gateway\Pagarme\`: `GatewayPagarme` (código `pagarme`), `ClientePagarme` (HTTP v5),
  `TradutorPagarme` (situação pelas faturas), `AssinaturaDoAvisoPagarme` (RS256 com JWKS) e
  `ChavePublicaRsa` (PEM a partir do JWK).
- `tests/Contribua/Unit/Pagarme/PagarmeContratoTest` (contrato comum) e `GatewayPagarmeTest` (bordas), com
  `MockHttpClient`, os exemplos da doc e um par de chaves RSA gerado no próprio teste.
- `public/js/contribua-pagarme.js`, variável global `ContribuaPagarme` com `montar`, `tokenizar`,
  `concluirAcao` (nada a fazer) e `desmontar`. Os campos do cartão são da página (o Pagar.me não tem
  iframe) e vão direto ao Pagar.me; o servidor só recebe o `token_`.
- Variáveis vazias no `.env`: `CONTRIBUA_PAGARME_SECRET_KEY` e `CONTRIBUA_PAGARME_PUBLIC_KEY`.

**Não confirmado na doc (testar no sandbox do dono)**
- A página "Criar assinatura avulsa" traz o esquema da assinatura de plano por engano: o corpo segue o objeto
  `subscription`, os exemplos e o SDK PHP oficial (`card_token` na raiz).
- Endereço de cobrança e telefone: a doc diz que o endereço não vai no token e que conta PSP exige telefone.
  O `PedidoDeCheckout` não traz nenhum dos dois; se o sandbox recusar, a f6 acrescenta e o adaptador envia.
- Assinatura do webhook (`X-Webhook-Asymmetric-Signature`, RS256, JWKS em `hubapi.pagar.me`): vem do código
  oficial dos plugins, não da docs.pagar.me. Sem o cabeçalho, o aviso é recusado.
- Ambiente de teste: a doc fala em `api.pagar.me` (chave de teste) e em `sdx-api.pagar.me`; usamos o primeiro.

**Para o dono**
1. Chaves: Dash em https://id.pagar.me, menu "Desenvolvimento" > "Chaves". A "Chave pública" (`pk_`) para
   `CONTRIBUA_PAGARME_PUBLIC_KEY` e a "Chave secreta" (`sk_`) para `CONTRIBUA_PAGARME_SECRET_KEY`. O modo
   teste ou produção da Dash mostra as chaves do ambiente.
2. Domínio: cadastrar o domínio do BlueJus nas configurações da conta (sem isso o navegador não consegue
   gerar o token).
3. Cartão de crédito: "Configurações" > "Meios de pagamento" > Cartão de crédito > "Editar", ligar "Status".
4. Webhook: "Configurações" > "Webhooks" > "Criar webhook". URL `https://<domínio>/api/webhooks/pagarme`,
   eventos `subscription.created`, `subscription.canceled`, `invoice.paid`, `invoice.payment_failed`,
   `charge.paid`, `charge.payment_failed`.
5. Teste: cartão `4000 0000 0000 0010` (aprovado), `4000 0000 0000 0028` (recusado), qualquer validade
   futura e qualquer CVV.
6. NFS-e: pendência do adaptador (a interface opcional da f6 é só do Asaas hoje).

## Decisões (19/10/2026, sem o dono)

- Assinatura avulsa mensal pré-paga (`billing_type=prepaid`), em centavos, cliente PF ou PJ pelo tamanho do
  documento, referência em `code` e em `metadata`.
- `iniciar` confere a credencial (`GET /customers` do documento) antes de abrir o formulário.
- Situação pelas faturas dos últimos 75 dias: `future` Aguardando; `active` sem fatura paga Aguardando (a
  primeira recusa não é inadimplência de quem nunca pagou), já pago com a última fatura `failed`
  Inadimplente, senão Ativa (renovação pendente não tira o mês pago); `canceled`, `failed` e `chargedback`
  Cancelada; desconhecida é `FalhaNoGateway`. (Revisão de 19/10.)
- `pagaAte`: fim do ciclo da última fatura paga; sem o ciclo na fatura, um mês depois da cobrança dela (não o
  ciclo vigente, que pode não estar pago); estorno contestado (`chargedback`), nada.
- `assinarComToken`: se a leitura das faturas falhar depois de criar a assinatura, volta Aguardando com o id
  (não falha): falhar faria o escritório tentar de novo com outro token e assinar duas vezes. O POST não tem
  chave de idempotência documentada; a C3 trava o pedido (FOR UPDATE) e barra outro checkout por 60 min.
- Aviso: chaves do JWKS por 6 h no cache; `kid` desconhecido busca de novo, no máximo a cada 5 minutos (aviso
  forjado não esvazia o cache nem martela o Pagar.me); `kid` conhecido com assinatura errada é recusado sem
  busca. JWKS fora do ar é `FalhaNoGateway` (a C3 responde 503 e o Pagar.me reenvia), não aviso falso.
- Branch atualizada com a `contribua-c3` (merge do master e os commits da c3, um a um).
- Cancelar: `DELETE` com `cancel_pending_invoices=true`; já cancelada (4xx) é confirmada por consulta.
