# Contribua: planos de espaço, cobrança pela Cora e PIX voluntário

> Spec de risco **ALTO** (mexe em `Tenant` e em dinheiro). Escrita em 19/10/2026 a partir do print do
> desenho "08 - Contribua - VS 18.10.26" (o arquivo `.dc.html` não pôde ser lido nesta sessão: o MCP do
> claude_design não estava conectado). **Nenhuma linha de código antes das decisões do §9.**

## 1. Estado real (medido no master `1d3e664c`)

| Peça que o desenho supõe | Existe? | Onde está o mais próximo |
|---|---|---|
| Integração com a Cora (token, fatura, webhook) | **não** | nenhum uso de mTLS no código; nenhum controller de webhook |
| Plano, cota de armazenamento por escritório | **não** | planejado na E3 de `perfis-master-1.0.md` (§5), não iniciada |
| Consumo de espaço por escritório | **sim, parcial** | `Master/Repository/PainelMasterRepository::consumoPorEscritorio()` (soma `tamanho_bytes` de 7 tabelas; fica fora `documento_demanda`, foto de perfil, anexos de ponto e de tarefa, imagem do editor) |
| Ponto único de gravação de arquivo | **sim** | `Shared/Armazenamento/ArmazenamentoDeArquivos::gravar()` (uma implementação: `ArmazenamentoLocal`) e, para upload HTTP, `Shared/Http/FonteDeUploadHttp::gravarEm()` |
| Página "Quem somos e contribua" / aba no perfil | **não** | `/perfil` é um card único com modais, sem abas |
| Fila entre pop-ups | **não** | flash, Central flutuante, modal de alerta do ponto, selo de Novidades e modal do iOS são independentes |
| Pagamento na política de privacidade | **removido de propósito** | `docs/HANDOFF_POLITICA_PRIVACIDADE.md`: saiu do Anexo I porque não existia |

## 2. O que o desenho mostra (print de 19/10)

1. **Toast no topo** (todas as telas): "Seu espaço está 93% ocupado", texto "9,3 GB de 10 GB no plano Free.
   Faltam 0,7 GB. Recomendamos aumentar o espaço para não interromper o envio de documentos.", barra,
   botão **Aumentar espaço**, fechar (×).
2. **Página "Quem somos e contribua"** (cabeçalho do produto + link **Sugestões**).
3. **Faixa de alerta**: "Seu escritório está com 93% do espaço ocupado", subtítulo "Amplie agora e continue
   anexando documentos sem interrupção. Nada do que você já guardou é apagado.", botão
   **Configurar pagamento · não configurado**.
4. **Barra de uso**: "9,3 GB de 10 GB no plano Free" / "93% ocupado".
5. **Quatro planos**: Free 10 GB grátis ("Seu plano atual", desabilitado) · Essencial 20 GB R$ 19,90/mês
   (selo "Recomendado para você", +10 GB, "menos de R$ 0,67 por dia", botão verde **Quero +10 GB**) ·
   Profissional 50 GB R$ 39,90 (+40 GB) · Avançado 100 GB R$ 69,90 (+90 GB).
6. **Quatro garantias**: Liberado na hora · Seus arquivos seguros · Sem fidelidade (cancele em um clique) ·
   Nota fiscal (enviada por e-mail todo mês).
7. **Apoio voluntário por PIX**: QR Code, favorecido "BlueJus Gestão e Tecnologia LTDA", CNPJ
   66.803.163/0001-10.

Conferir item a item contra o `.dc.html` (tokens, estados vazios, telas de pagamento pendente/pago, que o
print não mostra) antes da etapa C2.

## 3. Domínio novo `app/src/Assinatura/`

```
Assinatura/
  Entity/      PlanoDeEspaco, AssinaturaDoEscritorio, FaturaCora, NotificacaoCora
  Enum/        SituacaoAssinatura, SituacaoFatura
  Repository/  (filtro de tenant em tudo, menos o que o webhook e o cron leem por id da Cora)
  Service/     ClienteCora (HTTP mTLS), ConsumoDeArmazenamento, CotaDoEscritorio
  UseCase/     ContratarPlano, ConfirmarPagamentoDaFatura, GerarFaturasDoCiclo,
               CancelarAssinatura, RebaixarInadimplentes, ConfigurarPagador
  Controller/  PlanoController (/perfil/plano), WebhookCoraController (/webhooks/cora)
  Command/     app:cora:conciliar, app:assinatura:gerar-faturas, app:assinatura:rebaixar-inadimplentes
```

**Entidades** (PK integer, `tenant` obrigatório onde há escritório):

- `PlanoDeEspaco`: `codigo` (free/essencial/profissional/avancado), `nome`, `cotaGb`, `precoCentavos`,
  `ativo`, `ordem`. Catálogo global, editável só pelo Master. Preço em centavos (a Cora exige inteiro;
  mínimo R$ 5).
- `AssinaturaDoEscritorio` (uma vigente por tenant): `plano`, `situacao` (pendente, ativa, inadimplente,
  cancelada), `pagaAte` (date), `canceladaEm`, `contratadaPor` (User), dados do pagador (`nome`,
  `documento` CPF/CNPJ, `email`) que alimentam o "Configurar pagamento".
- `FaturaCora`: `assinatura`, `idCora` (único), `chaveIdempotencia` (UUID, único), `valorCentavos`,
  `vencimento`, `situacao` (OPEN, PAID, LATE, CANCELLED, espelho da Cora), `pixCopiaECola`, `urlBoleto`,
  `linhaDigitavel`, `pagaEm`, `competencia` (AAAA-MM, única por assinatura: impede fatura dobrada no mês).
- `NotificacaoCora`: `idEvento` (único), `tipo`, `idRecurso`, `recebidaEm`, `processadaEm`. Idempotência
  do webhook e trilha de auditoria.

**Tenant**: a coluna `cota_armazenamento_gb` da E3 passa a ser **override do Master** (nulo = vale o
plano). Cota efetiva = override ?? plano da assinatura ativa ?? plano Free. Assim a E3 e esta spec não
disputam a mesma coluna.

## 4. Cota e bloqueio de upload

- `ConsumoDeArmazenamento::doEscritorio(Tenant)`: extrai a soma de `PainelMasterRepository` para um
  serviço compartilhado (o painel passa a usá-lo), inclui `documento_demanda`, ignora documento com
  soft delete (decisão D7). Cache curto (60 s) por tenant, invalidado a cada gravação.
- **Ponto de bloqueio único**: decorador `ArmazenamentoComCota` sobre `ArmazenamentoDeArquivos::gravar()`.
  Recusa com `CotaDeArmazenamentoEsgotadaException` quando `consumo + tamanho > cota` e o escopo é de
  tenant. Isentos por parâmetro explícito, não por adivinhação: escopo global, recompressão (regrava menor),
  comando de acervo. Consulta, download, exportação e exclusão **nunca** são bloqueados.
- Mensagem ao recusar (flash ou JSON, conforme a rota): "Seu espaço acabou. Os arquivos que você já tem
  continuam guardados. Para enviar novos, aumente o espaço." com link para `/perfil/plano`.

## 5. Pop-ups e comandos existentes (respeitar)

- **Toast de espaço** (item 2.1) em `base.html.twig`, só para quem pode contratar (D4), nas faixas
  70/85/95/100 da E3. Fechar no × esconde **até a próxima faixa** (chave `tenant:faixa` no
  `localStorage`, no molde do modal de ponto). Em 100% não fecha: vira faixa fixa.
- **Precedência** (hoje não existe fila; esta spec cria uma regra mínima, sem refatorar os outros):
  modal de alerta do ponto > modal do iOS > toast de espaço > Central flutuante. O toast espera o
  `hidden.bs.modal` de qualquer modal aberto e não cobre o botão da Central (canto inferior direito).
- Nada muda no sino, nas Novidades, nos flashes nem nos atalhos de teclado já ligados (Ctrl+Y etc.).
- A virada de faixa e a confirmação de pagamento geram `Notificacao` pelo `NotificacaoService` (aparece no
  sino e na Central), tipos novos `assinatura.espaco_faixa` e `assinatura.pagamento_confirmado`.

## 6. Cora

**Configuração (env, nunca no repositório):** `CORA_AMBIENTE` (stage|producao), `CORA_CLIENT_ID`,
`CORA_CERT_PATH`, `CORA_KEY_PATH` (arquivos montados no container, fora de `app/`), `CORA_URL_TOKEN`,
`CORA_URL_API`. Stage: token `https://matls-clients.api.stage.cora.com.br/token`, API
`https://api.stage.cora.com.br`. URLs de produção: confirmar no painel da Cora (a doc pública não lista).

**`ClienteCora`** (Symfony HttpClient com `local_cert`/`local_pk`; não há precedente em `app/`, conferir
`symfony.com/doc/7.4/http_client.html` antes):
- `token()`: `POST /token`, `grant_type=client_credentials&client_id=…`, mTLS. Vale 24 h, sem refresh:
  guardar em cache por 23 h.
- `emitirFatura()`: `POST /v2/invoices/` com `Idempotency-Key` = `FaturaCora.chaveIdempotencia` (gravada
  **antes** da chamada, para que o retry nunca duplique), `payment_forms: ["BANK_SLIP","PIX"]`,
  `services[].amount` em centavos, `payment_terms.due_date`, `customer` com nome, documento e e-mail.
- `consultarFatura(idCora)`, `cancelarFatura(idCora)`.

**Webhook `/webhooks/cora`** (`PUBLIC_ACCESS` em `security.yaml`, sem CSRF):
- A Cora manda **sem corpo e sem assinatura**, só os cabeçalhos `webhook-event-id`, `webhook-event-type`
  (`invoice.paid`…) e `webhook-resource-id`. Portanto o webhook **é só um aviso**: grava
  `NotificacaoCora` (ignora `idEvento` repetido), responde 200 `{"success": true}` e enfileira no
  Messenger `async` a reconsulta da fatura por mTLS. Só a resposta da própria Cora muda a situação.
  Quem forjar o POST, no máximo, faz o sistema perguntar à Cora.
- `idRecurso` desconhecido: registra e ignora (não vaza se existe ou não).

**Conciliação** (`app:cora:conciliar`, cron a cada 15 min): reconsulta faturas `OPEN`/`LATE` dos últimos
60 dias. Cobre webhook perdido. Mesma rotina do webhook (`ConfirmarPagamentoDaFatura`), idempotente.

## 7. Fluxos

1. **Contratar** (`Quero +10 GB`): exige pagador configurado (senão abre o modal "Configurar
   pagamento"). Cria assinatura `pendente` + fatura da competência, vencimento hoje + 3 dias (D3), mostra
   QR/copia-e-cola e boleto na mesma tela, com espera ativa (polling de 10 s na situação da fatura).
2. **Pago** (webhook ou conciliação): fatura `PAID`, assinatura `ativa`, `pagaAte` = +1 mês, cota sobe na
   hora ("Liberado na hora"), notificação no sino.
3. **Mensal** (`app:assinatura:gerar-faturas`, diário): emite a fatura da próxima competência 5 dias antes
   de `pagaAte`, e-mail ao pagador com o link.
4. **Inadimplência** (`app:assinatura:rebaixar-inadimplentes`, diário): `pagaAte` + 7 dias sem pagamento
   (D3) → `inadimplente`, cota volta ao Free. **Nada é apagado**: só novos uploads ficam bloqueados se o
   consumo passar dos 10 GB.
5. **Cancelar em um clique**: cancela a fatura em aberto na Cora; mantém o plano até `pagaAte`; depois volta
   ao Free. Sem multa.
6. **Trocar de plano**: subir cobra a diferença proporcional? (D3). Descer vale no próximo ciclo e só se o
   consumo couber na cota nova.
7. **PIX voluntário**: BR Code **estático** da chave PIX da BlueJus, gerado no servidor (sem Cora, sem
   registro de quem pagou, sem dado pessoal). Valor livre.

## 8. Permissão e segurança

- Ver a página: todo usuário do escritório. Contratar, trocar, cancelar e configurar pagador: só o usuário
  primário (titular/sócio) (D4). Teste funcional de 403 para secundário.
- Isolamento: toda rota de `/perfil/plano` resolve o tenant da sessão; teste de isolamento no UseCase
  (escritório A não vê nem cancela fatura de B).
- Segredos: certificado e chave só em arquivo montado; guarda de segredos do CI cobre `*.pem`/`*.key`.
- Política de privacidade volta a listar processamento de pagamento com a Cora como suboperadora: **texto
  jurídico é do dono/advogado** (D6).

## 9. Decisões do dono (bloqueiam o início)

| # | Decisão | Proposta |
|---|---|---|
| D1 | Acesso ao desenho: exportar `08 - Contribua - VS 18.10.26.dc.html` para `docs/design/contribua/` (ou conectar o MCP claude_design com `/design-login`) | necessário: o print não mostra pagamento pendente, pago, cancelado, inadimplente, nem o modal de pagador |
| D2 | Credenciais Cora de **stage** (client_id, certificado, chave) para desenvolver; depois as de produção | o dono gera no Cora Web |
| D3 | Regras de dinheiro: vencimento da 1ª fatura, carência da inadimplência, troca de plano no meio do mês | 3 dias · 7 dias · subir cobra só a partir do próximo ciclo e libera o espaço na hora após pagar a 1ª fatura do plano novo |
| D4 | Quem contrata e quem vê o toast | só o primário contrata; o toast aparece só para ele; os demais veem a mensagem ao serem bloqueados |
| D5 | **Nota fiscal todo mês**: a Cora não emite NFS-e. Precisa de emissor (prefeitura/serviço). Até existir, o cartão "Nota fiscal" fica fora da tela? | **desvio do desenho, precisa de "sim" do dono** |
| D6 | Texto da política de privacidade (Cora como suboperadora) | dono/advogado redige |
| D7 | Arquivo na lixeira (soft delete) conta no espaço? | não conta |
| D8 | Chave PIX da BlueJus para o apoio voluntário | dono informa |
| D9 | Onde mora a tela: aba "Plano e espaço" dentro de `/perfil` (pedido do dono) **e** o item "Quem somos e contribua" do cabeçalho apontando para ela | as duas portas, uma página |

## 10. Etapas (uma migration por PR, nesta ordem)

| Etapa | Entrega | Migration | Depende de |
|---|---|---|---|
| **C1** | `ConsumoDeArmazenamento`, catálogo de planos (seed Free/Essencial/Profissional/Avançado), cota efetiva, decorador de bloqueio, mensagem de recusa | sim (`plano_de_espaco`, `assinatura_do_escritorio`, `tenant.cota_armazenamento_gb`) | D3 (preços já no desenho), D7 |
| **C2** | Aba "Plano e espaço" no perfil + página do desenho + toast com a precedência do §5 + PIX voluntário estático | não | C1, D1, D4, D5, D8, D9 |
| **C3** | `ClienteCora` (stage), contratar, webhook, conciliação, tela de pagamento pendente/pago | sim (`fatura_cora`, `notificacao_cora`) | C1, D2 |
| **C4** | Ciclo mensal, inadimplência, cancelar, trocar plano, e-mails, visão do Master (assinaturas e faturas) | talvez | C3, D3 |
| **C5** | Produção: credenciais montadas, cron no `DEPLOY.md`, URL do webhook cadastrada na Cora, política publicada | não | C4, D2, D6 |

Testes por etapa: unit dos UseCases (Cora com `MockHttpClient`, respostas reais da doc de stage),
functional dos controllers (403, isolamento, webhook repetido, webhook de fatura alheia, upload recusado na
cota). Smoke no navegador ao fim de C2, C3 e C4: do dono.
