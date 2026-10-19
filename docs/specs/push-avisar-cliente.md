# Painel do Push, parte C: "Avisar cliente" e envio manual da Análise IA (P4c)

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
