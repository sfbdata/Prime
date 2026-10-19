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

## 5. Cota e bloqueio (C1, implementada)

Modelo real da C1 (substitui o rascunho do §4 para plano e espaço; nenhuma coluna em `tenant`, que é
legado de risco ALTO):

| Tabela | Entidade | O que guarda |
|---|---|---|
| `contribua_plano` | `Plano` (global) | código, nome, cota em GB, preço em centavos, descrição, ordem, `limite_usuarios` (vazio), ativo. Carga: os 4 do 08 |
| `contribua_espaco_do_escritorio` | `EspacoDoEscritorio` (1 por tenant) | plano, `cota_gb` própria do Master, `sem_limite`, `limite_usuarios` próprio, justificativa, quem alterou. Sem linha = Free |
| `contribua_configuracao` | `ConfiguracaoDoEspaco` (linha única) | faixas 70/90/95/100 (05: Atenção, Alerta, Crítico, Bloqueio) e `bloqueio_ativo` (nasce **false**) |

- Limite efetivo: `sem_limite` > `cota_gb` do Master > cota do plano. Leitura pública:
  `SituacaoDoEspaco::doEscritorio(tenantId)` → `RetratoDoEspaco` (usados, limite, %, faixa, origem, plano,
  limite de usuários e origem, bloqueio ativo).
- Consumo: `ConsumoDeArmazenamentoRepository::bytesDoEscritorio()`, as mesmas 7 tabelas do Painel do Master
  com a mesma regra (**D7 resolvida pela convenção existente**: documento de pasta na lixeira não conta,
  pasta em lápide conta). Fora: Trabalho Externo (documento de demanda entre escritórios, sem dono único),
  foto, anexos de ponto e tarefa, imagem do editor (sem tamanho no banco).
- Ponto único: `ArmazenamentoComCota` (`#[AsDecorator]` sobre `ArmazenamentoDeArquivos`). Como a
  interface passou a ter duas implementações, o alias para o `ArmazenamentoLocal` está explícito em
  `config/services.yaml` (o núcleo não pode usar atributo do Symfony, INV-8). Só `NovoArquivo` de escopo
  tenant **e de categoria cobrada** (as que a soma conta: documento de pasta, de cliente, de cobrança,
  anexo de chamado e do Kanban); justificativa de ponto, Trabalho Externo, imagem do editor, anexo de
  tarefa e foto passam sem bloqueio nem medição ("não se recusa o que não se conta"; a justificativa de
  ponto nunca pode ficar travada). Recusa só com o bloqueio ligado, a partir da faixa Bloqueio aplicada
  ao limite (100 = o próprio limite; o Master pode antecipar, como o 05 permite). Recusa antes se já está no limite; senão grava,
  mede e, se passou, remove o arquivo recém-cunhado (pela `RemocaoAposTransacao` sobre o interno) e recusa.
  Uso medido memorizado por requisição (`ResetInterface`).
- Resposta (`EspacoEsgotadoListener`): JSON 422 com `error`/`erro`/`mensagem`/`message` e
  `success`/`ok`/`sucesso` falsos quando o pedido vem de JS (`X-Requested-With`, `X-CSRF-Token` ou Accept
  JSON); formulário comum volta à origem (só mesma origem) com flash `warning`. Mensagem do 08:
  "Espaço esgotado: novos envios estão pausados. Consultar e baixar arquivos continua liberado."
- Sync do Drive: recusa interrompe a importação da pasta (não baixa os arquivos seguintes à toa). Comando
  de acervo: segue a mesma cota (com o bloqueio desligado ou o escritório sem limite, nada muda).
- Kanban: o JS do mural não mostra erro de upload hoje; a mensagem só aparece lá quando a C2 tocar o JS.
- Purga de escritório apaga `contribua_espaco_do_escritorio`.
- `EspacoDoEscritorio` **não é TenantAware** (revisão da C1, decisão B): o filtro da sessão fazia o
  Master no escritório X ver o Y como Free e quebrar a unicidade ao gravar. Todo acesso é por tenantId
  explícito no repositório, e `EspacoDoEscritorioSoNoContribuaArquiteturaTest` impede consulta fora dele.
- **Condição antes de a b5 ligar a tela do Master:** envolver as três escritas em
  `EscopoDeAuditoriaDePlataforma::executar()` (chega com o #52). Sem isso o `audit_log` grava a mudança
  no escritório da sessão do Master e a expõe na auditoria dele.
- Limites aceitos e registrados no decorador: não é atômico entre requisições simultâneas (passa no
  máximo um arquivo); arquivo comprimido depois de gravado conta pelo tamanho cru naquela requisição.
- Evento de domínio `EspacoAtingiuFaixa` (tenantId, faixa, faixa anterior, retrato): o decorador o
  dispara quando um envio faz o escritório subir de faixa, com o bloqueio ligado ou não. Os e-mails do
  desenho 05 ("Comercial e e-mails": 90%, 100%, pedido de espaço) só ouvem o evento; o Contribua não manda
  e-mail direto. É disparado antes do COMMIT de quem gravou: o ouvinte enfileira e não supõe o documento
  no banco.
- Escrita do Master (contrato com a b5, Perfis): `DefinirLimiteDeArmazenamentoUseCase` (GB ou sem limite,
  justificativa obrigatória, nunca abaixo do uso, texto do 05), `DefinirLimiteDeUsuariosUseCase` (nunca
  abaixo dos cadastrados: "Menor que os usuários já cadastrados."), `ConfigurarFaixasDoEspacoUseCase`.
  Autorização `ROLE_SUPER_ADMIN` é do controller da b5. Auditoria: depois do merge do #52, envolver as três
  escritas em `EscopoDeAuditoriaDePlataforma::executar()` (pedido da b5).
- **Decisão do dono (19/10): publicar com o bloqueio DESLIGADO**, sem alterar dado de nenhum escritório em
  produção (o de 24 GB inclusive: com a chave desligada ninguém é bloqueado). A tela do Master que liga a
  chave (b5) mostra o aviso: "Antes de ligar, confira os escritórios acima do plano e defina sem limite ou
  uma cota própria."
- **Convenções sem desenho** (registradas, aprovadas pela coordenação): "sem limite" por escritório e a
  chave liga/desliga do bloqueio. Motivo medido: em produção o único escritório com arquivos usa 24,29 GB;
  com o Free de 10 GB ele seria bloqueado no deploy.

## 5.1 Página e aviso (C2)

- Página própria `/contribua` (`contribua_index`), como no 08, com o item "Contribua · PIX" no menu do
  avatar (desenho 03) e o título "Quem somos e contribua" na barra azul (linha autorizada pela 7d no
  `base.html.twig`). Não há aba no Meu Perfil: o 07 não tem; o bloco "Assinatura" do Meu Perfil (plano,
  vencimento, cancelar) entra na C3, no ponto de inclusão do #60 (decisão da coordenação, 19/10).
- Sugestões: cada escritório vê as próprias; o Master vê as de todos (pela porta `VisaoDoMaster`, que
  suspende o filtro de tenant só no callback) e marca como atendida com resposta. Números (recebidas, em
  aberto, atendidas, apoios): do escritório; para o Master, de todos.
- Apoio informado: um por escritório a cada 30 dias, como no desenho; avisa o Master. Motivo de não
  apoiar: anônimo (só a conta), um a cada 30 dias.
- PIX estático gerado e validado no servidor (`CodigoPixEstatico`, testado byte a byte contra a função
  `pix()` do desenho); QR desenhado no navegador com o `qrcode-generator@1.4.4` do desenho.
- Conta Nº = id do escritório com 6 dígitos; txid `BJ<conta>`.
- "Quero +N GB": na C2 registra o pedido em lista de espera e avisa o Master (caminho `coPedir` do
  próprio desenho para gateway não configurado). Na C3 vira o checkout de cartão.
- Aviso de espaço: parcial `layout/_globais/aviso_espaco.html.twig` (ponto de inclusão da 7d), só para o
  primário, a partir da faixa Alerta, uma vez por dia; "Espaço esgotado" sem fechar só com o bloqueio
  ligado e a faixa Bloqueio atingida. Espera qualquer modal aberto (`hidden.bs.modal`).
- Cores do cabeçalho do espaço e do aviso pelos limiares literais do desenho (80, 90, 100). **Estado
  bloqueio desligado acima de 100% usa a variante de uso alto do desenho** ("Seu escritório está com N% do
  espaço ocupado", aviso com ×), mantendo o vermelho: "novos envios estão pausados" só aparece com o
  bloqueio ligado e a faixa Bloqueio atingida, porque com ele desligado seria falso (coordenação, 19/10).
- E-mail de 90%/100% (§2.8): fica com a frente de e-mails, que ouve o evento `EspacoAtingiuFaixa`
  (coordenação, 19/10). A C2 não manda e-mail.
- Auditoria: `Apoio` é auditável; `MotivoDeNaoApoiar` fica fora de propósito (a trilha gravaria quem
  respondeu e desfaria o anonimato); `Sugestao` fica fora até o escopo de auditoria de plataforma (#52),
  porque o Master a atende de outro escritório e a trilha gravaria no escritório da sessão dele.
- O aviso ao Master nunca derruba a ação: título cortado em 255 e falha vira log. O aviso global de
  espaço nunca derruba a página: falha vira log e o aviso some.
- Limites aceitos: "um apoio por mês" não tem trava entre dois cliques simultâneos (dois apoios e dois
  avisos no pior caso); a soma de consumo roda em toda página do primário (uma consulta indexada por
  escritório).
- Ficam para a C3/C4, com as funções: "Configurar pagamento" (Master), checkout, Integração bancária,
  "Testar mensagem de prêmio" e o prêmio do apoiador.
- Avisos ao Master saem no sino do escritório de origem quando o Master tem vínculo com ele; senão no
  primeiro escritório do Master.

## 6. Cartão (espaço): quatro gateways atrás de uma interface

O desenho (§2.2 e §2.6) tem o seletor GATEWAY com **Mercado Pago (Assinaturas), Stripe (Payment Links),
Asaas (Link de pagamento recorrente) e Pagar.me (Link de pagamento)**, a chave pública, um "Link do
pacote" por plano pago e a "Página de retorno". Pela regra do dono, os quatro entram. A ordem dos
adaptadores segue o gateway em que o dono abrir conta primeiro (só ordem e credenciais, não escopo).

**Interface `GatewayDeAssinatura`** (domínio Contribua), no modelo que o desenho mostra:
- `linkDoPacote(Plano, referencia)`: o link de assinatura mensal cadastrado pelo Master para o plano,
  com a referência externa `BJ<conta>-<plano>` (o desenho grava `external_reference` e
  `client_reference_id`);
- `assinarComToken(Plano, tokenDoCartao, pagador, referencia)`: o checkout do desenho é um formulário
  de cartão na própria tela; o número vira token **no navegador**, com a chave pública e o SDK oficial
  do gateway; o servidor só recebe o token. Gateway que não aceitar token cai no `linkDoPacote`;
- `consultar(referencia | idNoGateway)`: a situação da assinatura e do último pagamento, lida do
  gateway (fonte da verdade);
- `interpretarAviso(Request)`: o webhook de cada gateway vira um aviso neutro (qual assinatura mudou).
  O aviso nunca muda nada sozinho: dispara `consultar()`;
- `cancelar(idNoGateway)`.

**Adaptadores**, uma etapa cada, na ordem decidida pelo dono em 19/10: **C3a Asaas** (primeira conta,
teste de ponta a ponta no sandbox), depois C3b Mercado Pago, C3c Stripe e C3d Pagar.me, pela mesma
interface. Mais um **adaptador falso** para os testes, que já entra na C3.

**Nota fiscal mensal** (garantia do desenho):
- Asaas: NFS-e automática da assinatura (`POST /v3/subscriptions/{id}/invoiceSettings`; webhooks
  `INVOICE_AUTHORIZED` e `INVOICE_ERROR`). O e-mail da nota é o do pagador.
- Mercado Pago, Stripe e Pagar.me não emitem NFS-e brasileira pela assinatura: **pendência de cada
  adaptador** (emissor próprio ou serviço de NFS-e), resolvida antes de ligar o gateway em produção.

**Fluxo comum (C3):**
- "Quero +N GB" abre o checkout do §2.2 e grava `PedidoDeEspaco` "aguardando confirmação".
- A página de retorno (`/contribua?assinatura=ok`) marca "pago, aguardando confirmação do gateway".
- O webhook `/api/webhooks/<gateway>` ou a conciliação periódica chama `consultar()`. Pago →
  `EspacoDoEscritorio` muda de plano, a cota sobe ("Liberado na hora") e o primário é notificado.
  Renovação, recusa e cancelamento seguem o mesmo caminho.
- Sem webhook: **Confirmar pagamento** do Master na lista de pedidos (§2.6), com auditoria.
- Cancelar (Meu Perfil): cancela no gateway, mantém o plano até o fim do mês pago e depois volta ao Free.
  Inadimplência: carência D3 e volta ao Free. Nada é apagado.
- Nenhum número de cartão, CVV ou validade toca o servidor, o log ou o banco.
- Nota fiscal mensal (garantia do desenho): entra. O caminho depende do gateway (D5).

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

## 10. Decisões (estado em 19/10)

| # | Decisão | Proposta |
|---|---|---|
| D1 | Cora para o espaço? | **Resolvida**: a API da Cora só tem boleto, PIX e webhooks (developers.cora.com.br/llms.txt); o link de cartão da Cora existe só no aplicativo (avulso, validade de 7 dias, sem API e sem recorrência), então não serve para assinatura. O desenho (cartão por gateway, Cora no PIX) é compatível com o pedido |
| D2 | Em qual gateway abrir conta primeiro | **Resolvida pelo dono**: Asaas (C3a). Os quatro continuam no escopo |
| D3 | Carência da inadimplência | **Resolvida pelo dono (19/10)**: 7 dias, configurável pelo Master (padrão 7) |
| D4 | Quem compra e cancela espaço | **Resolvida**: o primário, como no desenho |
| D5 | Nota fiscal mensal | **Resolvida para o Asaas** (NFS-e da assinatura, §6); pendência por adaptador nos outros três |
| D6 | Certificado da Cora pela tela | **Resolvida**: segue o desenho; o arquivo é guardado cifrado (sodium, chave de ambiente ou Symfony secrets) e a tela mostra presente/ausente. A chave de produção é segredo do dono |
| D7 | Lixeira conta no espaço? | **Resolvida** pela convenção do Painel do Master (§5) |
| D8 | Preferência no Trabalho Externo | **Resolvida**: etapa C6, contrato com a sessão a7 antes |
| D9 | Inter, BB e "Outro" | **Resolvida pelo dono**: tudo que está no desenho entra. Etapas C4b/C4c atrás de uma interface de banco comum à Cora |
| D10 | (dono) Credenciais: sandbox do gateway escolhido e da Cora (Client ID, certificado, chave) | o dono gera nos painéis |
| D11 | Texto da política de privacidade | dono/advogado (rascunho será entregue) |
| D12 | Quem é dono do modelo de plano e cota | **Resolvida**: o Contribua; o Painel do Master (b5) administra pelos UseCases do §5 |
| D13 | Preços: 05 (R$ 189/389, com usuários) × 08 (R$ 19,90/39,90/69,90) | **Resolvida**: carga segue o 08; limite de usuários do 05 existe, vazio. Levado ao dono como confirmação |

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
| **C3** | Cartão, parte comum: interface `GatewayDeAssinatura`, adaptador falso, checkout, pedidos, retorno, webhook, conciliação, confirmação manual do Master, cancelar, inadimplência | sim | C2, D3 |
| **C3a** | Adaptador Asaas (assinatura, token, webhook, NFS-e), testado com MockHttpClient e depois no sandbox | não | C3, D10 |
| **C3b a C3d** | Mercado Pago, Stripe, Pagar.me, pela mesma interface; nota fiscal de cada um | não | C3, D10 |
| **C4** | Cora: configuração Master, token mTLS, QR dinâmico, webhook, conciliação, prêmio, selo | sim | C2, D6, D9, D10 |
| **C5** | Produção: credenciais montadas, cron no `DEPLOY.md`, webhooks cadastrados, política publicada | não | C3, C4, D11 |
| **C6** | Preferência do apoiador no Trabalho Externo | talvez | C4, D8 |

Testes por etapa: unit dos UseCases (gateway e Cora com `MockHttpClient` e respostas da doc de sandbox; BR
Code contra o CRC do desenho), functional dos controllers (403, isolamento, webhook repetido, webhook de
recurso alheio, upload recusado na cota). Depois de cada etapa visual, conferir o desenho item a item
(✓/✗) e entregar a lista do que olhar na tela; o smoke no navegador é do dono.
