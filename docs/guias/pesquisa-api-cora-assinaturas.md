# Pesquisa: API do Banco Cora para as mensalidades das assinaturas do BlueJus

Pesquisa de 03/11/2026. Só leitura e documento, sem código.
Legenda: **[DOC]** confirmado na documentação oficial (link ao lado); **[SUPOSIÇÃO]** não está na
documentação, é dedução ou fonte não oficial; **[NÃO ACHADO]** procurado e não encontrado.

## Resumo para o Samuel (10 linhas)

1. A Cora cobra por **boleto** e por **PIX com QR Code**. Pela API, ela **não cobra cartão** e **não tem PIX Automático**.
2. A Cora **não tem "assinatura"**: cada mensalidade é uma cobrança avulsa que o BlueJus gera todo mês.
3. Dá para saber se o cliente X pagou outubro: consultamos a cobrança pelo número dela ou listamos por CNPJ e situação.
4. Situações: aberta, paga, atrasada e cancelada. "Cartão recusado" não existe na Cora, porque ela não tem cartão.
5. A Cora avisa o BlueJus quando a cobrança é paga, cancelada ou vence (webhook). O aviso não traz dados nem assinatura, então sempre reconsultamos.
6. Multa, juros e desconto entram na cobrança. Não há "segunda via" nem troca de vencimento: cancela e emite outra.
7. Custos pela API: boleto pago R$ 1,70; PIX 1% até R$ 0,50; cancelar R$ 0,30; plano CoraPro R$ 44,90/mês obrigatório.
8. A spec diz que o PIX da Cora não tem tarifa. Isso está **errado**: pela API, o PIX custa 1% até R$ 0,50.
9. Recomendação: cartão recorrente no **Asaas**, que já está no master. PIX e boleto mensal na **Cora**, gerados por uma rotina nossa. Tudo cai no mesmo registro `pagamento`.
10. Você vai providenciar na conta Cora: o CoraPro ativo, uma chave PIX, a Integração Direta com certificado de teste e de produção, e uma segunda conta de teste.

## 0. O que já existe no repositório (não repetido aqui)

| Documento | O que já diz | Onde esta pesquisa muda |
|---|---|---|
| `docs/specs/contribua-planos-cora.md` D1 (linha ~500) | API da Cora só tem boleto, PIX e webhooks; cartão fica com gateway | Confirmado. Acrescenta o carnê (`/v2/invoices/installments`) e os avisos da Cora ao pagador |
| `docs/specs/contribua-planos-cora.md` linha ~463 | "URLs de produção: confirmar no painel" | Resolvido: `https://matls-clients.api.cora.com.br/` (seção 6) |
| `docs/specs/contribua-c4-cora.md` | PIX v2, token 24 h, webhook sem corpo, `POST /endpoints` só com `trigger: paid`, conciliação a cada 15 min | Assinar também `canceled` e `overdue`. Conciliar pela listagem com filtro (seção 4) |
| `docs/specs/gateway-pagamentos.md` linha ~26 | "sem tarifa de gateway sobre PIX recebido" | **Errado para a API**: 1% até R$ 0,50 por PIX, mais R$ 0,30 por cancelamento (seção 7) |
| `docs/specs/gateway-pagamentos.md` linha ~45 | devolução da Cora por "`invoice.refunded` ou equivalente" | Não existe evento de devolução de invoice na lista oficial (seção 4) |
| `docs/specs/gateway-pagamentos.md` linha ~98 x `contribua-c4-cora.md` linha ~66 | certificado "em arquivo montado" x "cifrado no banco" | Contradição continua. Recomendação na seção 9 |
| `docs/specs/gateway-m0b-webhook-conciliacao.md` | liga webhook e conciliação da Cora ao `Pagamento`, sem código | Status `LATE` é ambíguo na doc; tratar com cuidado (seção 3) |
| PR #152 (boleto de apoio, rascunho) | `payment_forms ['BANK_SLIP','PIX']`, sem multa e juros, dúvidas para o sandbox | Responde parte das dúvidas (seção 10) |
| PRs #92, #93, #94, #146 (Stripe, Mercado Pago, Pagar.me, rascunhos em conflito) e #95 (Asaas, no master) | Cartão recorrente fica com gateway de cartão | Confirmado: a Cora não substitui nenhum deles |

Fonte índice da documentação da Cora: https://developers.cora.com.br/llms.txt

## 1. Produtos da API

| Produto | Na API? | Como | Fonte |
|---|---|---|---|
| Boleto registrado | Sim [DOC] | `POST /v2/invoices` com `payment_forms: ["BANK_SLIP"]` | https://developers.cora.com.br/reference/emissão-de-boleto-registrado-v2 |
| Boleto com QR Code PIX | Sim [DOC] | mesmo endpoint, `["BANK_SLIP","PIX"]`. Exige chave PIX na conta; sem ela, sai sem QR. Pago por PIX, o código de barras é baixado | mesma |
| QR Code PIX com vencimento | Sim [DOC] | mesmo endpoint, só `["PIX"]`. Tem `due_date`, aceita multa, juros e desconto. Resposta traz `pix.emv` (copia e cola) | https://developers.cora.com.br/reference/qr-code-pix-v2 |
| PIX imediato (sem vencimento) | [NÃO ACHADO] | O PIX da Cora é sempre "cobrança com vencimento" | mesma |
| Carnê (boletos parcelados) | Sim [DOC] | `POST /v2/invoices/installments`, 2 a 24 parcelas, só boleto (PIX dá 400) | https://developers.cora.com.br/reference/emissão-de-boleto-parcelado-v2 |
| PIX Automático | **Não** [NÃO ACHADO] | Só aparece no blog, sem endpoint | https://www.cora.com.br/blog/pix-automatico/ |
| Débito automático | **Não** [NÃO ACHADO] | | llms.txt |
| Cartão de crédito (avulso ou recorrente) | **Não** [DOC por ausência] | O link de pagamento com cartão existe só no aplicativo, avulso | https://www.cora.com.br/ajuda/artigo/e-possivel-receber-em-sua-conta-cora-atraves-de-um-link-de-pagamentos/ |
| Outros | Sim [DOC] | transferências, pagamento de contas (DARF, GPS), extrato, saldo, nota fiscal de serviço (beta) | llms.txt |

**Integração Direta x Parceria** [DOC] (https://developers.cora.com.br/docs/instrucoes-iniciais , https://www.cora.com.br/integracoes/):

- **Integração Direta**: a empresa cobra para o próprio negócio. Certificado e chave privada gerados no app ou no Cora Web, mTLS em toda chamada, token por `client_credentials`. Exige CoraPro. **É o caso do BlueJus.**
- **Parceria**: ERP ou BPO que opera a conta dos clientes dele (OAuth `authorization_code`, `client-id` e `client-secret`, base `api.cora.com.br`). Aprovação em até 3 dias úteis. Só serviria se cada escritório cobrasse pela própria Cora, o que não é o caso das mensalidades.

**Cartão recorrente: alternativa.** Gateway de cartão. O Asaas já está no master (#95), com checkout recorrente e NFS-e. Stripe, Mercado Pago e Pagar.me estão em rascunho (#92 a #94).

## 2. Recorrência e assinatura

- Não existe objeto de assinatura nem de plano. Cada mensalidade é uma invoice avulsa. [DOC por ausência, llms.txt]
- Existe o status `RECURRENCE_DRAFT` ("criado com recorrência"), mas nenhum endpoint de recorrência documentado. Provavelmente é a recorrência criada pelo app. [SUPOSIÇÃO] https://developers.cora.com.br/reference/consultar-boletos-v2
- O carnê serve para um número fixo de meses (até 24), só por boleto, sem PIX. Só a 1ª parcela é registrada na hora; as outras em cerca de 1 minuto. A resposta pode levar até ~40 s. [DOC] Não serve bem para assinatura mensal sem prazo, nem para quem paga por PIX.
- **Cobrança automática**: não existe. Sem PIX Automático e sem débito automático, o cliente precisa pagar cada mês (boleto ou QR). O mais perto disso é a Cora mandar o boleto por e-mail ou SMS antes do vencimento (seção 5).

**Consequência**: o BlueJus é o dono da recorrência. Uma rotina agendada gera a mensalidade de cada assinatura alguns dias antes do vencimento. Usa `code` = id interno e uma `Idempotency-Key` fixa por (assinatura, competência), assim rodar duas vezes não cobra duas vezes.

## 3. Acompanhamento por cliente

- **Uma cobrança**: `GET /v2/invoices/{invoice_id}` [DOC] https://developers.cora.com.br/reference/consultar-detalhes-de-um-boleto-v2
- **Não dá para buscar pelo nosso `code`** [DOC por ausência]. Guardar o `inv_...` da Cora junto da mensalidade no nosso banco é obrigatório.
- **Listagem** `GET /v2/invoices/` [DOC] https://developers.cora.com.br/reference/consultar-boletos-v2
  - `start` e `end`: por vencimento; com `state=PAID`, pela data do pagamento.
  - `state`: situação.
  - `search`: CPF ou CNPJ do pagador. Serve de **histórico por cliente**.
  - `page` e `perPage` (máximo 200).
  - Cada item traz `id, status, total_amount, total_paid, customer_document, due_date, payment_form, paid_at, fine_paid, interest_paid, discount_paid`.
- **"O cliente X pagou outubro?"**: pelo nosso banco (mensalidade de outubro → `inv_...` → `GET`). Na Cora direto: `search=<CNPJ>` com `start`/`end` do mês.
- **Status** [DOC]: `DRAFT, OPEN, IN_PAYMENT, PAID, LATE, CANCELLED, RECURRENCE_DRAFT, INITIATED`.
  - `LATE` é descrito de dois jeitos na própria doc ("pago após o vencimento" e "em atraso"). **Confirmar no stage** antes de usar `LATE` para marcar inadimplente. A conta segura é: não está `PAID` e `due_date` já passou.
  - Detalhe do pagamento em `payments[]`: `method` (BANK_SLIP ou PIX), `fine`, `interest`, `finalized_at`.
- **Cartão recusado e motivo**: não se aplica à Cora. Vem do gateway de cartão (Asaas).

Mapeamento sugerido para o `pagamento` (status de `docs/specs/gateway-pagamentos.md`):

| Cora | `pagamento.status` |
|---|---|
| `DRAFT`, `OPEN`, `IN_PAYMENT`, `INITIATED` | `aguardando` |
| `PAID` | `paga` (valor pago diferente do esperado: `divergente`) |
| `LATE` sem pagamento | `aguardando` + vencida (a assinatura decide a inadimplência) |
| `CANCELLED` | `cancelada` |

## 4. Webhooks

- **Cadastro**: `POST /endpoints/` com `{url, resource, trigger}` e `Idempotency-Key`; também listar e excluir. [DOC] https://developers.cora.com.br/reference/criação-de-endpoints
- **Eventos de `invoice`**: `drafted`, `created`, `paid`, `canceled`, `overdue` ou `*`. [DOC] (Outros recursos: `transfer`, `payment`, `register`, `service_receipt`.)
  - **Mudança na spec C4**: hoje assinamos só `paid`. Para assinaturas, assinar também `canceled` e `overdue`.
  - **Não há evento de devolução** de invoice. A linha da `gateway-pagamentos.md` que supõe `invoice.refunded` precisa ser revista. Devolução de PIX (MED) pela API: [NÃO ACHADO].
- **Formato** [DOC] https://developers.cora.com.br/reference/exemplo-de-post-da-notificação
  - Corpo vazio (`content-length: 0`). Dados só nos cabeçalhos: `webhook-event-id` (`evt_...`), `webhook-event-type` (ex.: `invoice.paid`), `webhook-resource-id` (`inv_...`), `user-agent: Cora-Webhook`.
- **Segurança**: sem assinatura HMAC, sem mTLS de volta, sem lista de IPs publicada. [NÃO ACHADO] Por isso:
  1. O aviso nunca é a prova. É só um gatilho para `GET /v2/invoices/{id}` com mTLS. (Já é a regra da C4.)
  2. URL com segredo longo e aleatório no caminho, trocável.
  3. Limite de requisições na rota pública (C4 e M0b já levantam isso).
  4. Deduplicar por `webhook-event-id` (`NotificacaoRecebida` com `idEvento` único já faz).
- **Reenvio e tentativas**: [NÃO ACHADO]. A resposta do cadastro mostra `connectionTimeout 1000` e `readTimeout 2000` (sem unidade; [SUPOSIÇÃO] milissegundos). Responder 200 rápido e processar depois.
- **Conciliação**: como não há garantia de reenvio, a rotina de conciliação é obrigatória.
  - Um dia: `GET /v2/invoices/?state=PAID&start=<ontem>&end=<hoje>` pega todo pagamento pela data do pagamento, sem varrer cobrança por cobrança.
  - Mais as nossas mensalidades `aguardando` vencidas, consultadas uma a uma.

## 5. Inadimplência, multa, juros, desconto, segunda via e baixa

- **`payment_terms`** [DOC] (emissão de boleto v2)
  - `due_date`: não aceita data passada.
  - `fine`: `amount` em centavos tem precedência sobre `rate` (0 a 100); `date` padrão é vencimento + 1.
  - `interest.rate`: 0 a 100. [SUPOSIÇÃO] taxa mensal; confirmar no stage.
  - `discount`: `FIXED` ou `PERCENT`, vale até a véspera do vencimento.
  - Valor mínimo: R$ 5,00 (`500`).
  - Limites legais (multa de até 2% e juros de até 1% ao mês em relação de consumo): [SUPOSIÇÃO jurídica, não é da Cora].
- **Saber em tempo real quem está inadimplente**: evento `invoice.overdue` mais a regra nossa (carência D3 de 7 dias da spec mãe). A situação "inadimplente" mora na **assinatura do BlueJus**, não na Cora.
- **Segunda via e troca de vencimento**: não há endpoint. [NÃO ACHADO] Caminho: `DELETE /v2/invoices/{id}` (204; já paga devolve 422 `REC-0006`) e emitir outra. Custa R$ 0,30 por cancelamento. https://developers.cora.com.br/reference/cancelamento-de-boleto-v2
  - [SUPOSIÇÃO] Para boleto já vencido, a multa e os juros são calculados pelo banco do pagador, e o mesmo boleto continua pagável. Segunda via só é necessária se quisermos um novo vencimento.
- **Baixa automática**: a Cora disse em 2021 (Reclame Aqui) que baixa 90 dias após o vencimento. [SUPOSIÇÃO, fonte antiga e não oficial] Melhor cancelar nós mesmos, quando a assinatura for encerrada.
- **Avisos da Cora ao pagador** [DOC]: `notification.channels` com `EMAIL` ou `SMS` (SMS exige CoraPro). Regras 15/10/7/5/2 dias antes, no dia, 2 a 15 dias depois e "quando pago". Sem WhatsApp. Status do envio em `GET /v2/invoices/{id}/notifications`. https://developers.cora.com.br/reference/edição-de-notificações-do-boleto-copy
  - Decisão para o BlueJus: usar o e-mail do próprio BlueJus (marca, cota da Hostinger já controlada) **ou** deixar a Cora mandar. Os dois juntos duplicam o aviso.

## 6. Autenticação e segurança

- **Bases (Integração Direta)** [DOC] https://developers.cora.com.br/docs/utilização-das-apis
  - stage `https://matls-clients.api.stage.cora.com.br/`
  - produção `https://matls-clients.api.cora.com.br/`
  - Atenção: as páginas de referência mostram exemplos em `api.stage.cora.com.br` (que é da Parceria). Na Direta vale `matls-clients.*`.
- **Token** [DOC] https://developers.cora.com.br/docs/client-credentials-int-direta
  - `POST /token`, form-urlencoded, `grant_type=client_credentials&client_id=...`, com certificado.
  - `expires_in` 86400 (24 h), sem refresh. Já implementado (cache de 23 h).
- **Certificado**: um para stage e outro para produção [DOC]. Validade e rotação: [NÃO ACHADO]. Precisamos de um alerta de validade lendo a data do próprio certificado.
- **Escopos**: não há escopos documentados para a Integração Direta. [NÃO ACHADO] O certificado dá acesso a tudo da conta, inclusive **transferências e pagamentos**. Por isso o certificado é o segredo mais sensível do sistema: quem o tem pode tirar dinheiro da conta.
- **`Idempotency-Key`**: UUID obrigatório em todo `POST`; sem ele, 400. [DOC] Por quanto tempo a chave vale: [NÃO ACHADO].
- **Sandbox**: `POST /v2/invoices/pay` simula o pagamento e dispara `paid`. Não paga cobrança da própria conta (`REC-0007`), então são **duas contas de teste**. [DOC] https://developers.cora.com.br/reference/pagar-boleto-em-stage
- **Limites de uso (rate limit)**: [NÃO ACHADO]. Emitir em lote com espaçamento.
- **LGPD**: os termos remetem à política de privacidade; a guarda da credencial é nossa (cláusulas 7.4 e 9.3). Mandamos à Cora nome, CPF/CNPJ e e-mail do cliente. Isso deve constar na política de privacidade do BlueJus como operador de pagamento. [DOC] https://www.cora.com.br/termos-e-condicoes-de-apis/ (atualizados em 11/06/2026)
- **PCI**: não se aplica à Cora (sem cartão). No cartão, o PCI fica com o gateway desde que o BlueJus nunca receba o número do cartão (checkout ou token do gateway, como já é no Asaas).
- **Suporte**: a Cora não se obriga a dar suporte (cláusula 3.3); contato `suporteapi@cora.com.br`. [DOC]

## 7. Custos (termos de API, 11/06/2026) [DOC]

| Item | Tarifa |
|---|---|
| Plano CoraPro (obrigatório na Integração Direta) | R$ 44,90/mês |
| Boleto pago pelo código de barras | R$ 1,70 |
| Boleto pago pelo QR PIX | 1%, no máximo R$ 0,50 |
| QR Code PIX | 1% até R$ 49,99; R$ 0,50 a partir de R$ 50 |
| Cancelamento de boleto ou QR criado pela API | R$ 0,30 |
| TED | R$ 2,00 |
| Cartão | não existe na API |

Fontes: https://www.cora.com.br/termos-e-condicoes-de-apis/ e https://www.cora.com.br/integracoes/.
A página de tarifas `cora.com.br/tarifas` deu 404 na consulta. A franquia de 100 boletos grátis por mês citada no blog é do aplicativo; os termos de API não a citam. [SUPOSIÇÃO: não vale para a API]

Exemplo: mensalidade de R$ 100 paga por PIX custa R$ 0,50; por boleto, R$ 1,70.

## 8. Lacunas da Cora para um sistema de assinaturas

| Falta na Cora | Como suprir |
|---|---|
| Objeto de assinatura e plano | Assinatura, plano e vigência no BlueJus (`AssinaturaDeEspaco`, já previsto) e rotina mensal que emite a cobrança |
| Cartão recorrente | Asaas (já no master, #95) |
| PIX Automático e débito automático | Não há na Cora. Se for desejado: outro gateway que já ofereça PIX Automático [SUPOSIÇÃO: pesquisar à parte] |
| Busca pelo nosso código | Guardar `inv_...` ↔ mensalidade no `pagamento` (`PagamentoRepository::porIdExterno`) |
| Webhook assinado e reenvio garantido | Reconsulta mTLS + deduplicação + conciliação diária pela listagem |
| Segunda via e troca de vencimento | Cancelar e emitir outra |
| Evento de devolução | Conciliação pelo extrato, se surgir caso real |
| Nota fiscal da mensalidade | NFS-e da Cora está em beta; o Asaas já emite. [SUPOSIÇÃO] manter a nota num lugar só |
| Limites e validade do certificado | Espaçar emissões; alerta de validade do certificado |

## 9. Recomendação de arquitetura

**Dois provedores, um registro.**

1. **Assinatura e recorrência no BlueJus.** A `AssinaturaDeEspaco` guarda plano, forma de pagamento escolhida, vigência e situação (aguardando, ativa, inadimplente, cancelada). Nenhum provedor é dono da recorrência do PIX ou boleto.
2. **Cartão no Asaas** (recorrência do próprio Asaas, cartão recusado com motivo, NFS-e). Cada cobrança do Asaas vira um `pagamento` (etapa M4 da `gateway-pagamentos.md`).
3. **PIX e boleto na Cora**: uma rotina diária emite a mensalidade X dias antes do vencimento, com `payment_forms ["BANK_SLIP","PIX"]` (o cliente escolhe como pagar), multa e juros conforme regra do Farlei, `code` = id do `pagamento`, `Idempotency-Key` derivada de (assinatura, competência), como o #152 já faz por HMAC.
4. **Registro único `pagamento`** (M0, já no master) com `provedor` `cora` ou `asaas` e o id externo. A pergunta "o cliente X pagou outubro?" é respondida só pelo nosso banco.
5. **Confirmação**: webhook como gatilho (Cora: `paid`, `canceled`, `overdue`), sempre com reconsulta, e conciliação diária pela listagem `state=PAID` (M0b). Inadimplência = regra nossa sobre o vencimento mais a carência.
6. **Segurança**
   - Certificado e chave **só da conta do BlueJus**, nunca no repositório nem em log. Resolver a contradição das specs: recomendo manter o `CofreDeCertificado` já existente (cifrado no banco com `CONTRIBUA_CRYPTO_KEY`), porque já funciona no master e permite trocar o certificado pela tela do Master sem deploy.
   - Alerta de vencimento do certificado.
   - Rota do webhook com segredo no caminho e limite de requisições.
   - Registrar a Cora e o Asaas como operadores na política de privacidade.

**O que o Samuel precisa providenciar na conta Cora**

1. Plano **CoraPro** ativo na conta PJ do BlueJus (R$ 44,90/mês).
2. **Chave PIX** cadastrada na conta (sem ela o boleto sai sem QR).
3. No app ou no Cora Web: **Integração Direta**, gerar o **certificado e a chave de stage** e anotar o **client_id**.
4. Uma **segunda conta de teste** no stage, para simular pagamento (a Cora não deixa a conta pagar a si mesma).
5. Depois dos testes: **certificado e client_id de produção**, entregues pela tela do Master (nunca por chat ou e-mail).
6. Regras do Farlei: valor de multa e juros, dias de antecedência da emissão, carência antes de bloquear, e quem manda o aviso (BlueJus ou Cora).

## 10. Dúvidas do PR #152 respondidas pela documentação

| Dúvida | Resposta |
|---|---|
| `pix.emv` vem quando a conta tem chave PIX? | Sim; sem chave PIX, o boleto sai sem QR [DOC] |
| `Idempotency-Key` repetida devolve a mesma fatura? Por quanto tempo? | Obrigatória [DOC]; comportamento na repetição e prazo [NÃO ACHADO], testar no stage |
| Aceita boleto sem `fine`, `interest`, `notifications`? | A doc os trata como opcionais [DOC]; confirmar no stage |
| `invoice.paid` do boleto chega ao mesmo endpoint do PIX? | O endpoint é por recurso `invoice`, não por forma de pagamento [DOC]: sim |
| 20 s bastam para emitir? | Boleto avulso: provavelmente sim [SUPOSIÇÃO]. Carnê pode levar ~40 s [DOC] |

Divergências da própria documentação, a conferir no stage: `customer.email` opcional na tabela e obrigatório no OpenAPI; cancelamento 204/422 na tabela e 200/400 no OpenAPI; significado de `LATE`.
