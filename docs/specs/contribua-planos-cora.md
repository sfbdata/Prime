# Contribua: espaço pago no cartão, apoio por PIX com a Cora, Quem somos, Suporte e Sugestões

> Spec de risco **ALTO** (mexe em `Tenant`, em dinheiro e em segredo bancário). Fonte: o desenho
> `08 - Contribua - VS 18.10.26.dc.html` do projeto Claude Design `4f6a5f61…`, lido em 19/10/2026 e
> copiado em `docs/design/contribua/`, mais os scripts que ele importa (`bj-plano.js`, `bj-contas.js`,
> `bj-recursos.js`, `bj-lembretes.js`, `bj-idioma.js`). **O desenho manda**; onde ele é omisso, a
> convenção do sistema. Nenhuma linha de código antes das decisões do §10.

## 1. Estado real (medido no master `1d3e664c`)

| O desenho supõe | Existe? | O mais próximo |
|---|---|---|
| Integração Cora / gateway de cartão / webhook | **não** | nenhum mTLS, nenhum controller público de webhook |
| Plano e cota de espaço por escritório | **não** | E3 de `perfis-master-1.0.md` (colunas `cota_armazenamento_gb`, faixas), não iniciada |
| Consumo por escritório | **parcial** | `Master/Repository/PainelMasterRepository::consumoPorEscritorio()` (7 tabelas; fora `documento_demanda`, foto, anexos de ponto e tarefa, imagem do editor) |
| Ponto único de gravação | **sim** | `Shared/Armazenamento/ArmazenamentoDeArquivos::gravar()`; uploads HTTP passam por `Shared/Http/FonteDeUploadHttp::gravarEm()` |
| Página Contribua, Quem somos, Suporte, Sugestões | **não** | nada; `/perfil` é um card único com modais |
| Fila entre pop-ups | **não** | flash, Central flutuante, modal do ponto, Novidades, modal iOS: cada um por si |
| Pagamento na política de privacidade | **removido** | `docs/HANDOFF_POLITICA_PRIVACIDADE.md` |

## 2. O desenho, item a item

Página **"Quem somos e contribua"** (título na barra azul), cabeçalho com o símbolo, "BlueJus, o escritório
digital", subtítulo, e quatro atalhos: **Apoiar o sistema · Comprar espaço · Quem somos · Sugestões**.
Seções na ordem:

### 2.1 Espaço (`#espaco`)
- Cabeçalho com ícone e título que mudam com o uso: `< 80%` "Mais espaço quando o escritório crescer" /
  "O BlueJus continua gratuito. Amplie o espaço só quando precisar, mês a mês." (fundo `#f8f9fa`,
  `bi-hdd-stack`); `≥ 80%` "Seu escritório está com N% do espaço ocupado" / "Amplie agora e continue
  anexando documentos sem interrupção. Nada do que você já guardou é apagado." (fundo `#fff8e6`,
  `bi-exclamation-triangle`); `≥ 100%` "Seu espaço acabou: novos envios estão pausados" (fundo `#fdf1f2`).
- Barra: cor `#0a6fa0` (< 90), `#c08a12` (≥ 90), `#b02a37` (≥ 100); "9,3 GB de 10 GB no plano Free" e
  "93% ocupado".
- Botão **Configurar pagamento · {estado}** só para o **Master** (abre §2.6).
- Quatro pacotes: Free 10 GB grátis "Todos os recursos do BlueJus, sem custo" · Essencial 20 GB
  R$ 19,90 "Advogado autônomo ou escritório pequeno" · Profissional 50 GB R$ 39,90 "Equipes que anexam
  processos e documentos todos os dias" · Avançado 100 GB R$ 69,90 "Alto volume de processos e
  digitalizações". Cada pago mostra "/ mês", "menos de R$ X por dia" (preço/30 arredondado para cima),
  selo "+N GB". **Recomendado para você** = o primeiro pago com `GB ≥ max(cota+1, uso×2)`, borda e botão
  verdes `#1e7b45`. Botões: "Seu plano atual" / "Incluído" / "Menor que o atual" (desabilitados) ou
  "Quero +N GB".
- Quatro garantias: Liberado na hora · Seus arquivos seguros · Sem fidelidade · Nota fiscal (ver D5).

### 2.2 Checkout (modal "Pagamento seguro", 760px)
- Esquerda: "VOCÊ ESTÁ COMPRANDO ESPAÇO", "+N GB de armazenamento", hoje × com o plano, "Assinatura
  mensal · sem fidelidade", subtotal e total mensal, texto "Cobrança mensal no cartão, no mesmo dia de cada
  mês. Sem fidelidade e sem multa: cancele a qualquer momento em Meu Perfil. O espaço vale até o fim do mês
  pago e nenhum arquivo é apagado."
- Direita: **só cartão de crédito**: número (bandeira detectada, Luhn), nome impresso, validade, CVV,
  CPF/CNPJ; botão "Assinar por R$ X/mês"; selos PCI DSS, TLS, 3-D Secure; "Os dados do cartão vão direto
  para o gateway de pagamento ({gateway}) e não ficam guardados no BlueJus."
- Erros do desenho: "Número do cartão inválido.", "Informe o nome como está no cartão.", "Validade
  inválida ou cartão vencido.", "Informe o código de segurança (CVV).", "Informe o CPF ou CNPJ do titular."
- Sucesso: "Pagamento enviado. Assinatura registrada. Assim que o gateway confirmar o pagamento, seu espaço
  passa para N GB. Próxima cobrança em DD/MM/AAAA; recibo e nota fiscal chegam no seu e-mail. Cancele
  quando quiser em Meu Perfil." + **Concluir**.
- Ciclo anual existe no script mas está desligado (`anual = false`): **fora**.

### 2.3 Apoiar o sistema (`#apoie`)
- "Prefere apoiar sem mudar de plano?", "contribuição voluntária por PIX, de qualquer valor".
- Valores R$ 20 · R$ 50 · R$ 100 · Outro valor; QR Code e "copia e cola" gerados **no servidor** a partir
  do BR Code estático (chave CNPJ `66803163000110`, nome `BLUEJUS GESTAO E TECNOLOG`, cidade `BRASILIA`,
  `txid = BJ<numero da conta>`, CRC16) com o selo "PIX válido · conta Nº …".
- Favorecido: BlueJus Gestão e Tecnologia LTDA · CNPJ 66.803.163/0001-10 · Cora SCFI · 403 · Agência
  0001 · C/C 7202371-7 · Chave PIX (CNPJ). Botões copiar chave / dados bancários / código.
- **Já contribuí, registrar meu apoio** (1 por mês por conta; avisa o Master na Central).
- Rodapé: "O BlueJus é gratuito e continua assim. A contribuição é voluntária. Confira o favorecido no
  aplicativo do banco antes de pagar."
- **"Ainda não é o momento de contribuir?"**: 5 motivos (Ainda estou conhecendo o sistema · Sem orçamento
  agora · Prefiro comprar espaço · Não sabia que podia contribuir · Outro motivo), anônimo (só conta Nº),
  some por 30 dias depois de responder ou de apoiar; avisa o Master.

### 2.4 Integração bancária (API), **só Master**
- Modalidade: Integração Direta (só a BlueJus recebe) · Parceria (cada escritório usa o banco: **fora**).
- Banco: Cora SCFI (403) · Banco Inter (077) · Banco do Brasil (001) · Outro (API PIX BACEN). Esta spec
  implementa **só a Cora**; os outros ficam no seletor desabilitados (D9).
- Ambiente sandbox/produção, Client ID, certificado e chave privada ("Enviado direto ao servidor e guardado
  em cofre. O conteúdo nunca aparece na tela nem fica no navegador." Ver D6).
- Recursos (chaves liga/desliga): Cobrança PIX por conta (QR dinâmico por escritório) · Conciliação
  automática · Boleto · Relatório de contribuições no Painel do Master.
- Webhook exibido: `https://app.bluejus.com.br/api/webhooks/cora/pix`. **Testar conexão**, **Salvar
  integração**. Estado: Não conectado / Aguardando servidor / Conectado.
- "Sem a integração, o QR continua funcionando (PIX estático com a identificação da conta)."
- **Testar mensagem de prêmio** (simula a confirmação).

### 2.5 Prêmio do apoiador (modal com confete, quando a Cora confirma um apoio)
"PAGAMENTO CONFIRMADO · Parabéns, {nome}! Você ganhou um prêmio · Recebemos o seu apoio de R$ X."
Benefícios: **Selo Apoiador BlueJus** (perfil e propostas) · **Preferência no Trabalho Externo** (propostas
aparecem primeiro) · **Novidades em primeira mão**. Botões "Ver propostas de correspondência" e
"Aproveitar agora". Aparece uma vez por apoio confirmado.

### 2.6 Pagamento por cartão, **só Master** (modal)
Gateway (Mercado Pago · Stripe · Asaas · Pagar.me), chave pública, link de assinatura por pacote, página de
retorno (`…?assinatura=ok`) para colar no gateway, lista dos últimos pedidos com **Confirmar pagamento**
manual ("Sem webhook, o pedido entra como aguardando confirmação e o Master confirma no painel").

### 2.7 Quem somos, Suporte, Sugestões
- **Quem somos** (recolhível): A EMPRESA, PARA QUE SERVE O SISTEMA, CONTATO (QND 14, Lote 7, Sala 11-A,
  Taguatinga Norte, Brasília/DF, CEP 72.120-140 · bluejus.86@gmail.com · (61) 4104-9958), "Versão 1.0".
- **Suporte e ajuda**: e-mail e telefone.
- **Sugestões e melhorias**: KPIs (recebidas, em aberto, atendidas, apoios registrados), filtro Em aberto /
  Atendidas / Todas, cartões por tipo (Sugestão · Algo atrapalha · O que falta), situação, resposta.
  "Enviar sugestão" abre modal com os 3 tipos e texto (mínimo 8 caracteres: "Escreva um pouco mais para
  entendermos a sua sugestão."). Master: "Marcar atendida" com resposta opcional / "Reabrir". Cada envio
  avisa o Master.

### 2.8 Aviso de espaço em todas as telas (`bj-plano.js`)
- Só o **usuário primário**. A partir do **limite configurável pelo Master (padrão 90%)**, **uma vez por
  dia**: cartão no topo central (top 70px, 720px), borda esquerda `#c08a12`, "Seu espaço está N%
  ocupado", "X GB de Y GB no plano P. Faltam Z GB. Recomendamos aumentar o espaço para não interromper o
  envio de documentos.", barra, **Aumentar espaço** (vai a `#espaco`), × ("Lembrar amanhã").
- Em **100%**: borda `#b02a37`, "Espaço esgotado: novos envios estão pausados", "Consultar e baixar
  arquivos continua liberado.", **sem ×**.
- E-mail ao primário no mesmo gatilho, 1 por dia: "Seu espaço no BlueJus está quase cheio" (limite) e
  "Espaço esgotado: novos envios pausados no BlueJus" (100%).

## 3. Onde mora a página

Rota `/contribua` (domínio `Contribua`), com âncoras `#espaco`, `#apoie`, `#sug`. Portas: item "Contribua"
no menu do usuário (o `bj-recursos.js` registra "Contribua (atalho)") e aba **Plano e espaço** em
`/perfil`, pedida pelo dono, que mostra o plano atual, a próxima cobrança e **Cancelar assinatura** ("cancele
a qualquer momento em Meu Perfil", §2.2). Qualquer usuário logado vê a página; comprar espaço e o cancelar
são do primário (D4); seções Master só para `ROLE_SUPER_ADMIN`.

## 4. Domínio `app/src/Contribua/`

**Entidades** (PK integer):
- `PlanoDeEspaco` (global): `codigo`, `nome`, `cotaGb`, `precoCentavos`, `descricao`, `ordem`, `ativo`,
  `linkGateway`. Seed com os 4 do desenho.
- `AssinaturaDeEspaco` (por tenant): `plano`, `situacao` (aguardando, ativa, cancelada, inadimplente),
  `idNoGateway`, `pagaAte`, `canceladaEm`, `contratadaPor`.
- `PedidoDeEspaco` (por tenant): `plano`, `valorCentavos`, `gateway`, `referenciaExterna`
  (`BJ<conta>-<plano>`, única), `situacao` (aguardando confirmação, pago, recusado), `confirmadoPor`
  (Master, quando manual), `bandeira`, `final4`.
- `Apoio` (por tenant): `valorCentavos`, `informadoPor`, `origem` (informado, cora), `idCora`, `txid`,
  `confirmadoEm`, `premioVistoEm`.
- `MotivoDeNaoApoiar` (por tenant, sem usuário: anônimo para quem lê): `motivo`, `em`.
- `Sugestao` (por tenant): `tipo` (sugestao, problema, falta), `texto`, `autor`, `atendida`, `resposta`,
  `respondidaPor`.
- `ConfiguracaoDePagamento` (global, linha única): gateway, chave pública, limite do aviso (90), estado da
  integração Cora, recursos ligados, ambiente, Client ID. **Segredos fora do banco** (D6).
- `NotificacaoRecebida` (global): `origem` (cora, gateway), `idEvento` único, `tipo`, `idRecurso`,
  `processadaEm`. Idempotência dos webhooks.

**Tenant**: `cota_armazenamento_gb` (E3) vira override do Master; cota efetiva = override ?? plano da
assinatura ativa ?? Free (10 GB). **Número da conta** = `tenant.id` com 6 dígitos (sem coluna nova).

## 5. Cota e bloqueio

- `ConsumoDeArmazenamento::doEscritorio()` sai do `PainelMasterRepository` para o domínio, inclui
  `documento_demanda`, ignora soft delete (D7), cache de 60 s invalidado na gravação.
- Decorador `ArmazenamentoComCota` sobre `ArmazenamentoDeArquivos::gravar()`: recusa quando
  `consumo + tamanho > cota` e o escopo é de tenant. Isentos por parâmetro explícito: escopo global,
  recompressão, comando de acervo. Consulta, download, exportação e exclusão nunca bloqueiam.
- Mensagem: "Espaço esgotado: novos envios estão pausados. Consultar e baixar arquivos continua liberado."
  com link para `/contribua#espaco`.

## 6. Cartão (espaço)

- **Um gateway só nesta entrega** (D2). Tokenização no navegador com a chave pública (SDK oficial do
  gateway, carregado do domínio dele); o BlueJus recebe só o token, cria a assinatura no servidor com a
  chave secreta (env), grava `PedidoDeEspaco` "aguardando confirmação". Nenhum número de cartão, CVV ou
  validade toca o servidor, o log ou o banco.
- Webhook `/api/webhooks/<gateway>`: só aviso; o servidor reconsulta a assinatura no gateway antes de
  mudar qualquer coisa. Pago → `AssinaturaDeEspaco` ativa, `pagaAte` +1 mês, cota sobe, notificação.
  Renovação, recusa e cancelamento atualizam do mesmo jeito.
- Sem webhook: **Confirmar pagamento** do Master (§2.6), com auditoria.
- Cancelar (perfil): cancela no gateway, mantém até `pagaAte`, depois Free. Inadimplência: o gateway
  avisa, carência D3, volta a Free. Nada é apagado.

## 7. Cora (apoio por PIX)

- Sem integração: BR Code estático do §2.3 gerado no servidor (testes do CRC contra o exemplo do desenho).
- Com integração (Master liga): `ClienteCora` com HttpClient mTLS (`local_cert`/`local_pk`; sem
  precedente em `app/`, conferir `symfony.com/doc/7.4/http_client.html`). Token
  `POST https://matls-clients.api.stage.cora.com.br/token`, `grant_type=client_credentials`, vale 24 h,
  sem refresh (cache de 23 h). QR dinâmico por conta via "QR Code Pix v2"; boleto via `POST /v2/invoices/`
  com `Idempotency-Key` gravada antes da chamada (recurso Boleto). URLs de produção: confirmar no painel.
- Webhook `/api/webhooks/cora/pix` (`PUBLIC_ACCESS`): a Cora manda **sem corpo e sem assinatura**, só
  `webhook-event-id`, `webhook-event-type` (`invoice.paid`…) e `webhook-resource-id`. O webhook grava
  `NotificacaoRecebida` (evento repetido é ignorado), responde 200 `{"success": true}` e enfileira no
  Messenger a **reconsulta por mTLS**; só a resposta da Cora confirma o `Apoio`. Conciliação
  `app:cora:conciliar` a cada 15 min cobre aviso perdido.
- Confirmado → `Apoio.confirmadoEm`, prêmio (§2.5) na próxima tela do usuário daquela conta, aviso ao
  Master, selo de apoiador.
- **Testar conexão** = pedir token no ambiente escolhido e mostrar o resultado.

## 8. Pop-ups e comandos existentes (respeitar)

- O aviso de espaço (§2.8) entra no `base.html.twig` sem tirar nada: sino, Central flutuante, modal do
  ponto, Novidades, modal iOS, flashes e atalhos (Ctrl+Y etc.) continuam iguais.
- Precedência mínima, sem refatorar os outros: modal do ponto > modal iOS > prêmio do apoiador > aviso de
  espaço > Central. Quem chega depois espera o `hidden.bs.modal`; o aviso fica no topo central e a Central
  no canto inferior direito, sem se cobrirem.
- Avisos ao Master (sugestão nova, apoio informado, motivo de não apoiar, pedido de espaço) e ao
  primário (pagamento confirmado) saem pelo `NotificacaoService` (sino + Central), tipos novos
  `contribua.*`.
- "Lembrar amanhã" guarda o dia no `localStorage` (no molde do modal do ponto); o e-mail diário fica
  registrado no servidor para não repetir.

## 9. Segurança e permissão

- Master = `ROLE_SUPER_ADMIN` no backend em toda rota de configuração, confirmação manual e
  atender sugestão; teste de 403 para primário e secundário.
- Isolamento: cada escritório só vê os próprios pedidos, apoios e sugestões; Master vê todos. Teste no
  UseCase.
- Segredos (chave secreta do gateway, certificado e chave da Cora) nunca no banco, no repositório nem na
  tela; guarda de segredos do CI cobre `*.pem`, `*.key`, `*.p12`.
- Política de privacidade volta a citar processamento de pagamento (gateway e Cora): texto do dono/advogado.

## 10. Decisões do dono (bloqueiam o início)

| # | Decisão | Proposta |
|---|---|---|
| D1 | A primeira mensagem pedia "integração do banco Cora" para os pagamentos, mas o desenho cobra o espaço **no cartão por gateway** e usa a Cora **só no apoio por PIX**. Confirmar | seguir o desenho |
| D2 | Qual gateway de cartão (o desenho oferece 4) | **Asaas** (brasileiro, assinatura recorrente, também emite NFS-e, o que resolve a D5); alternativa Mercado Pago |
| D3 | Carência da inadimplência antes de voltar ao Free | 7 dias |
| D4 | Quem compra e cancela espaço | só o primário (o aviso já é só dele no desenho) |
| D5 | Garantia "Nota fiscal enviada por e-mail todo mês" precisa de emissor | emitir pelo gateway (Asaas emite NFS-e); senão o cartão sai da tela, **com o seu sim** |
| D6 | O desenho envia o certificado da Cora pela tela, "guardado em cofre". Não temos cofre | **desvio**: certificado montado como arquivo no servidor pelo deploy; a tela mostra só "certificado presente / ausente" e o Client ID. Precisa do seu sim |
| D7 | Arquivo na lixeira conta no espaço? | não |
| D8 | Benefício "Preferência no Trabalho Externo" muda a ordenação das propostas no módulo Trabalho Externo | entra numa etapa própria (C6); o selo e o "primeira mão" entram antes |
| D9 | Bancos Inter, BB e "Outro" no seletor | aparecem desabilitados com "em breve"; só Cora funciona |
| D10 | Credenciais: sandbox do gateway escolhido e da Cora (Client ID, certificado, chave) | o dono gera nos painéis |
| D11 | Texto da política de privacidade | dono/advogado |

## 10.1 Sobreposição com outras frentes (19/10, via sessão coordenadora)

- **Perfis 1.2 "Telas e recursos"** (worktree `perfis-telas-recursos`, empilhada no PR #52): `contribua` é
  uma chave do catálogo; o menu e a rota `/contribua` usam `recurso_visivel('contribua')` quando ela existir.
- **Perfis "limites/suspensão" e "Planos"** encostam no §4 (`PlanoDeEspaco`, `tenant.cota_armazenamento_gb`).
  **Quem é dono do modelo de plano e cota é decisão do dono (D12)**; a coluna não é criada antes disso.
- Arquivos quentes disputados: `base.html.twig` (Perfis, Agenda), `security.yaml` (`push-link-externo`).
  Avisar a coordenação antes de tocar.
- Migrations: há duas pendentes com o mesmo número (`Version20261019120000`, #52 e `push-link-externo`).
  Cada migration desta frente espera a vez combinada com a coordenação.

## 11. Etapas (uma migration por PR)

| Etapa | Entrega | Migration | Depende |
|---|---|---|---|
| **C1** | Cota: catálogo de planos, cota efetiva, consumo, decorador de bloqueio, mensagem | sim | D7 |
| **C2** | Página `/contribua` sem dinheiro: espaço (leitura), Quem somos, Suporte, Sugestões, apoio por PIX estático, "registrar meu apoio", motivos; aba no perfil; aviso de espaço + e-mail | sim | C1, D4 |
| **C3** | Cartão: gateway, checkout tokenizado, webhook, confirmação manual do Master, cancelar, inadimplência | sim | C2, D2, D3, D5, D10 |
| **C4** | Cora: configuração Master, token mTLS, QR dinâmico, webhook, conciliação, prêmio, selo | sim | C2, D6, D9, D10 |
| **C5** | Produção: credenciais montadas, cron no `DEPLOY.md`, webhooks cadastrados, política publicada | não | C3, C4, D11 |
| **C6** | Preferência do apoiador no Trabalho Externo | talvez | C4, D8 |

Testes por etapa: unit dos UseCases (gateway e Cora com `MockHttpClient` e respostas da doc de sandbox; BR
Code contra o CRC do desenho), functional dos controllers (403, isolamento, webhook repetido, webhook de
recurso alheio, upload recusado na cota). Depois de cada etapa visual, conferir o desenho item a item
(✓/✗) e entregar a lista do que olhar na tela; o smoke no navegador é do dono.
