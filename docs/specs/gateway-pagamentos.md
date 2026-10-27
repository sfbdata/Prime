# Gateway de pagamentos: PIX com confirmação automática (webhook)

> Spec de risco **ALTO** (dinheiro). Investigação de 26/10/2026, somente leitura. Nenhum código escrito.
> Substitui o "o Master marca que o PIX caiu" por confirmação do banco, sem quebrar o fluxo manual.

> **Decisões do dono (26/10):** (a) CNPJ e chave PIX do desenho do Service Desk são os da BlueJus; (b) migrar **todos** os métodos (Trabalho Externo, Service Desk, Contribua/apoio, planos/cartão) para o gateway, com rastreio automático de quem, quando e quanto pagou; (c) valor da proposta do TE editável pelo Master **só antes de pago**: quem já pagou mantém o valor antigo, quem vai pagar paga o novo.

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

## 4. Modelo de dados: registro único de pagamentos

Uma tabela é a verdade de "quem pagou, quando e quanto", para qualquer origem. As demais (`AssinaturaDeEspaco`, `Apoio`, recarga do Service Desk, `pagoEm` da demanda) viram **consumidoras** dela, não fontes.

- `pagamento` (por tenant): `id`, `tenant_id` (escritório), `origem` (`trabalho_externo`|`service_desk`|`contribua_apoio`|`contribua_plano`), `origem_id`, `referencia` única (`TE<codigo>-<n>`, `BJSD...`, `BJ<conta>-...`), `pagador_user_id` nulo-se-externo, `pagador_nome` e `pagador_documento_final` (4 últimos dígitos, §6.8), `metodo` (`pix`|`cartao`|`boleto`|`manual`), `provedor` (`cora`|`asaas`|`manual`), `valor_centavos` (**congelado na criação**), `valor_pago_centavos`, `status` (`aguardando`|`paga`|`expirada`|`divergente`|`devolvida`|`cancelada`), `criado_em`, `pago_em`, `expira_em`, `id_externo` (id no banco), `end_to_end_id` único, `idempotency_key` única, `confirmado_por` (`banco`|`manual:<userId>`), `motivo_manual`. Índices: `(tenant_id, origem, origem_id)`, `(status, expira_em)`.
- `pagamento_devolucao`: `pagamento_id`, `valor_centavos`, `motivo`, `solicitada_por`, `id_externo`, `status`, datas.
- `pagamento_evento` (trilha imutável): `pagamento_id`, `tipo` (criado, aviso, reconsultado, confirmado, divergente, devolvido, manual), `em`, `quem`, `resumo` sem PII. É o "rastreio automático".
- `NotificacaoRecebida` (já existe no #95) segue para a idempotência do webhook.
- `pix_cobranca` das seções anteriores = esta tabela `pagamento` (renomeada: um só registro). Consumidor liga a `pagamento` por `origem`/`origem_id`; sem FK entre domínios.
- Leitura: tela "Pagamentos" do Master (filtro por escritório, origem, status, período) e extrato do escritório; ambas só leitura sobre `pagamento`. Layout dela: desenho a pedir ao dono (não inventar).

## 5. Pontos de integração

### 5.1 Trabalho Externo (#398, no master)
- `PixDaDemanda` continua como **fallback estático** quando a integração está desligada (o dono liga por recurso, como o §7 do Contribua).
- Com integração: `ApresentadorDoPagamento` pede cobrança dinâmica por demanda (`TE<codigo>`) e mostra `brCode` dela; o QR passa a identificar o pagamento sem olho humano.
- `ConfirmarRecebimentoDoPixUseCase`: extrair o miolo (marco `pago`, aviso WhatsApp da fase Pago, andamento) para método reutilizável chamado por `ConfirmarPagamentoPixUseCase`. O botão manual **permanece** (Master/Equipe com "Alterar a situação"), mas passa a recusar se existir `pix_cobranca` `aguardando` ainda válida sem aviso do banco, exceto com motivo registrado (evita pagar duas vezes por engano). Mensagem do chat: "Pagamento de R$ x confirmado via PIX" mantida; agora autor = "Sistema (banco)".
- **Valor editável pelo Master (decisão do dono):** a edição da proposta aceita só vale com **nenhum `pagamento` `paga`** da demanda. `valor_centavos` é congelado em cada `pagamento` na criação, então quem já pagou mantém o valor antigo. Editar valor com cobrança `aguardando`: cancela a cobrança (`cancelada`, cancelada também no banco) e a próxima gera `pagamento` novo com o valor novo; QR antigo já impresso paga a cobrança cancelada: o aviso cai em `divergente` e o Master decide (devolver ou aceitar). Com `paga`, a edição é recusada (409) e o Master usa devolução + nova cobrança. Registrar o valor antigo e novo em `pagamento_evento` (quem editou, quando).
- Comprovante enviado pelo cliente deixa de ser exigido para liberar, mas continua aceito.

### 5.2 Service Desk (razão e carteira são da sessão ServiceDesk)
- Dono do razão, da carteira e do mínimo de R$ 20: `docs/specs/service-desk-chamados-e-saldo.md` (interface `ProvedorDeRecarga::iniciar(pedido): InstrucaoDePagamento`, hoje só `RecargaManual`; confirmação por `ConfirmarRecargaDeSaldo`, idempotente por `referencia_externa`). **Esta frente não cria tabela nem regra de saldo.**
- Esta frente entrega o adaptador **`RecargaPorPixCora`** que implementa `ProvedorDeRecarga`: `iniciar` cria `pix_cobranca` (`origem = service_desk`, `origem_id` = id do pedido de recarga) e devolve o `brCode` dinâmico; o webhook/reconsulta, ao confirmar (§3.4), chama `ConfirmarRecargaDeSaldo` com `referencia_externa = end_to_end_id`.
- **Contrato final (bluejus-ef, 26/10; código no PR 1 dela, namespace `App\ServiceDesk\Pagamento`):**
  - `ProvedorDeRecarga::iniciar(PedidoDeRecarga): InstrucaoDePagamento`. `PedidoDeRecarga` (readonly): `int tenantId`, `string referencia` (id da recarga, ex. `BJSD1A2B3C4D5E6F`, **usar como txid** e como `referencia` da `pix_cobranca`), `int valorCentavos`, `FormaDeRecarga forma` (pix|cartao), `string descricao`. Exceção em `iniciar()` = nada gravado.
  - `InstrucaoDePagamento` (readonly): `?string copiaECola` (brCode), `?\DateTimeImmutable expiraEm`, `string mensagem`.
  - `ConfirmarRecargaDeSaldoUseCase::executar(ConfirmarRecargaInput(referencia, valorCentavos, idExterno = null, origem = 'manual', confirmadoPor = null))` devolve `RecargaConfirmadaOutput(resultado: ResultadoDaConfirmacao, ?saldoCentavos)`, **sem lançar por regra** (exceção na transação fecha o EntityManager). Resultados: `Confirmada`, `JaConfirmada`, `ValorDivergente` (grava `valorRecebido`, recarga segue pendente para conciliar), `PagamentoRepetido` (mesmo `idExterno` em outra recarga), `NaoEncontrada`. Este adaptador passa `origem = 'cora'` e `idExterno = end_to_end_id`. Usa `wrapInTransaction` próprio; aninhado na transação do webhook funciona.
  - `iniciar()` roda **depois** de gravar o pendente. Lançar `App\ServiceDesk\Pagamento\CobrancaNaoCriada` **só** quando é certo que nada foi cobrado (o pendente é desfeito); qualquer outra exceção (timeout) mantém o pendente, então a `Idempotency-Key` do §3.1 garante que a nova tentativa não duplica a cobrança.
  - Binding: `RecargaManual` é o padrão; este PR troca o alias de `ProvedorDeRecarga` em `services.yaml`.
  - **Consequência no §3.4:** para `service_desk`, pagamento de valor diferente do pedido **não credita** (`ValorDivergente` dela: o `pagamento` vira `divergente` aqui; o resto do resultado é só registrado em `pagamento_evento`); pagamento a maior também fica `divergente` (decisão do §9.2 vale para o TE).
- O mínimo de R$ 20 é validado por ela; o adaptador só repassa valor.

### 5.3 Plano de migração por método (um por PR, ordem de menor para maior risco)

Cada etapa é reversível por chave liga/desliga (`ConfiguracaoDePagamento`); o caminho antigo continua até a etapa seguinte estar estável por 2 semanas.

| # | Método | Hoje | Passa a | Pré-requisito |
|---|---|---|---|---|
| M0 | Base | nada | `pagamento`, `pagamento_evento`, `BancoPix` (reuso #88), webhook, conciliação, tela Master, **sem consumidor** | #74/#95/#88 mergeados; credenciais Cora; migration 151000 |
| M1 | Trabalho Externo (PIX) | PIX estático + comprovante + confirmação manual | cobrança dinâmica por demanda; confirmação automática; manual com motivo | M0; decisão §9.3 |
| M2 | Service Desk (recarga PIX) | `RecargaManual` (pendente) | `RecargaPorPixCora` (contrato do §5.2) | M0 e PR 1 da bluejus-ef |
| M3 | Contribua apoio (PIX) | BR Code estático + "informei" | `Apoio` ligado a `pagamento`; prêmio ao confirmar | M0 (já previsto na C4) |
| M4 | Contribua planos (cartão, Asaas) | assinatura Asaas (#95), `AssinaturaDeEspaco` | cada cobrança do Asaas vira `pagamento` (`origem=contribua_plano`, `provedor=asaas`) por webhook/reconsulta; `AssinaturaDeEspaco` mantém a vigência | M0; Asaas em produção |
| M5 | Retirada do manual | botões "confirmar à mão" | só com motivo e perfil Master | M1 a M4 estáveis |

**Histórico:** pagamentos anteriores (`pagoEm` do TE, `Apoio` confirmados, pedidos de espaço) entram por comando único `app:pagamento:importar-historico` (dry-run por padrão), com `provedor=manual`, `confirmado_por=manual:importacao`, sem inventar `end_to_end_id`. Só depois da decisão do dono (§9.7).
**Cartão:** número, CVV e validade nunca tocam servidor, log ou banco; só bandeira e 4 últimos dígitos se o Asaas devolver.

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

**Uma** por etapa de código, nunca duas abertas. Reservada: `Version20261026151000` = M0, só `pagamento`, `pagamento_devolucao`, `pagamento_evento`. Nada de carteira/razão (sessão ServiceDesk). Colunas de ligação nos consumidores (se necessárias) vão na migration da própria etapa. As do #88 (`Version20261019230000`) e #95 (`...235700`) são renomeadas na vez delas.

## 9. Decisões do dono (27/10, `bluejus-coordenacao/decisoes-27-10.md`)

Todas as perguntas foram respondidas pela recomendação:
1. **Cora para PIX, Asaas para cartão.** Certificado mTLS e Client ID de produção da Cora são do dono; esta frente pede quando precisar. **Sem credencial, o botão de PIX dinâmico some** (dev igual a produção: cai no PIX estático onde ele já existe).
1b. **PIX dinâmico, valor fixo (decisão do dono, 27/10):** com o gateway o PIX é cobrança dinâmica: o QR Code já vem com o valor predefinido e o pagador **não pode alterar o valor** (`valor` do BR Code sem permissão de alteração; cobrança com valor fixo na API do banco). Cobrança sem integração (PIX estático antigo) continua existindo só como fallback.
2. Pagamento **a menor**: fica pendente (`divergente`), não libera. **A maior**: libera e avisa o Master da diferença. Com 1b isso é só **rede de segurança** (PIX estático antigo ou valor alterado), não o fluxo normal. (Ajusta o §3.4 e o §5.2: no Service Desk o resultado `ValorDivergente` continua sem creditar; a maior no TE libera.)
3. **Estorno:** o Master decide caso a caso.
4. Service Desk: saldo pré-pago, recarga mínima R$ 20,00; preço do chamado configurável pelo Master, só cobra com preço definido (da spec da sessão ServiceDesk).
5. **Botão manual** fica como plano B, só com motivo registrado.
6. **Validade:** TE 24 h, Service Desk 1 h; a BlueJus absorve a tarifa.
7. **Histórico:** começa do zero, importando só as **assinaturas ativas** (`app:pagamento:importar-historico` reduzido a isso).
8. **Ordem:** #95 (cartão Asaas) primeiro; depois esta frente atualiza o #88 (Cora) e o reaproveita. M0 só começa após o merge do #95.
9. CNPJ e chave PIX do Service Desk são os da BlueJus.
