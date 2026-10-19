# Contribua C4: apoio por PIX com a Cora (integração bancária do Master)

> Risco **ALTO** (dinheiro e segredo bancário). Parte de `docs/specs/contribua-planos-cora.md` (§2.4, §2.5,
> §7, §9, D6, D9, D10), cujo dono é a sessão f6. Desenho: `docs/design/contribua/08 - Contribua - VS
> 18.10.26.dc.html`, linhas 54-101 (apoio e integração bancária) e 192-203, 249-256, 285-291 (lógica e prêmio).
> O desenho manda; onde ele é omisso, a convenção do sistema.

## Estado da frente (atualizar a cada PR, decisão ou contrato; reler depois de compactação)

Atualizado em 19/10/2026. Sessão 4b, a pedido do coordenador; dona do Contribua: f6.

**Pilha:** branch `contribua-pix`, base `contribua-c3` (f6, commit eefecfef), que fica sobre `contribua-c2` (#74) e
`contribua-cora` (#64). PR da C4 empilhado sobre o da C3, aberto depois dele.
**Migration:** `Version20261019230000` (`contribua_integracao_bancaria`, `contribua_cobranca_pix` e colunas
de `contribua_apoio`), na fila logo depois da `Version20261019220000` da C3 (a tabela dos avisos recebidos).
Branch atualizada com o origin/master e com a C3 até 38624ff8 (19/10/2026).

**Contrato com a f6 (19/10/2026)**
- Só o domínio `App\Contribua`. Não mexer em: `ArmazenamentoComCota`, `SituacaoDoEspaco`, `Plano`,
  `EspacoDoEscritorio`, `ContribuaController`, `contribua/index.html.twig`, `public/js/contribua.js`,
  `public/css/contribua.css`. Dado novo na página: pedir à f6 (no `MontarPaginaDoContribuaUseCase`).
- Pontos de inclusão (já na C3): `contribua/_integracao_bancaria.html.twig` (só Master, entre #apoie e
  Quem somos) e `contribua/_premio.html.twig` (fim da página, antes do toast). JS e CSS próprios:
  `public/js/contribua-cora.js` e `public/css/contribua-cora.css`.
- `Apoio` ganha colunas (`origem`, `id_cora`, `txid`, `premio_visto_em`); continua `Auditavel`.
- O PIX estático (`CodigoPixEstatico`, txid `BJ<conta>`) continua o caminho sem integração.
- Interface de banco PIX `App\Contribua\Banco\BancoPix`, adaptador Cora agora; Inter, BB e "Outro" (API
  PIX BACEN) depois (C4b, C4c).
- `security.yaml`: a f6 pôs na C3 a regra única `^/api/webhooks/` (commit 7ec1e2de). Não mexer.
- Aviso ao Master pelo `App\Contribua\Service\AvisoAoMaster`.
- Depois da C4: adaptadores de cartão (Mercado Pago, Stripe, Pagar.me) sobre `GatewayDeAssinatura`, um PR
  por gateway; mudar a interface só avisando a f6 antes.

**Respostas da f6 (19/10/2026, §8):** P1 decorar `App\Contribua\Service\CodigoPixDaConta` (#[AsDecorator]),
o estático é o decorado; P2 usar `NotificacaoRecebida` da C3 (`registrarSeNova` com ON CONFLICT, origem
`cora`, `marcarProcessada`); P3 prêmio só na página Contribua, pelo parcial `_premio.html.twig`.
**Material do dono (D10):** Client ID, certificado e chave da Cora (stage e produção), a chave de cifra
`CONTRIBUA_CRYPTO_KEY` de produção, o cadastro do webhook e o cron da conciliação (C5).
**Pendências fora desta entrega:** Boleto e Relatório de contribuições (chaves do desenho, gravadas desde
já; funcionamento na C4d); Inter, BB, Outro (C4b/C4c); selo de apoiador no perfil e nas propostas (C6).
**Feito:** camada de banco, cobrança por conta (decorador), webhook, reconsulta, confirmação, conciliação,
tela do Master, prêmio, migration (`Version20261019230000`), testes; revisão com bloqueantes corrigidos (§2b);
verificado no navegador contra o 08.
**Próximo passo:** re-revisão; PR empilhado sobre a C3.

## 1. O que o desenho pede

**Integração bancária (Master, L80-101, L249-256):** cabeçalho "Integração bancária (API)" com o selo de
estado (Não conectado / Aguardando servidor / Conectado); modalidade (Integração Direta / Parceria); banco
(Cora SCFI (403), Banco Inter (077), Banco do Brasil (001), Outro (API PIX padrão BACEN)); ambiente
(Testes (sandbox) / Produção); Client ID; certificado e chave privada (arquivo, "Enviado direto ao servidor
e guardado em cofre. O conteúdo nunca aparece na tela nem fica no navegador."); quatro chaves (Cobrança PIX
por conta, Conciliação automática, Boleto, Relatório de contribuições); endereço do webhook com copiar;
Testar conexão; Salvar integração; rodapé; botão "Testar mensagem de prêmio".
**Prêmio (L192-203, L285-290):** modal "PAGAMENTO CONFIRMADO", "Parabéns, {nome}! Você ganhou um prêmio",
"Recebemos o seu apoio de {valor}. Obrigado por ajudar o BlueJus a crescer!", três benefícios, botões "Ver
propostas de correspondência" e "Aproveitar agora". Um por apoio confirmado.

## 2. Decisões técnicas (decisor técnico)

- **Configuração** numa linha global `contribua_integracao_bancaria` (`IntegracaoBancaria`): modalidade,
  banco, ambiente, client_id, recursos (json), estado, último teste (quando e resultado), nomes dos
  arquivos enviados, e o certificado e a chave **cifrados** (`certificado_cifrado`, `chave_cifrada`).
  Fica fora da auditoria (técnica, com segredo), e os nomes dos campos cifrados entram na lista de campos
  nunca auditados por garantia.
- **Cofre:** `App\Contribua\Banco\CofreDeCertificado`, `sodium_crypto_secretbox` com chave própria
  `CONTRIBUA_CRYPTO_KEY` (base64, 32 bytes), separada da do Drive para não amarrar a rotação. D6 (dono)
  manda guardar cifrado; o "nunca no banco" do §9 da spec mãe vale para o segredo em claro: no banco fica
  só o blob, e a chave mora no ambiente. A tela mostra os nomes dos arquivos (como o desenho) e
  presente/ausente; o conteúdo nunca volta ao navegador.
- **Arquivos aceitos:** `.pem`, `.crt`, `.key`, e `.p12`/`.pfx` sem senha (convertidos para PEM com
  `openssl_pkcs12_read`). Validação ao enviar: o certificado lê (`openssl_x509_read`), a chave lê, e um
  bate com o outro (`openssl_x509_check_private_key`); senão, recusa com mensagem.
- **mTLS:** `ClienteCora` (Symfony HttpClient) decifra para dois arquivos temporários 0600, passa em
  `local_cert`/`local_pk` em **toda** chamada (a Cora exige em todas) e apaga no `finally`.
- **Bases:** stage `https://matls-clients.api.stage.cora.com.br/`, produção
  `https://matls-clients.api.cora.com.br/` (developers.cora.com.br/docs/instrucoes-iniciais).
- **Token:** `POST /token` (`grant_type=client_credentials&client_id=...`), vale 86400 s, sem refresh;
  guardado no cache da aplicação, cifrado, por 23 h, com a chave do cache pelo ambiente + Client ID. 401 em
  qualquer chamada apaga o token guardado e tenta uma vez de novo.
- **Interface `BancoPix`:** `codigo()`, `testarConexao(): ResultadoDoTeste`, `criarCobranca(PedidoDeCobrancaPix):
  CobrancaPix` (id do banco, nosso código, copia e cola, vencimento), `consultar(idDoBanco): SituacaoDaCobranca`
  (paga, valor pago, quando), `interpretarAviso(Request): ?AvisoDoBanco` (o id do recurso). Registro dos
  bancos por código; só `cora` agora.
- **Gatilho (P1):** `CodigoPixDaContaDinamico` decora `CodigoPixDaConta`: devolve o copia e cola da Cora
  quando vale e o do decorado (estático) quando não.
- **Cobrança por conta (QR dinâmico):** `POST /v2/invoices/` com `Idempotency-Key` (gravada antes da
  chamada), `code` = `BJ<conta>-<id da cobrança local>` (vai também para `Apoio.txid`), `customer` = o
  escritório (nome até 60, CNPJ), `services` = um item "Apoio ao BlueJus" com o valor, `payment_terms.due_date`
  = hoje + 7 dias, `payment_forms ["PIX"]`. A resposta dá `id` (`inv_...`) e `pix.emv` (copia e cola); o
  QR continua desenhado no navegador a partir do código, como hoje. Tabela `contribua_cobranca_pix` (conta,
  valor, id_cora, código, copia e cola, vencimento, situação, criada_em). Reaproveita a cobrança aberta da
  mesma conta e valor que ainda não venceu (trocar R$ 20/50/100 não cria cobrança nova a cada clique).
- **Cai no PIX estático** (o caminho de hoje) quando: integração desligada, recurso "Cobrança PIX por
  conta" desligado, escritório sem CNPJ (a Cora exige o documento e o usuário não tem CPF), valor abaixo de
  R$ 5 (mínimo da Cora) ou a Cora falhou. O apoio continua possível; só não se confirma sozinho.
- **O `Apoio` só nasce quando a Cora confirma** (origem `cora`, `id_cora` único, `txid` = nosso código,
  `confirmadoEm` = quando pagou). Assim a trava "1 por mês" do "Já contribuí" (`apoiouDesde`) não é afetada
  por cobrança aberta e não paga. O "Já contribuí" grava origem `informado`.
- **Webhook** `POST /api/webhooks/cora/pix` (público pela regra da C3): lê só os cabeçalhos
  `webhook-event-id`, `webhook-event-type`, `webhook-resource-id` (a Cora manda sem corpo e sem assinatura);
  grava o aviso em `NotificacaoRecebida` da C3 (`registrarSeNova`, origem `cora`: repetido é ignorado); responde 200 `{"success": true}` na
  hora; enfileira `ReconsultarCobrancaCora(idCora)` (Messenger async). Recurso que não é cobrança nossa é
  ignorado sem erro. Nada se confirma pelo aviso: só a reconsulta mTLS (`GET /v2/invoices/{id}`, `status
  PAID`) confirma.
- **Confirmação** (idempotente pelo índice único em `id_cora`): cria o `Apoio` confirmado, marca a cobrança
  paga, avisa o Master pelo `AvisoAoMaster` ("Apoio confirmado pelo banco: conta Nº X, R$ Y."); o prêmio
  aparece na próxima visita de alguém da conta (`premio_visto_em` nulo).
- **Conciliação** `app:cora:conciliar` (cron do dono a cada 15 min, C5): reconsulta as cobranças abertas
  dos últimos 30 dias; vencidas há mais de 30 dias saem da varredura.
- **Testar conexão:** pede o token no ambiente escolhido; sucesso marca "Conectado" e "Conexão com a Cora
  confirmada."; falha volta a "Não conectado" com "A Cora recusou a conexão: <motivo curto>." (o desenho
  não tem o texto do resultado; o "no protótipo" do toast do desenho não vai para o sistema).
- **Salvar:** exige Client ID ("Informe o Client ID fornecido pelo banco."); com certificado, estado
  "Aguardando servidor" e "Integração salva. Ela é ativada quando o servidor validar o certificado."; sem,
  "Não conectado". Testar sem Client ID ou certificado: "Informe o Client ID e envie o certificado antes de
  testar." Copiar o webhook: "Endereço copiado." (o desenho cai no toast do PIX por engano; convenção).
- **Seletores:** Inter, BB e Outro aparecem e ficam desabilitados (D9, C4b/C4c); "Parceria" aparece e fica
  desabilitada (§2.4: fora). O endereço do webhook segue o banco (`/api/webhooks/<banco>/pix`).
- **Testar mensagem de prêmio (Master):** só mostra o modal com um apoio de exemplo (R$ 20,00 ou o último
  confirmado), **sem gravar nada**: no protótipo o botão cria um apoio confirmado local, mas aqui isso
  inventaria dinheiro no extrato.
- **Prêmio:** parcial `_premio.html.twig` com os textos e cores do desenho; os dois botões marcam
  `premio_visto_em` (POST com CSRF); "Ver propostas de correspondência" vai ao Trabalho Externo. Precedência
  (§8 da spec mãe): espera outro modal aberto fechar.
- **Acesso:** toda rota de configuração exige `ROLE_SUPER_ADMIN` (Master, §9); a do prêmio, usuário logado
  com vínculo na conta do apoio.

## 2b. Decisões da revisão (decisor técnico, 19/10/2026)

- **Ambiente de testes isolado:** a cobrança guarda o ambiente em que nasceu e só é servida, reaproveitada,
  consultada e conciliada nele. Em "Testes (sandbox)", o PIX dinâmico só vale para o usuário Master (os outros
  usuários, inclusive do escritório dele, e as contas seguem no estático). Pagamento confirmado em sandbox não cria `Apoio`: a cobrança fica paga e o
  Master recebe "Teste da Cora (sandbox): pagamento confirmado ... Nenhum apoio foi registrado."
- **Um pagamento, um apoio:** se a conta clicou em "Já contribuí" depois de a cobrança existir (apoio informado
  ainda não confirmado), é esse apoio que o banco confirma (origem, id, código, valor recebido); senão nasce um.
- **O código que o banco devolve tem de ser o da cobrança;** o valor do apoio é o que o banco recebeu
  (limitado ao teto do `Apoio`).
- **Webhook:** só uma cobrança nossa gera registro e fila; aviso com recurso desconhecido responde 200 sem
  efeito (o endereço é público e sem assinatura; não enche a fila `async`, que o Drive e a Inteligência usam).
  Limite de requisições por origem fica para quando houver limitador no `framework.yaml` (arquivo quente).
- **Página:** no máximo 10 cobranças novas por conta por dia; valor acima do teto do `Apoio` cai no estático;
  falha passageira do banco (rede, 5xx, 401, 403, 429) põe uma pausa de 5 min para todos, e a cobrança sem
  código é refeita depois com a mesma chave de idempotência; recusa do banco para o pedido de uma conta (400)
  cancela só aquela cobrança. Cobrança que já tem código continua valendo durante a pausa. Testar a conexão
  com sucesso tira a pausa.
- **Conciliação** roda com certificado, Client ID e "Conciliação automática" ligada, no ambiente atual, mesmo
  com a cobrança por conta desligada.
- **Aviso ao escritório:** o "pagamento confirmado" do §8 da spec mãe é o da assinatura no cartão (C3). No
  apoio por PIX, quem confirma ao escritório é o modal do prêmio na página (desenho 08); não há aviso no sino
  do primário (a f6 deixou a decisão para a C4; o desenho não tem).

## 3. Testes

Unit: cofre (cifra e recusa adulterado), validação do certificado (par certo, par trocado, p12), ClienteCora
com MockHttpClient e as respostas da doc (token, invoice, consulta, 400 de valor mínimo, 401 refaz o token),
regra do estático, reaproveitamento da cobrança. Functional: configuração só Master (403 para os outros),
salvar/testar, webhook (aviso novo enfileira; repetido ignora; recurso alheio ignora; sem cabeçalho 200 sem
efeito), confirmação idempotente (duas reconsultas), prêmio (aparece uma vez, some depois de visto, só para a
conta do apoio), Testar mensagem não grava. Integração: conciliação.

## 4. Material do dono

Gerar `CONTRIBUA_CRYPTO_KEY` (`php -r 'echo base64_encode(random_bytes(32));'`) no `.env.prod`; criar a
integração direta na Cora (app ou Cora Web) e baixar certificado e chave de stage; enviar pela tela; Testar
conexão; cadastrar o webhook `POST /endpoints` (`resource: invoice`, `trigger: paid`, url
`https://app.bluejus.com.br/api/webhooks/cora/pix`); pagar uma cobrança em stage (`POST /v2/invoices/pay`);
depois, produção (credenciais próprias). Cron `app:cora:conciliar` a cada 15 min (C5).

## 8. Perguntas à f6

- **P1 gatilho do QR dinâmico:** o `contribua_pix` (seu controller) responde o código a cada troca de valor.
  Proposta: ele passa a chamar `App\Contribua\UseCase\CodigoPixDaContaUseCase` (meu), que devolve o
  dinâmico quando a integração vale e o estático senão (mesma resposta `{codigo, valido}`). Uma linha no seu
  controller. De acordo?
- **P2 aviso recebido comum:** a C3 vai criar `NotificacaoRecebida` (origem, id do evento único, tipo,
  recurso, processada_em)? Se sim, uso a sua; senão, crio na minha migration com esse formato e você usa.
- **P3 prêmio:** o desenho mostra o modal na própria página Contribua e o §7 diz "na próxima tela do
  usuário". Sigo o seu ponto de inclusão (só na página), ou o prêmio vai por um parcial global
  (`layout/_globais/`)?
