# Gateway de pagamentos: PIX com confirmação automática (webhook)

> Spec de risco **ALTO** (dinheiro). Investigação de 26/10/2026, somente leitura. Nenhum código escrito.
> Substitui o "o Master marca que o PIX caiu" por confirmação do banco, sem quebrar o fluxo manual.

## 1. Estado real (medido em `origin/master` 971cd3f6c + PRs abertos)

| Item | Situação |
|---|---|
| Provedor de **PIX** decidido | **Cora SCFI (403)**: conta da BlueJus Gestão e Tecnologia LTDA, CNPJ 66.803.163/0001-10. Spec `contribua-planos-cora.md` §7, decisão do dono. |
| Provedor de **cartão** decidido | **Asaas** primeiro (D2, 19/10). Mercado Pago, Stripe e Pagar.me ficam atrás da mesma interface. |
| Código da Cora | **Não está no master.** PR #88 (`contribua-pix`, C4) aberto, **CONFLICTING**, empilhado sobre #95 (`contribua-c3`, rascunho) e #74 (C2). Tem `BancoPix`, `BancoCora`, `ClienteCora` (mTLS), `WebhookDoBancoController`, `ConciliarCoraCommand`, `docs/specs/contribua-c4-cora.md`. |
| Credenciais Cora/Asaas em produção | **Não confirmadas** (pendência C5 do dono: certificado mTLS, Client ID, webhook cadastrado no painel). |
| PIX do Trabalho Externo (#398, no master) | PIX **estático** (`PixDaDemanda`, chave CNPJ, txid = código da demanda) + comprovante enviado pelo cliente + `ConfirmarRecebimentoDoPixUseCase` (Master/Equipe confirma à mão, valor sempre da proposta aceita, demanda travada `FOR UPDATE`). Sem integração bancária. |
| Service Desk "saldo mínimo R$ 20" | **Não existe no repo** (grep em `app/src`, `docs/specs/servicedesk*`). Só `Chamado`, `ChamadoAnexo`, `ChamadoInteracao`. Fronteira: ver §5.2. |
| Migration livre | `Version20261024150700` é a última no master; coordenador indica próxima **151000** (§8). |

Conclusão: **o gateway está contratado em decisão, não em código nem em produção.** O caminho mais curto é **reaproveitar a C4 Cora (#88)**, não construir outro provedor.

## 2. Provedor: recomendação

| Opção | Prós | Contras |
|---|---|---|
| **A. Cora (recomendada para PIX)** | Já é a conta do dono; spec e código prontos no #88; QR dinâmico v2; reconsulta por mTLS; sem tarifa de gateway sobre PIX recebido (confirmar tabela da conta). | Webhook **sem corpo e sem assinatura** (só headers `webhook-event-*`): a autenticidade vem da **reconsulta mTLS**, não do aviso. mTLS exige certificado gerido por nós. #88 conflita e depende da pilha #74/#95. |
| B. Asaas (PIX + cartão + NFS-e) | Um provedor para PIX e cartão; NFS-e automática; sandbox bom; webhook com token (`asaas-access-token`). | Tarifa por PIX recebido; segunda conta/integração para o PIX; Asaas já é a escolha do **cartão**, não do PIX. |
| C. Efí / Woovi (PIX dedicado) | Cobrança PIX com webhook assinado e estorno (devolução) por API. | Contrato novo, conta nova; nenhum deles está no escopo decidido. |

**Recomendo A (Cora) para PIX, com Asaas só no cartão (como já decidido).** Motivo: dinheiro já cai na Cora, código existe, e a reconsulta mTLS resolve a falta de assinatura. Se o dono não quiser mTLS, B é o plano alternativo.

## 3. Fluxo (provedor-neutro, atrás de `BancoPix`)

Interface `App\Contribua\Banco\BancoPix` (do #88), estendida só por adição (contrato compartilhado: avisar antes).

1. **Cobrança.** `criarCobrancaPix(referencia, valorCentavos, vencimento)` devolve `{idNoBanco, txid, brCode, expiraEm}`. Referência estável: `TE<codigo>` (Trabalho Externo) e `SD<tenant>-<seq>` (Service Desk). A **`Idempotency-Key` é gravada antes da chamada** (como no boleto da Cora): reenvio do mesmo pedido devolve a mesma cobrança.
2. **Valor e dono vêm do servidor.** Valor da proposta aceita (TE) ou do pacote (SD). O cliente nunca informa valor nem referência.
3. **Aviso (webhook).** `POST /api/webhooks/<banco>/pix`, `PUBLIC_ACCESS`:
   - grava `NotificacaoRecebida` (`origem`, `idEvento` **único**; repetido = ignora, responde 200);
   - Cora: sem corpo nem assinatura, então **o aviso nunca confirma nada**: enfileira no Messenger a **reconsulta mTLS** do recurso; só a resposta do banco confirma;
   - banco com assinatura (Asaas/Efí): validar HMAC ou token em **tempo constante** antes de gravar; falha = 401;
   - responde sempre 200 rápido (resto é assíncrono); corpo/headers nunca vão para o log completo.
4. **Confirmação (idempotente).** Handler único `ConfirmarPagamentoPixUseCase`: trava a cobrança `FOR UPDATE`, confere `status` ainda `aguardando`, confere **valor pago >= valor cobrado** e `txid`/referência, grava `pagoEm`, `endToEndId`, e chama o consumidor. Segunda chamada é no-op. Valor menor: marca `divergente`, avisa o Master, **não** libera.
5. **Conciliação.** `app:pix:conciliar` a cada 15 min (padrão da `app:cora:conciliar`, via Scheduler): consulta cobranças `aguardando` com mais de 2 min e expiradas há menos de 7 dias; cobre aviso perdido. Relatório diário ao Master de `divergente` e `aguardando` vencidas.
6. **Estorno/devolução.** Fora do automático na v1: o Master pede, o sistema registra `DevolucaoPix` (valor, motivo, quem, `endToEndId` da devolução) e chama a API de devolução do banco. Efeito no consumidor: TE volta a demanda para "aguardando pagamento" só com ação humana explícita; SD debita o saldo (nunca deixa negativo sem alerta). Aviso do banco de devolução (`invoice.refunded` ou equivalente) entra pelo mesmo webhook e registra, **sem desfazer sozinho**.
7. **Expiração.** Cobrança vence (padrão 24 h TE, 1 h SD), vira `expirada`; QR novo gera nova cobrança (nova referência de tentativa), nunca reabre a antiga.

## 4. Modelo de dados (proposta)

- `pix_cobranca` (por tenant): `id`, `tenant_id`, `origem` (`trabalho_externo`|`service_desk`), `origem_id`, `referencia` única, `valor_centavos`, `txid`, `id_no_banco`, `status` (`aguardando`|`paga`|`expirada`|`divergente`|`devolvida`), `pago_em`, `valor_pago_centavos`, `end_to_end_id` único (nulo até pagar), `idempotency_key` única, `criada_em`, `expira_em`. Índice `(status, expira_em)`.
- `pix_devolucao`: `cobranca_id`, `valor_centavos`, `motivo`, `solicitada_por`, `id_no_banco`, `status`.
- `NotificacaoRecebida` já existe na C3 (#95); reaproveitar (`idEvento` único). Não criar outra.
- **Sem migration que apague nada.** `ConfirmarRecebimentoDoPixUseCase` continua como **plano B manual** (§5.1).

## 5. Pontos de integração

### 5.1 Trabalho Externo (#398, no master)
- `PixDaDemanda` continua como **fallback estático** quando a integração está desligada (o dono liga por recurso, como o §7 do Contribua).
- Com integração: `ApresentadorDoPagamento` pede cobrança dinâmica por demanda (`TE<codigo>`) e mostra `brCode` dela; o QR passa a identificar o pagamento sem olho humano.
- `ConfirmarRecebimentoDoPixUseCase`: extrair o miolo (marco `pago`, aviso WhatsApp da fase Pago, andamento) para método reutilizável chamado por `ConfirmarPagamentoPixUseCase`. O botão manual **permanece** (Master/Equipe com "Alterar a situação"), mas passa a recusar se existir `pix_cobranca` `aguardando` ainda válida sem aviso do banco, exceto com motivo registrado (evita pagar duas vezes por engano). Mensagem do chat: "Pagamento de R$ x confirmado via PIX" mantida; agora autor = "Sistema (banco)".
- Comprovante enviado pelo cliente deixa de ser exigido para liberar, mas continua aceito.

### 5.2 Service Desk (razão e carteira são da sessão ServiceDesk)
- Dono do razão, da carteira e do mínimo de R$ 20: `docs/specs/service-desk-chamados-e-saldo.md` (interface `ProvedorDeRecarga::iniciar(pedido): InstrucaoDePagamento`, hoje só `RecargaManual`; confirmação por `ConfirmarRecargaDeSaldo`, idempotente por `referencia_externa`). **Esta frente não cria tabela nem regra de saldo.**
- Esta frente entrega o adaptador **`RecargaPorPixCora`** que implementa `ProvedorDeRecarga`: `iniciar` cria `pix_cobranca` (`origem = service_desk`, `origem_id` = id do pedido de recarga) e devolve o `brCode` dinâmico; o webhook/reconsulta, ao confirmar (§3.4), chama `ConfirmarRecargaDeSaldo` com `referencia_externa = end_to_end_id`.
- Contrato a alinhar com a sessão ServiceDesk: assinatura de `iniciar`, formato de `InstrucaoDePagamento` (brCode, expiraEm, referência), e `ConfirmarRecargaDeSaldo(referenciaExterna, valorCentavos)`. Mudança na interface dela é contrato compartilhado: avisar antes.
- O mínimo de R$ 20 é validado por ela; o adaptador só repassa valor.

## 6. Segurança

1. **Segredos fora do banco** (decisão D6 do Contribua): certificado mTLS e chave em arquivo montado, Client ID/secret em `.env.prod`; nunca em log, flash ou resposta.
2. **Webhook nunca confia no corpo.** Cora: reconsulta mTLS. Outros: assinatura em tempo constante; sem credencial = 401; gateway desconhecido = 404; resto = 200.
3. **Valor e referência do servidor**, conferidos na confirmação (`valor_pago >= cobrado`, `txid` bate). Pagamento a menor = `divergente`.
4. **Idempotência em 3 camadas:** `NotificacaoRecebida.idEvento` único, `pix_cobranca.status` sob `FOR UPDATE`, `end_to_end_id` único.
5. **Multi-tenant:** `tenant_id` em toda leitura de tela; o webhook resolve o tenant **pela cobrança** (referência), nunca por dado do aviso. Cobrança de outro tenant = 404.
6. **Rate limit e tamanho** no endpoint público; resposta 200 não revela se o evento era válido.
7. **Auditoria:** quem confirmou (sistema ou humano), `endToEndId`, valor. Estorno só Master, com motivo.
8. **PII:** CPF/CNPJ do pagador que vier do banco não vai para log; guardar só os 4 últimos dígitos se preciso.
9. **Rotação:** procedimento do certificado mTLS e do token do webhook em `docs/runbooks/` (a criar quando entrar em produção; runbook do Asaas já existe no #95).

## 7. Testes e rollout

- Unit: `ConfirmarPagamentoPixUseCase` (já paga, valor menor, valor maior, expirada, cobrança de outro tenant, evento repetido, corrida de duas confirmações). Adaptador falso `CoraFalsa` já existe no #88.
- Functional: webhook (sem credencial, evento repetido, desconhecido), TE e SD fim a fim com banco falso.
- Rollout em 3 degraus: (1) ligar em sandbox; (2) produção com **um** escritório e o botão manual ainda ativo; (3) botão manual só com motivo. Chave liga/desliga em `ConfiguracaoDePagamento` (existente na C3).
- Pré-requisito de ordem: **resolver #88/#95/#74** antes (conflito, merge em série pelo dono). Esta frente não deve reimplementar o que o #88 já tem.

## 8. Migrations

**Uma**, reservada pelo Coordenador: `Version20261026151000`, só `pix_cobranca` e `pix_devolucao`. Nada de carteira/razão (é da sessão ServiceDesk). As migrations do #88 (`Version20261019230000`) e #95 (`...235700`) são renomeadas na vez delas.

## 9. Perguntas de negócio ao dono

1. Confirma **Cora para PIX** (e Asaas só cartão)? Já existe certificado mTLS e Client ID de produção?
2. Pagamento **a menor** libera, fica pendente ou devolve? E a **maior**?
3. Estorno de PIX no TE: devolve o dinheiro e reabre a demanda, ou só o Master decide caso a caso?
4. (Service Desk: saldo, mínimo e custo do chamado são da spec da sessão ServiceDesk; a pergunta fica com ela.) O recebedor das recargas do Service Desk é a mesma conta Cora?
5. Depois da confirmação automática, o botão manual do Master permanece (só com motivo) ou sai?
6. Prazo de validade da cobrança (TE 24 h? SD 1 h?) e quem absorve tarifa do banco, se houver.
