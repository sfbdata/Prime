# Painel do Push, parte C: "Avisar cliente" e envio manual da Análise IA (P4c)

## Estado da frente (sessão bluejus-0d; atualizado 19/10/2026, noite)

Releia esta seção antes de continuar se a sessão for compactada.

**Pedido do dono (19/10):** implementar na íntegra o desenho das abas Processo e Push Processual (02 - EXPEDIENTES,
versão completa de 18/10 em `docs/design/redesign-19-10-26/`, fora do git), mesmo que precise de frente nova.

**PRs e ordem de merge (fila de migrations do coordenador):**
- #55 link externo do Push: MERGEADO.
- #68 `pasta-ocultar-aba`: base master. Migration Version20261019200000 (renomear na vez: depois de #56, #60, #65,
  #69, #64, #74, #62, #83, #84).
- #75 `relatorio-processos-controladoria`: base master. Migration Version20261019233000.
- #82 `push-painel-monitoramento` (P4a): base master. Migration Version20261019234500.
- #87 `push-avisar-quando-chegar` (P4b): EMPILHADO sobre o #82, pronto. Migration Version20261020090000. A 5d
  (comercial-1a) empilha sobre ele (descadastro em Shared).
- #91 `processo-apensados` (P4f): base master. Migration Version20261020103000 (renomeada: a 5d tem a 20261020100000).
- P4c `push-avisar-cliente`: PR aberto, EMPILHADO sobre o #87. Migration Version20261020110000. Controladoria e envio
  isolados em interfaces: trocar pela coluna do #75 e pelo Carteiro da 5d quando entrarem no master.
- P4d (envio automático ao cliente, pelo Carteiro da 5d) e P4e (arquivos, OCR, análise completa): specs no
  scratchpad; P4e espera o #76 (composer) e aviso de Dockerfile.
Depois de cada merge: trazer o origin/master, migrar o banco de teste da frente (APP_ENV=test, TEST_TOKEN da frente),
suíte, push. Renomear a migration na vez dela.

**Decisões do dono (19/10/2026):** Controladoria = pessoas marcadas (só Master/primário marca); ocultar aba organiza a
pasta e não restringe o sistema; renovação do link por aviso no sino e link curto ativo; envios automáticos ao cliente
nascem desligados; pausar monitoramento para só a busca por número (legenda com OAB); chave "Análise da BlueJus IA"
do P4b nasce desligada. Coordenador: prazo da previsão pela lei (Lei 11.419 art. 4º, CPC art. 224); e-mail com
descadastro; sem Controladoria envia e avisa o primário; remetente nao-responda@ com reply-to do responsável; OCR no
P4e; "Apensado · Outro" para as 4 pastas antigas com 2 processos.

**Contratos com outras sessões:** security.yaml com uma rota pública por linha; framework.yaml com limitadores em
trechos próprios; descadastro (`App\Shared\Email\Descadastro`, ?Tenant, todos, reativar, listar) usado pela 5d e
pela a7; Carteiro da 5d (`App\Comercial\Envio`, commit 80c4c7de) para P4c/P4d; user_tenant: 7d põe `situacao`, #75
põe `controladoria`.

**Para o dono:** merges na ordem da fila; confirmar que o MAILER_DSN de produção sai pela Hostinger (SPF/DKIM do
nao-responda@); configurar o cron mensal do relatório (DEPLOY.md, #75); reconstruir a imagem de produção quando o P4e
entrar (tesseract/poppler); trocar o comando do worker para consumir a fila 'comercial' (PR da 5d) antes do P4c/P4d
enviarem pelo Carteiro.

**Próximo passo:** abrir o PR do P4f; fechar o P4c (revisão, visual, PR); depois P4d; P4e quando o #76 entrar.


Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (fora do git; igual ao 1.2.3 nesta
área, salvo raio 4px e `confirmarUI`): cartão "Avisar cliente" ~N:2765-2830 (1.2.3: L2637-2698), `mailVals`
~N:5249-5293 (1.2.3: L4993-5037), modal "Enviar ao cliente" ~N:2833-2873 (1.2.3: L2701-2741), CC Controladoria
`mailCc` (1.2.3: L4970-4971). Depende de: #75 (`user_tenant.controladoria`), #87 (descadastro em Shared) e do
Carteiro da 5d (envio). Risco MÉDIO (e-mail para fora do escritório, dado de cliente, LGPD).

## Decisões já tomadas

- Coordenador (19/10): todo e-mail ao cliente leva "Não quero mais receber" (descadastro do #87, assunto
  `push_cliente`, escopo = o escritório). Sem ninguém na Controladoria, envia e avisa o primário (README do desenho
  dá essa saída). Remetente `nao-responda@` com o nome do escritório, Responder para = responsável da pasta.
- A Análise IA nunca sai sozinha: só manual, com o checkbox "Revisei e corrigi o conteúdo. Autorizo o envio ao cliente
  em nome do escritório." (desenho L4968).
- Contrato com a 5d (a confirmar): `CarteiroInterface::enviar(MensagemDeEmail)`, tipo transacional para o envio
  manual; CC comum (não oculto) para a Controladoria.
- Coordenador (19/10): empilhado sobre o #87 (que traz o #82). Duas dependências ficam ISOLADAS atrás de interfaces
  do domínio Pasta, para trocar sem mexer no resto:
  - `ControladoriaDoEscritorioInterface::pessoas(Tenant): list<User>` — implementação provisória devolve [] (o modal
    mostra "Nenhum usuário com perfil de Controladoria cadastrado." e o primário é avisado); a real lê
    `user_tenant.controladoria` do #75 quando ele estiver no master.
  - `EnvioDeEmailAoClienteInterface::enviar(...)` — implementação provisória pelo Symfony Mailer atual (síncrono,
    com os cabeçalhos List-Unsubscribe do #87); troca para o Carteiro da 5d quando ele entrar.

- Orquestrador (revisão do P4c, 19/10): limites do envio manual, no servidor. Assunto com no máximo 200 caracteres e
  mensagem com no máximo 20.000 (422 com a mensagem clara); no máximo 10 envios de análise por pasta por hora (429
  "Muitos envios em pouco tempo. Tente de novo mais tarde."); idempotência: a mesma análise para o mesmo destinatário
  nos últimos 2 minutos é ignorada e volta na resposta como já enviada. O texto livre da mensagem é do desenho e fica.
- Orquestrador (revisão do P4c, 19/10): a linha que nasceu do e-mail da ficha guarda `do_cadastro`, que sobrevive à
  restauração; removê-la só marca `removido_em` (nunca apaga) e, havendo essa linha, o item virtual não volta. Um só
  principal ativo por cliente (índice único parcial); as escritas travam o cliente (FOR UPDATE) e a violação de
  UNIQUE vira 409. LGPD: remover um e-mail que não é do cadastro troca o endereço por "e-mail removido" nas linhas
  `cadastro` do histórico da pasta; os envios `ia`/`auto` ficam, como prova do ato. Sem responsável ativo, Responder
  para = quem enviou (nunca o MAILER_REPLY_TO da plataforma). Primário = `RegraDosPrimarios::ehPrimario`; na falha
  parcial sem Controladoria, os primários são avisados antes do erro se algum e-mail saiu.

## Contrato com o Carteiro da 5d (recebido 19/10, branch comercial-1a, `App\Comercial\Envio`, spec comercial-e-emails §3.1)

`CarteiroInterface::enviar(MensagemDeEmail): ResultadoDoEnvio {envioId, situacao: enfileirado | bloqueado_descadastro}`.
`MensagemDeEmail(para: string (UM destinatário), assunto, corpoHtml (só o miolo; layout, rodapé legal e descadastro
são do Carteiro), tipo: Transacional|Lista, origem: '/^[a-z0-9_:-]{3,60}$/', lista: ?string (só em Lista),
escopo: ?Tenant, nome, selo, titulo, replyTo, cc: list<string>, rastrear=false, nomeDoRemetente)`. Assíncrono (fila
'comercial'), relê o descadastro antes de mandar. Situação: `RegistroDeEnviosInterface::situacoes(list<int>)`.
Aplicação no P4c:
- Envio manual da Análise IA = Transacional, origem `pasta:push_cliente_ia`, escopo = o escritório,
  replyTo = responsável da pasta, nomeDoRemetente = "{Escritório} via BlueJus".
- Uma chamada por destinatário; o CC da Controladoria vai SÓ na primeira mensagem do envio (a Controladoria recebe uma
  cópia por envio, não uma por e-mail do cliente).
- `envio_ao_cliente` guarda os envioIds; a situação de cada um é lida do Carteiro no histórico.
- A interface provisória `EnvioDeEmailAoClienteInterface` espelha a `MensagemDeEmail` (mesmos campos), para a troca
  ser só de implementação. A provisória (Mailer atual, síncrono) devolve envioId null e situação "enviado".
- P4d (automático) = Lista, lista `push_cliente`, escopo = o escritório.

## 1. Modelo

- `cliente_email` (domínio Cliente): tenant, cliente (FK), email (normalizado), rotulo (Pessoal | Trabalho |
  Familiar | Outro | Cadastro), principal (bool), recebe_avisos (bool), criado_por, criado_em. UNIQUE (cliente, email).
  Máximo 5 por cliente. A lista é POR CLIENTE (o desenho chaveia pelo CPF/CNPJ, então vale em todas as pastas do
  cliente). O e-mail do cadastro (`Cliente.email`) aparece como item virtual "Cadastro · principal" até a primeira
  alteração, quando é materializado (desenho L4972-4977).
- `envio_ao_cliente` (domínio Pasta): tenant, pasta, cliente, tipo (`ia` | `auto` | `cadastro`), descrição, para
  (json), cc (json), assunto, por (user), em, status (do Carteiro), referência (id da análise ou da movimentação).
  Alimenta o "Histórico de envios".

## 2. Cartão "Avisar cliente" (coluna do painel, abaixo de "Avisar quando chegar")

Textos e estados exatos do desenho: "AVISAR CLIENTE" com chip contador (azul / âmbar no 0, título "N e-mail(s)
cadastrado(s), M recebendo avisos" / "Nenhum e-mail cadastrado"), botão "CADASTRAR E-MAIL", vazio "Nenhum e-mail
cadastrado para {2 primeiros nomes}. Cadastre para manter o cliente informado sobre o processo.", itens com ícone,
"{rótulo} · principal · pausado", estrela "E-mail principal"/"Tornar principal", switch "Recebendo avisos (clique para
pausar)"/"Pausado (clique para voltar a receber)", X "Remover e-mail" com confirmação do sistema "Remover {email} dos
avisos ao cliente?". Formulário: campo e-mail (placeholder "email@cliente.com.br"), chips Pessoal/Trabalho/Familiar/
Outro, "Cancelar" e "+ Adicionar", validações "Digite um e-mail válido.", "Este e-mail já está cadastrado.",
"Limite de 5 e-mails por cliente.", aviso "E-mail cadastrado: {e}". E-mail descadastrado (no #87) aparece com o selo
"Descadastrado pelo cliente" e o switch travado (o escritório não pode religar; só o cliente, por novo
consentimento). Linha "Cópia para a Controladoria: {nomes}" com selo "Sempre" e tooltip do desenho; sem Controladoria
a linha some (o aviso fica no modal). "Ver histórico de envios (N)" / "Ocultar histórico de envios (N)", 6 mais
recentes, formatos do desenho (`ia` "Análise IA enviada", `auto` "Envio automático: …", cadastro "E-mail
cadastrado"). Quem pode editar: EDIT na pasta.

## 3. Modal "Enviar ao cliente"

Aberto pelo botão "Enviar ao cliente"/"Reenviar ao cliente" do cartão de Análise IA da aba Push (só análises
concluídas e não internas da pasta). Sem e-mail: abre o formulário de cadastro com "Cadastre um e-mail para enviar a
análise.". Campos do desenho: Para (chips de todos os e-mails não descadastrados; marcados os que recebem avisos),
"Cópia (CC) · Controladoria" travada com cadeado (sem ninguém: texto vermelho "Nenhum usuário com perfil de
Controladoria cadastrado."), Assunto (padrão "Atualização do seu processo {numero}"), Mensagem (corpo padrão do
desenho L4980-4984, editável; editar desmarca o checkbox), checkbox obrigatório. Validações na ordem do desenho.
Envio pelo Carteiro (transacional) com Responder para = responsável da pasta e o link de descadastro; grava
`envio_ao_cliente`; aviso "Análise enviada para N e-mail(s) do cliente, com cópia para a Controladoria"; o cartão da
análise mostra "Enviado dd/mm às HH:MM". Sem Controladoria: envia e avisa o primário no sino. Falha: "Falha no envio.
O texto foi mantido; tente de novo.". Quem pode enviar: EDIT na pasta e acesso à IA.

## 4. Fora deste PR

Envio automático (P4d, nasce desligado por decisão do dono). Envio de movimentação ou da Situação atual (o desenho
só envia a Análise IA).

## 5. Testes mínimos

Unit: limite de 5, principal único, normalização, item virtual do cadastro. Functional: CRUD dos e-mails (CSRF,
EDIT, outro tenant), descadastrado travado, modal com CC e sem Controladoria, envio grava histórico e chama o
Carteiro com os campos certos (dublê), checkbox obrigatório, análise interna não envia, aviso ao primário sem
Controladoria.
