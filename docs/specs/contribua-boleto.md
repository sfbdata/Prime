# Contribua: boleto de apoio (chave "Boleto" do desenho 08, só com a Cora)

**Risco:** MÉDIO (dinheiro, documento do pagador) · **Frente:** `contribua-boleto` · **Data:** 20/10/2026

## Estado da frente

- 20/10: implementada na branch `contribua-boleto`, empilhada sobre `contribua-bancos` (PR #123). Migration
  `Version20261021170000` (horário reservado pela coordenação, depois da `Version20261021160000` da C4b).
- **Falta:** revisão, conferência na tela pelo dono e o teste no sandbox da Cora (lista em "Para o dono").

## O pedido

Desenho `docs/design/redesign-19-10-26/08 - Contribua -  VS 18.10.26.dc.html`, L253: a chave `bol` "Boleto", "Opção de
contribuir por boleto bancário" (padrão desligado, L249), na Integração bancária. O desenho **não mostra** a tela do
boleto: onde ele é omisso vale a convenção do sistema, ou seja, o visual da própria seção Apoie (L54-77) e o campo de
documento do checkout da C3.

## Contrato (do orquestrador)

- Só com a Cora. Com Inter, BB ou Outro, a chave Boleto aparece desabilitada na Integração bancária, com a nota
  "Disponível só com a Cora". Chave desligada ou integração não conectada: nada de boleto na tela.
- Seção Apoie, com a chave ligada e a Cora conectada: botão "Gerar boleto" (`bi-upc`) abaixo dos valores; usa o valor
  escolhido (20/50/100/Outro); abaixo de R$ 5,00, "O boleto tem valor mínimo de R$ 5,00."; pede o CPF/CNPJ do pagador,
  pré-preenchido com o do escritório (o mesmo do checkout da C3: `Tenant::getCnpj()` só com dígitos); e-mail de quem
  está logado; nome do escritório cortado em 60; vencimento em 3 dias corridos.
- Resultado: linha digitável com o botão de copiar (padrão dos botões de copiar do PIX), link "Abrir boleto (PDF)" em
  nova aba com `rel="noopener noreferrer"`, o vencimento e o "Pix copia e cola" do boleto, se vier. Sem `|raw`, sem travessão.
- Persistência na entidade das cobranças PIX por conta (`CobrancaPixDaConta`, `contribua_cobranca_pix`). Webhook,
  reconsulta e conciliação valem para o boleto: fatura PAID confirma o apoio.
- Um boleto aberto por escritório e valor no dia; pedir de novo devolve o mesmo, sem outra fatura na Cora;
  `Idempotency-Key` derivada do pedido.
- Documento e e-mail só para a Cora; nada de documento em log. Mesma porta do PIX dinâmico.
- Falha da Cora: "Não foi possível gerar o boleto agora. Tente de novo em instantes.", sem linha e sem o corpo do erro.

## API da Cora (developers.cora.com.br, "Emissão de boleto registrado (v2)")

`POST /v2/invoices/` com `Idempotency-Key` (UUID), mTLS e Bearer. Corpo enviado:

```json
{
  "code": "BJ<conta>-B<10 hex>",
  "customer": {"name": "<escritório, 60>", "email": "<quem pede>", "document": {"identity": "<só números>", "type": "CPF|CNPJ"}},
  "services": [{"name": "Apoio ao BlueJus", "description": "Contribuição voluntária, conta <code>", "amount": <centavos>}],
  "payment_terms": {"due_date": "AAAA-MM-DD"},
  "payment_forms": ["BANK_SLIP", "PIX"]
}
```

Sem `fine` e sem `interest`. Resposta lida: `id`, `payment_options.bank_slip.digitable`, `.url` (só aceita `https://`),
`pix.emv` (pode vir nulo). A consulta (`GET /v2/invoices/{id}`) é a mesma da cobrança PIX.

## Decisões

| # | Decisão | Por quê |
|---|---|---|
| B1 | Interface nova `BancoBoleto` (`emitirBoleto(PedidoDeBoleto): BoletoEmitido`), implementada só pela `BancoCora`. `BancoPix` e `GatewayDeAssinatura` não mudam | acréscimo opcional: Inter, BB e Outro seguem iguais; "só Cora" vira `instanceof BancoBoleto` |
| B2 | Colunas novas: `forma` (`pix` padrão, `boleto`), `linha_digitavel` VARCHAR(60), `url_do_boleto` TEXT. **Sem coluna `vencimento` nova**: a tabela já tem `vencimento` (DATE), que o boleto usa. O Pix do boleto vai no `copia_e_cola` que já existe | o contrato pedia `vencimento`, mas ela já existia; duplicar seria ruído |
| B3 | A linha do boleto só é gravada **depois** de a Cora responder (`CobrancaPixDaConta::boletoEmitido`). Falha não deixa linha | contrato ("sem criar a linha") |
| B4 | Idempotência: (a) trava `pg_advisory_xact_lock(80801, tenant)` numa transação em volta de "procurar o boleto do dia, ou emitir e gravar" (`CobrancaPixDaContaRepository::comTravaDeBoleto`); (b) a `Idempotency-Key` (UUID v4) e o `code` saem de um HMAC-SHA256 com o `kernel.secret` sobre escritório, banco, ambiente, valor, dia, documento e o n-ésimo boleto do dia | a cobrança PIX não tem trava (só o reaproveitamento); com a linha nascendo só depois da resposta, um retry depois de timeout precisa da mesma chave **e** do mesmo código, senão a fatura que a Cora criou não seria reconhecida. O HMAC não deixa o documento recuperável a partir da chave gravada |
| B5 | "Do dia" = aberta e criada hoje, no mesmo banco, ambiente e valor. Se a Cora devolver pela chave uma fatura que já é nossa (`doBanco`), ela é devolvida sem outra linha | contrato |
| B6 | Limite de 5 boletos novos por escritório por dia (cada forma tem o seu limite; `criadasDesde` ganhou o filtro de forma, PIX por padrão) | o boleto não pode gastar o limite de 10 PIX do dia, e vice-versa |
| B7 | `abertaParaReusar` (PIX dinâmico) passa a filtrar `forma = pix` | sem isso o PIX do mesmo valor podia servir (ou tentar registrar de novo) uma linha de boleto |
| B8 | Porta: a do PIX dinâmico. Qualquer pessoa logada num escritório; no ambiente de testes (sandbox) só o escritório do Master. CSRF da página (`contribua`) | contrato |
| B9 | Respostas: indisponível (chave, conexão, banco, sandbox) = **404**; valor, documento ou limite = **422**; falha da Cora = **503** com a mensagem honesta; sem CSRF = 403 | 404 porque, para quem pede, o recurso não existe (o botão nem aparece) |
| B10 | Documento validado com `DigitoVerificador` (CPF ou CNPJ com dígito) antes de ir à Cora | a Cora devolve erro genérico para documento inválido |
| B11 | Log da falha: só a conta e o motivo do banco, com sequências longas de números mascaradas (`[número]`) | o motivo da Cora pode ecoar o documento |
| B12 | Tela: parcial próprio `contribua/_boleto.html.twig`, incluído no fim da coluna do QR (`.ct-apoie-qr`), abaixo dos valores e do "Copiar código PIX"; JS e CSS próprios (`contribua-boleto.js`, `contribua-boleto.css`); dados pelo `contribua_boleto()` (`BoletoRuntime`, carregado só quando a página usa). O `index.html.twig` da f6 ganhou só a linha do include | mesmo padrão dos parciais da C4; não mexe no `contribua.js`, no `contribua.css` nem no `ContribuaController` |
| B13 | Fluxo na tela: "Gerar boleto" abre o pedido (CPF/CNPJ e "Gerar boleto de R$ X"); o resultado troca o pedido pela linha digitável, o vencimento, "Copiar linha digitável", "Abrir boleto (PDF)" e, se veio, o "Pix copia e cola do boleto" com o botão de copiar | o desenho é omisso; segue os botões `ct-botao-contorno`, o rótulo pequeno e a nota da seção Apoie |
| B14 | `SolicitanteAtual` (serviço) monta o `Solicitante` da sessão como o `ContribuaController` faz | o controller da f6 não é tocado |
| B15 | Chave desabilitada com outro banco: só na tela (template e `contribua-cora.js` ao trocar o banco). O valor gravado da chave não é apagado; sem Cora ele não tem efeito (B1) | voltar para a Cora recupera a escolha do Master |

## Confirmação (sem mudança de código)

O webhook da Cora (`invoice.*`), a reconsulta (`ReconsultarCobrancaNoBancoHandler` → `ConfirmarCobrancaPeloBancoUseCase`) e a
conciliação (`app:contribua:conciliar`, `abertasDesde` sem filtro de forma) tratam o boleto como qualquer cobrança da
tabela: `GET /v2/invoices/{id}`, PAID com o nosso `code` confirma o apoio (txid do apoio = código do boleto). O
escritório vem da própria linha da cobrança; a consulta da conciliação é de todas as contas por desenho (a conta que
recebe é a da BlueJus), filtrada por banco e ambiente.

## Testes

- Unit `tests/Contribua/Unit/Banco/BoletoDaCoraTest.php`: corpo exato, CPF, resposta com e sem Pix, mínimo sem chamada,
  resposta ruim (sem linha, PDF fora de https), Cora fora.
- Unit `tests/Contribua/Unit/GerarBoletoDeApoioUseCaseTest.php`: emissão, idempotência (do dia, retry com a mesma chave,
  fatura já nossa), chave derivada, indisponível (chave, conexão, outro banco, sandbox), recusas e limite.
- Functional `tests/Contribua/Functional/BoletoDeApoioTest.php`: 200 com a linha e repetição sem nova fatura, PIX
  separado do boleto, chave desligada 404, outro banco 404 e chave desabilitada com a nota, valor baixo e documento 422,
  Cora fora 503 sem linha, CSRF e sandbox, arranjo da tela (filho direto), conciliação e aviso confirmam o boleto pago.

## Para o dono

1. **Conferir no sandbox da Cora** (stage, com a chave Boleto ligada):
   - a `Idempotency-Key` repetida devolve a **mesma** fatura (e por quanto tempo a Cora guarda a chave);
   - o boleto sai com o QR Pix (`pix.emv`) quando a conta tem chave Pix, e o PDF abre pela `payment_options.bank_slip.url`;
   - a Cora aceita o boleto sem `fine` e `interest` e sem `notifications`;
   - um documento inválido volta com qual status (a tela mostra a mensagem genérica de qualquer jeito);
   - o webhook da Cora chega para o boleto pago (`invoice.paid`) no mesmo endereço do PIX, e o status da fatura paga
     depois do vencimento (LATE → PAID) confirma igual.
2. **Na tela:** o botão "Gerar boleto" abaixo do "Copiar código PIX", o pedido com o CPF/CNPJ, o resultado (linha,
   vencimento, PDF, Pix do boleto) e, na Integração bancária com Inter/BB/Outro, a chave Boleto desabilitada com a nota.
3. Boleto pago **depois** de trocar de banco fica sem confirmação automática, como a cobrança PIX (C4b, item 4).
