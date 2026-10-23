# Comercial e e-mails (painel do Master)

Desenho: `docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html`, seção "Comercial e e-mails" (template
L222-287, script L482-588). "dc L.n" abaixo são linhas desse arquivo. Regra do dono: tudo do desenho entra; onde o
desenho mostra diferente do sistema, o desenho manda; desviar exige perguntar antes.

## Estado da frente (atualizado em 19/10/2026; reler antes de continuar se a sessão for compactada)

- **Sessão:** 5d. **Spec e fatia 1a:** branch `comercial-1a` (worktree `.claude/worktrees/comercial-1a`), empilhada
  sobre `push-avisar-quando-chegar` (#87 da 0d). A branch `comercial-emails` guardou só as versões da spec e não tem PR.
- **Versão:** 3 (19/10). v1 não aprovada (B1, I1 a I11, M1 a M7); v2 aprovada com ressalvas (N1 a N6, M-a a M-m);
  a v3 trata todos. Pelo rigor ALTO, os ganchos de Auth têm nova revisão já no código.
- **Risco:** MÉDIO no geral (envio em massa, LGPD). Os ganchos em Auth (§3.5) tocam identidade User/Tenant: rigor
  ALTO (revisão e re-revisão).
- **Fatias (um PR cada, na fila de migrations):**
  - **1a.** Infraestrutura de envio, registro, confirmação de endereço, rastreio (desligado até a Política), e as
    automações do ciclo da conta (`cad`, `lemb`, `val`, `conv`, `dica`). Empilhada sobre o **#87** da 0d
    (`push-avisar-quando-chegar`, que traz `email_descadastro`).
  - **1b.** As automações de espaço (`e90`, `e100`, `ped`). Branch `comercial-1b`, cortada do master em 21/10 (o #74 e
    o #99 já entraram), migration `Version20261020147100` (nome do coordenador).
  - **2.** Campanhas: modelos, editor de blocos, públicos, agendamento, teste. Spec em §8 (19/10), em dois PRs (2a e
    2b), na branch `comercial-2` empilhada sobre a `comercial-1a` (#99).
  - **3.** Monitoramento (números e CSV por campanha) e Descadastrados.
  - **4.** Indicações (tabela da 7d) e datas comemorativas (`CalendarioDoSistema`).
- **Fila de migrations (coordenador, 19/10):** a da 1a logo DEPOIS da P4b da 0d (#87); as seguintes uma por vez.
  Renomear o timestamp na vez.
- **Decisões do coordenador (19/10, por delegação do dono):**
  - A1: as transacionais nascem ligadas e disparam só pelo evento do próprio usuário, nunca em lote no deploy.
  - A4: o convite de indicação é da 7d e consulta o descadastro.
  - B2: link de descadastro assinado da 0d, escopo plataforma.
  - B3: rodapé legal SEMPRE, também no HTML importado. B4: link da Política de Privacidade no rodapé.
  - C1: a confirmação de cadastro já existe; o evento de cadastro confirmado e de convite aceito sai DEPOIS do commit,
    rigor ALTO; o e-mail sai do ouvinte, e a falha dele não desfaz o cadastro.
  - C2: aberturas (pixel) e cliques (link rastreado) entram, em números e CSV por campanha, sem gráfico; a Política de
    Privacidade precisa citar o rastreio (rascunho da f6, com o advogado).
  - C4: "Enviar teste" vai ao Master logado.
  - P3a: o `e90` oferece 80, 85 e 90% como no dc. P3b: o `lemb` manda um link novo, uma vez só.
  - P5: migrar os e-mails antigos para o Carteiro é follow-up fora desta frente.
  - Vazão 20/min e 1.500/dia, configurável, até o dono responder. **Revisto em 23/10/2026** (Q1 respondida: Hostinger
    Business Standard/Premium, 3.000 por caixa em 24 h corridas): 10/min e 2.400 em 24 h corridas, ver §3.6.
- **Decisões do coordenador sobre a v3 (19/10):** Q2 ok (pendente vencido guardado 72 h a mais); Q3 e Q4 seguem o
  dc: as boas-vindas saem ao confirmar e ao aceitar qualquer convite (de escritório, de primário ou de plataforma);
  Q5 (código de 6 dígitos) entra, mas com a frente do desenho 10 (Login e cadastro), fora desta; Q7 sim, destinatário
  mascarado nos "Últimos disparos" (minimização); Q6 direto com a f6; rastreio desligado com a constante, ok; o
  worker `comercial async` e o `SYMFONY_TRUSTED_PROXIES` vão à lista do dono, com o passo no PR.
- **Com o dono (pacote do coordenador):** A2 datas comemorativas desligadas e só para primários; A3 Pausar e Encerrar
  na recorrente; B1 CSV e Individual só para quem consentiu; reply-to; C3 limite de envio; P1 provedor de envio; P2
  "Ao abrir uma conta" travado em ligado; P4 retenção de 24 meses. **Novas na v2 (§7):** Q1 a Q7.
- **Contratos com outras sessões:**
  - **0d (#87):** `App\Shared\Email\Descadastro\DescadastroDeEmailInterface`: `estaDescadastrado(?Tenant, email,
    assunto)`, `descadastrar(?Tenant, email, assunto, origem)`, `gerarLink(?Tenant, email, assunto)`; `ASSUNTO_TODOS =
    'todos'`; `PADRAO_DO_ASSUNTO = /^[a-z_]{3,40}$/`; one-click RFC 8058 tira só da lista do link. **Aceito pela 0d em
    19/10, entra no #87:** `reativar(?Tenant, email, assunto, origem, ?User $por = null)`, idempotente, sem apagar
    (a linha ganha `reativado_em/origem/por`; descadastro novo vira linha nova); `reativar('todos')` solta tudo do
    escopo; com `todos` ativo, `reativar(x)` libera só x (exceção registrada). `listar(?Tenant, ?busca, pagina,
    porPagina <= 100)`: só os ativos, do mais recente, com total e `{email, assunto, origem, motivo, em}`.
    `descadastrar(..., ?string $motivo = null)`. Registro manual do Master com origem `manual_master`. Página pública:
    GET só mostra; o botão grava a lista do link e, se marcada (desmarcada por padrão), também `todos`.
  - **f6 (#64, #74), entregue em 19/10:** eventos despachados só depois do trabalho dar certo
    (`EventosDoEspacoAposConfirmar`); `UsoDoEspacoSubiu(tenantId, percentualAnterior, percentualAtual, retrato)` com
    `cruzou(int $limite)`; `EspacoAtingiuFaixa` (com `percentualAnterior`; esgotado = faixa `Bloqueio`);
    `EspacoFoiPedido(tenantId, codigoDoPlano, ?pedidoPorUserId)` (#74). O retrato traz `bloqueioAtivo`. O limite de 1
    aviso por dia por escritório e faixa fica com esta frente.
  - **a7 (#84), aceito em 19/10 (spec dela §4.3):** a inscrição de alertas chama `pedir('vagas_alerta', email,
    'pagina-publica-vagas', versao, texto, null)`; o job dos alertas é dela e chama o Carteiro só para quem passa em
    `estaConfirmado('vagas_alerta', email, null)`, com `tipo = lista`, `lista = vagas_alerta`, `origem =
    consumidor:vagas_alerta`. Ela liga o `pedir()` num PR pequeno depois da 1a.
  - **0d (Push):** consome o Carteiro com `replyTo` e `cc` (§3.1).
  - **4b (#54):** datas do `CalendarioDoSistema` (fatia 4). **7d:** UseCase de leitura das indicações (fatia 4).
  - **b5 (#56):** parcial `master/_comercial.html.twig`, evento `mp:secao`, CSP do `/master` com 'self'.
- **Arquivos quentes que a 1a toca:** `config/packages/messenger.yaml` (transporte próprio), `security.yaml` (rotas
  públicas), `framework.yaml` (limitadores), `docker-compose.prod.yml` (o worker passa a consumir o transporte novo:
  avisar o dono, é deploy).
- **Inventário de identidade da 1a (M-i, rigor ALTO):** 4 ganchos de evento (`ConfirmarCadastroUseCase`,
  `AceitarConviteEscritorioSemContaUseCase`, os 2 retornos do `AceitarConviteEscritorioComContaUseCase`) mais 1 no
  `AceitarConvitePlataformaUseCase`; 1 evento no `CadastroMailer`; o UseCase novo `ReemitirConfirmacaoDeCadastroUseCase`;
  métodos novos no `CadastroPendente` (`reemitir`, `lembreteEm`); a purga dos pendentes (72 h); a migration de
  `cadastro_pendente.lembrete_em`; e a purga de escritório (`PurgarEscritorioUseCase`, N2).
- **Dependências de PR:** a 1a depende do #87 da 0d, que está em rascunho e empilhado sobre o #82 (M-j); a 1b, do #74
  (que contém o #64), também em rascunho.
- **0d, assinatura final (#87, commit 56fd1001):** constantes `PADRAO_DO_ASSUNTO`, `ASSUNTO_TODOS`,
  `ORIGEM_MANUAL_MASTER = 'manual_master'`, `MAXIMO_POR_PAGINA = 100`; `descadastrar(..., ?string $motivo = null)`
  (até 280 caracteres); `reativar(?Tenant, email, assunto, origem, ?User $por = null)`; `listar(...)` devolve
  `ListaDeDescadastros {total, itens, pagina, porPagina}` com `ItemDeDescadastro {email, assunto, origem, motivo, em}`
  (busca ILIKE no e-mail). A migration `Version20261020090000` da 0d foi reescrita: no banco de teste da frente, `down`
  e `up` dela antes da da 1a.
- **Implementação da 1a (19/10, noite):** contratos commitados (80c4c7de, com push); bloco A (envio) e bloco B
  (confirmação de endereço) com implementadores em worktrees isoladas; bloco C (ganchos de Auth, purgas, automações,
  varredura e a tela do Master) com a 5d. Decisões técnicas da implementação, registradas: (1) gravação por DBAL e
  entidades só para esquema e purga, como a 0d; (2) coluna nova `comercial_envio.proxima_tentativa_em`: a
  recuperação só redespacha o `enfileirado` com a tentativa vencida há mais de 30 min (sem ela, todo envio que espera
  a vazão do dia seguinte seria redespachado a cada 15 min); (3) a tela carrega por JSON quando a seção abre (o
  parcial não recebe variáveis do controller do painel, que é do #56); (4) o `cad` não é registrado pelo Carteiro: o
  `CadastroMailer` despacha `ConfirmacaoDeCadastroEnviada` e um ouvinte grava o envio; (5) contrato do Carteiro
  ajustado para a 0d (P4c e P4d): `ResultadoDoEnvio {envioId, situacao}`, `RegistroDeEnviosInterface::situacoes`,
  `nomeDoRemetente` só como nome exibido, um destinatário por mensagem; (6) campo `MensagemDeEmail::motivoDoEnvio`
  (a frase do rodapé que diz por que a pessoa recebeu; a confirmação de endereço usa "Este endereço foi informado na
  página de vagas do BlueJus. Se não foi você, ignore este e-mail.", em vez da frase transacional, falsa para quem
  ainda não tem conta); (7) no rodapé de escritório em transacional, o link diz "Não quero mais receber e-mails de
  <escritório>"; (8) a tela tem a prévia só de leitura de cada modelo (`/master/comercial/automacoes/{chave}/previa`),
  com o nome e o endereço de exemplo do 05; os avisos de espaço ganham prévia na 1b.
- **Contrato da 0d no Push (P4c/P4d, aceito em 19/10):** manual da Análise IA = transacional, origem
  `pasta:push_cliente_ia`, escopo = o escritório, `replyTo` = responsável, `nomeDoRemetente` = "{Escritório} via
  BlueJus", CC da Controladoria só na 1ª mensagem; automático = lista `push_cliente`. A 0d guarda os `envioId` e lê a
  situação pelo `RegistroDeEnviosInterface`. Migration dela renomeada para `Version20261020103000` (a desta é
  `Version20261020129800`).
- **Contrato com a 9b (desenho 10, Login e cadastro, que passou da 7d para a 9b em 19/10):** o cadastro passa a
  criar a conta direto e a confirmação do e-mail não trava. A 9b cria `App\Auth\Event\ContaCriadaPeloCadastro(int
  $userId, int $tenantId)` e `App\Auth\Event\EmailDaContaConfirmado(int $userId)` (depois do commit); as boas-vindas
  (`val`) passam a ouvir o `EmailDaContaConfirmado` quando ela integrar. O **lembrete `lemb` é sobre o User**: coluna
  `user.email_a_confirmar_desde`, `UserRepository::semConfirmacaoDeEmailParaLembrete(\DateTimeImmutable $pendenteAte,
  int $limite): list<User>` e `ReemitirConfirmacaoDeEmailUseCase::executar(int $userId):
  ReemissaoDeConfirmacaoOutput{email, nome, url}` (link novo, grava a marca, NÃO envia; DomainException sem conta,
  já confirmado ou já lembrado). A varredura desta frente chama os dois e manda pelo Carteiro, com o miolo "Confirmar
  e-mail" do 05. Por isso **saíram desta 1a** o `ReemitirConfirmacaoDeCadastroUseCase`, a coluna
  `cadastro_pendente.lembrete_em` e a folga de 72 h da purga (o `CadastroPendente` e o repositório dele voltaram à
  base). Até a 9b entregar, a linha do `lemb` aparece no painel e não dispara. O `CadastroMailer` segue com o
  dispatch de `ConfirmacaoDeCadastroEnviada` depois do send. Ordem: esta 1a entra antes; a 9b traz o master e leva os
  eventos aos pontos novos.
  **Entregue pela 9b (branch `login-desenho-10`, 19/10, sem PR):** `ContaCriadaPeloCadastro` (no `CriarContaEEscritorioUseCase`)
  e `EmailDaContaConfirmado` (no `ConfirmarEmailDaContaUseCase`, só na primeira confirmação), a consulta e o
  `ReemitirConfirmacaoDeEmailUseCase` com os nomes combinados, e `CadastroMailer::enviarConfirmacaoDeEmail(User,
  token)` (método novo; o `enviarConfirmacao` antigo não mudou). **Follow-up desta frente, depois do merge das duas:** um
  PR pequeno que (a) põe as boas-vindas (`val`) a ouvir `EmailDaContaConfirmado`, (b) liga o `lemb` à consulta e ao
  reemissor da 9b, mandando pelo Carteiro, e (c) põe o dispatch de `ConfirmacaoDeCadastroEnviada` depois do send do
  `enviarConfirmacaoDeEmail`. Cuidados da 9b para o (b): o `ReemitirConfirmacaoDeEmailUseCase` tem `ERRO_JA_ENVIADO` e o banco garante
  um lembrete por conta (índice único parcial); na corrida, a segunda chamada recebe `DomainException(ERRO_JA_ENVIADO)`
  vinda de uma violação de unicidade, que **fecha o EntityManager**. A varredura trata isso resetando o EM
  (`ManagerRegistry::resetManager`) antes do próximo da lista, ou processa um lembrete por mensagem. O
  `EmailDaContaConfirmado` sai uma vez só mesmo com dois cliques simultâneos.
- **Contrato com a 7d (desenho 10, antes da troca para a 9b; vale o da 9b acima):** a 1a entra primeiro em Auth e a 7d traz o master; a 7d não muda a
  validade de 24 h nem a purga; se criar a confirmação por código, ela chama o mesmo `ConfirmarCadastroUseCase` (o
  evento sai nos dois caminhos); colunas dela em `cadastro_pendente`: `codigo_hash`, `codigo_expira_em`.
- **Decisões da revisão do código (19/10):** (1) o trabalho de cada ouvinte de Auth roda em `Connection::transactional`:
  aninhado numa transação de quem chamou, vira savepoint, e uma falha de SQL ali não aborta o fluxo de Auth (o teste
  achou isso com a DAMA); (2) "no máximo uma vez": a boas-vindas e a dica são marcadas antes do envio, e uma falha no
  Carteiro desfaz a marcação (sai na próxima vez); (3) cada passo da varredura é isolado (uma falha na dica não pula a
  recuperação) e a purga do Comercial nunca lança (não impede a purga dos escritórios); (4) as simulações gravam a
  origem `simulacao:<chave>` (para o gatilho de "Últimos disparos"); (5) hora: o sistema grava hora local
  (America/Sao_Paulo) sem fuso e o `NOW()` do banco está em UTC; a migration semeia o `ligada_em` com
  `NOW() AT TIME ZONE 'America/Sao_Paulo'` e a varredura com uma data passada (com o `NOW()` puro, a primeira cadeia
  morreria por 3 h em produção); (6) o worker de dev e o de produção consomem `comercial async`
  (docker-compose.yml e docker-compose.prod.yml, neste PR); (7) o texto "Boas-vindas" no `conv` e a descrição do
  `cad` ("o link e o código") seguem o 05 literalmente; o código é da 9b.
- **A tela só aparece com o #56:** o include do parcial `master/_comercial.html.twig` é do painel do #56, que não está
  nesta base. A verificação visual contra o 05 fica para depois do #56 no master; até lá o PR fica em rascunho.
- **Para o dono:**
  1. Q1: a conta do `MAILER_DSN` de produção (Gmail gratuito, Workspace ou Hostinger). Recomendação: provedor
     transacional ou o SMTP da Hostinger, com SPF e DKIM; o Gmail limita o volume e não dá aviso de entrega.
  2. P1 (provedor), P2 ("Ao abrir uma conta" travado em ligado; recomendação: sim) e P4 (retenção de 24 meses;
     recomendação: sim) e as do pacote anterior (A2, A3, B1, reply-to, C3), com o coordenador.
  3. Deploy: este PR muda o comando do `jusprime_worker_prod` para `messenger:consume comercial async`
     (docker-compose.prod.yml); o worker precisa ser recriado no deploy. (O `app:comercial:varredura` que semeava
     a cadeia saiu em 23/10/2026: a varredura passou ao schedule `cadeias`, ver `docs/specs/scheduler-tarefas-agendadas.md`, "Cadeias no Scheduler".)
  4. `SYMFONY_TRUSTED_PROXIES` na VPS, para o IP real nos limitadores e na prova de consentimento.
  5. Os merges, na ordem: #82 → #87 (0d) → esta 1a.
- **Pendências:** respostas do dono; consulta de datas de descadastro à 0d (fatia 3); `CITA_RASTREIO_DE_EMAIL` com a f6
  (a f6 já pôs a regra no rascunho da Política).
- **PR da 1a: #99** (rascunho, base `push-avisar-quando-chegar` = #87; merge depois de #82 e #87). Revisão aprovada com
  ressalvas, corrigidas e re-revisadas (aprovada); suíte da frente 10457 verdes. Fica em rascunho até o #56 entrar e a
  tela ser conferida contra o 05.
- **PRs da frente (20/10):** #99 (1a, `comercial-1a` sobre `push-avisar-quando-chegar`), #116 (2a, `comercial-2` sobre
  `comercial-1a`) e #121 (2b, `comercial-2b` sobre `comercial-2`), todos abertos; o merge segue a pilha.
- **Premissa de operação (2b):** um worker só no transporte `comercial`. Os tetos (vazão por minuto e dia, teto das
  campanhas, contrapressão, teto de testes) são contagens, não reservas atômicas; com dois workers no mesmo transporte
  podem passar um pouco. Não muda o docker-compose.
- **Fatia 2a implementada (19/10, branch `comercial-2`, PR #116):** `ModelosDeCampanha` (os fixos do dc L.504-515, com
  as cópias em blocos das automações; o `validar` sem o código 482 917), `comercial_modelo` (modelos próprios, trilha
  manual `modelo_new/upd/del` com `{nome, modo}`), `RenderizadorDeBlocos` (dc L.484-500, 1:1), sanitização do HTML
  importado (`symfony/html-sanitizer` configurado em código, mais o sanitizador do `style`), `LayoutDoEmail` (`padrao`,
  `linhas`, `cru`) no `MensagemDeEmail` e na coluna `comercial_envio_conteudo.layout`, a prévia em JSON (`srcdoc` de
  iframe `sandbox=""`), "Baixar HTML" (na tela) e "Enviar teste" (`teste_campanha`, isento do teto diário pela lista
  renomeada `ORIGENS_ISENTAS_DO_TETO_DIARIO`, 10 por hora por Master). Migration `Version20261020129900`.
  Decisões da implementação: (1) as chaves de link `vagas`, `contribua` e `espaco` viram caminhos fixos
  (`/vagas-juridicas`, `/contribua#sug`, `/contribua#espaco`), porque as rotas da a7 e da f6 não estão nesta base; `app` é
  a página de entrada (`app_login`, como a 1a) e `cadastro` é `auth_cadastro`; (2) a prévia mostra "Dra. Ana" inteira
  como o dc (o envio usa o primeiro nome); (3) o bloco da campanha fica ANTES das Automações, na posição do dc;
  (4) os marcadores das URLs do parcial passaram a casar com o requisito das rotas (`xchavex`, `xidx`): com
  `__chave__`, o parcial da 1a não renderizava em dev nem em teste (`strict_requirements`).
- **Fatia 2b implementada (19/10, branch `comercial-2b`, empilhada sobre `comercial-2`, PR #121):** públicos
  (`PublicosDaCampanha`, `CadastradosRepository`, o CSV lido no servidor com a regra do dc L.545 em `ListaInformada`),
  B1 e reply-to em `PoliticaDeDestinatarios`, "Enviar campanha" com a chave de idempotência e a chave
  `COMERCIAL_CAMPANHAS_LIGADAS` (`ChaveDasCampanhas`, padrão desligada), o expansor (`ExpandirCampanhas`,
  `ExpansorDeCampanhas`, `comercial_expansor`), a situação nova `cancelado` em `comercial_envio` (o handler não envia
  o que é de campanha cancelada), o comando `app:comercial:encerrar-campanha`, a lista de campanhas no bloco
  Monitoramento e a retenção de 24 meses de `comercial_campanha_destino`. Migration `Version20261020129990`.
  Decisões da implementação: (1) secundário = vínculo ativo sem papel de sistema, mesmo que a pessoa seja primária em
  outro escritório (letra da §8.5); "Todos" é a união sem repetidos; (2) o "usuário ativo cadastrado" do B1 usa o
  mesmo vínculo válido dos públicos de sistema (vínculo e usuário ativos, escritório nem suspenso nem excluído); (3) nada é recuperado vale também para a `na_fila` com mais de 24 h de atraso; a recorrente com até
  24 h de atraso executa uma vez; (4) o nome de todo destinatário (não só do CSV) é limpo de `;:()<>"'`; (5) o teto
  diário das campanhas conta os envios de campanha registrados no dia, sem os bloqueados e os cancelados; (6) a frase
  de sucesso volta a "registra entregas, aberturas e cliques." se as duas chaves do rastreio ligarem; (7) o
  descadastro é consultado um endereço por vez (contrato da 0d); uma consulta em lote, pedida à 0d, é follow-up para
  públicos grandes. Revisão da 2b (19/10): com a chave desligada o `EnviarEmailHandler` não envia nada de
  `campanha:*` (marca `cancelado`); o comando de encerrar alcança também a `enviada` com envios na fila e cancela
  esses envios; os cancelamentos automáticos (chave e atraso) gravam `camp_canc` `{motivo, total}`, um por campanha;
  "Baixar HTML" sai sempre com o exemplo fixo do dc; a Base de contatos e o B1 comparam o e-mail confirmado em
  minúsculas.
- **Próximo passo:** depois do #56 no master, verificação visual do bloco Automações contra o 05 e tirar o #99 do
  rascunho; a 1b quando o #87 com o #99, ou o #74, entrar no master; o follow-up da 9b depois do merge das duas.

## 1. O que já existe (levantamento de 19/10)

- **Envio:** Symfony Mailer, síncrono, dentro da requisição (`SendEmailMessage` não está roteado; `messenger.yaml`).
  `IdentidadeDeEmail::aplicar()` põe remetente (`MAILER_FROM`, nao-responda@bluejus.com.br), reply-to
  (`MAILER_REPLY_TO`) e o prefixo `[BlueJus]`. Mailers: `CadastroMailer`, `ConviteMailer`, `RedefinicaoSenhaMailer`,
  `TrabalhoExternoMailer`, legado `InvitationService`. Templates soltos em `templates/email/`, sem layout base.
- **Provedor de produção: incerto.** O `.env.prod` local (de março, não versionado, sem `MAILER_FROM`) aponta o SMTP do
  Gmail, e o Anexo I da Política lista o Gmail para comunicações transacionais
  (`templates/legal/_politica_privacidade_texto.html.twig:362`); a 0d registra o `MAILER_DSN` da Hostinger como
  pendência. Ver P1 e Q1.
- **Messenger:** transporte `async` (doctrine), dividido com o Drive e a IA; 3 tentativas; `failed` sem consumidor;
  worker `jusprime_worker_prod` (`messenger:consume async`, `docker-compose.prod.yml`).
- **Cadastro:** `/cadastro` grava `CadastroPendente` (com nome, escritório, OAB, hash da senha; link de 24 h) e o
  `CadastroMailer` manda a confirmação, sem código; `ConfirmarCadastroUseCase` cria User e Tenant dentro de
  `wrapInTransaction`; o `app:purgar-dados-expirados` apaga o pendente vencido. Aceites:
  `AceitarConviteEscritorioSemContaUseCase` (cria User; sem transação, vários `flush`),
  `AceitarConviteEscritorioComContaUseCase` (dois retornos: reativação e vínculo novo; trata convite de primário),
  `AceitarConvitePlataformaUseCase` (cria User sem escritório). **Não há e-mail de boas-vindas nem eventos de domínio.**
- **LGPD:** a Política vigente prevê comunicação comercial a clientes por legítimo interesse com descadastro, a não
  clientes por consentimento (L165-168), cookies não essenciais com consentimento (cap. 10), registros de consentimento
  por 5 anos após o término (L379), e só cita "registros de envio e de abertura de comunicados operacionais" (L146).
- **Agendamento:** sem Scheduler nem cron no código. O #76 (fora do master) usa mensagem que se reagenda com reivindicação
  atômica; esta frente implementa o próprio, no mesmo padrão.
- **Master:** `ROLE_SUPER_ADMIN` na classe; rotas `master_*`; JSON com `X-CSRF-Token`; CSP do painel com script 'self'.
- **Primário do escritório:** quem tem vínculo ativo com o papel de sistema
  (`UserTenantRepository::findUsuariosAdminsAtivos`).

## 2. O que o desenho mostra (resumo; inventário completo nas linhas citadas)

Página corrida: 4 KPIs (L223); Descadastrados (L224-228); Nova campanha (L229-260); Automações (L262-268); Indicações
(L269-275); Datas comemorativas (L276-281); Monitoramento (L282-286). Rodapé de todo e-mail (L501): razão social, CNPJ,
cidade, "Você recebeu este e-mail porque se cadastrou no BlueJus ou autorizou o envio de novidades. Não quero mais
receber." Layout (L496-502): cabeçalho em degradê azul com logo, selo e título; corpo; rodapé.

**Automações (L262-268, regras L561-566):**

| Chave | Título | Descrição (dc) | Modelo | Atraso | Estado no dc |
|---|---|---|---|---|---|
| `cad` | Ao abrir uma conta | Envia o link e o código para confirmar o e-mail | Confirmar e-mail | imediato | ligada |
| `lemb` | E-mail não confirmado | Reenvia a confirmação se não validar | Confirmar e-mail | 24h (24, 48, 72h) | ligada |
| `val` | Ao confirmar o e-mail | Boas-vindas com os primeiros passos | Boas-vindas | imediato | ligada |
| `conv` | Convite aceito (secundário) | Boas-vindas para quem entrou na equipe | Boas-vindas | imediato | ligada |
| `dica` | 7 dias depois do cadastro | Dicas para aproveitar o sistema | Primeiros passos | 7 dias (3, 7, 15) | **desligada** |
| `e90` | Conta chegou a 90% do espaço | Recomenda aumentar ou solicitar espaço (ao primário) | Espaço em 90% | 90% (80, 85, 90) | ligada |
| `e100` | Espaço esgotado (100%) | Avisa que novos envios estão pausados | Espaço esgotado | | ligada |
| `ped` | Pedido de mais espaço | Confirma ao cliente e avisa o Master | Pedido de espaço recebido | imediato | ligada |

"Simular novo cadastro" (L263, L566): no dc, põe na frente dos "Últimos disparos" dois itens ("Conta aberta" com o
assunto da confirmação e "E-mail confirmado" com o das boas-vindas, para `novo.cliente@exemplo.com`) e mostra
"Simulação: um novo cadastro disparou a confirmação e, após validar, as boas-vindas." Não envia nada. "Últimos
disparos" (L267, L565): os 6 últimos, com gatilho, assunto, destinatário e data; vazio "Nenhum disparo ainda."

## 3. Fatia 1a: infraestrutura e automações do ciclo da conta

Domínio novo `app/src/Comercial/`. Nenhuma tabela nova é `TenantAware`: são do Master e cruzam escritórios; o escopo
fica numa coluna `tenant_id` nullable (null = plataforma), escrita à mão em cada consulta.

### 3.1 O Carteiro: porta única de envio (contrato para 0d e a7)

`App\Comercial\Envio\CarteiroInterface::enviar(MensagemDeEmail $m): ResultadoDoEnvio`.

`MensagemDeEmail` (DTO imutável, validado no construtor):

| Campo | Regra |
|---|---|
| `para` | e-mail válido (`filter_var`), normalizado em minúsculas, sem espaços |
| `nome` | opcional; usado em `{nome}` (primeiro nome) e no cabeçalho `To` |
| `assunto` | do e-mail |
| `selo`, `titulo` | opcionais; o cabeçalho do layout (dc L498) |
| `corpoHtml` | o miolo, já renderizado pelo chamador ou por um modelo desta frente |
| `tipo` | `transacional` (ciclo da conta, obrigação do serviço) ou `lista` (com descadastro) |
| `lista` | obrigatória em `lista`, `PADRAO_DO_ASSUNTO` da 0d; proibida em `transacional` |
| `escopo` | `?Tenant`; null = plataforma |
| `origem` | `automacao:<chave>`, `campanha:<id>`, `teste_campanha`, `consumidor:<nome>` (`[a-z0-9_:]{3,60}`) |
| `replyTo` | opcional; a 0d manda o responsável |
| `cc` | lista opcional; a 0d manda a Controladoria |
| `rastrear` | bool; só vale em `lista` e só com o rastreio ligado (§3.8) |

**Conteúdo do envio (N1):** o corpo, o selo, o título, o `replyTo`, o `cc` e o `rastrear` ficam em
`comercial_envio_conteudo` (`envio_id` PK e FK com ON DELETE CASCADE, `corpo_html`, `selo`, `titulo`, `reply_to`,
`cc` json, `rastrear`). **Apagado assim que o envio chega a um estado final** (`enviado`, `falhou`,
`bloqueado_descadastro`): o corpo pode levar credencial (o link de confirmação do `lemb`) e não fica guardado. O
registro de `comercial_envio` (sem o corpo) segue a retenção da §3.2.

**Gravação isolada (M-f):** o Carteiro grava pela conexão DBAL (`Connection::transactional`), sem `flush` do
EntityManager: chamado no meio do trabalho de um consumidor, não grava as entidades pendentes dele.

Em uma transação só da conexão (insert do registro, do conteúdo e da mensagem no transporte doctrine, mesma conexão;
sem registro órfão):
1. `lista`: `estaDescadastrado(escopo, para, lista)`. Descadastrado (inclusive em `todos`): grava o envio como
   `bloqueado_descadastro` e devolve sem despachar.
2. Grava o `EnvioDeEmail` como `enfileirado` e despacha `EnviarEmail(envioId)` no transporte **`comercial`** (§3.6).

`EnviarEmailHandler`:
1. **Reivindica** o envio: `UPDATE comercial_envio SET situacao = 'enviando', tentativa_em = :agora WHERE id = :id
   AND situacao = 'enfileirado'`. Zero linhas: outro worker pegou, ou já saiu; para.
   **Recuperação (M-c):** a varredura devolve a `enfileirado` todo `enviando` com `tentativa_em` de mais de 15 min, e
   **despacha de novo** `EnviarEmail` para todo `enfileirado` de mais de 30 min (a mensagem original pode ter morrido
   em `failed`); a reivindicação torna o redespacho inofensivo. **Exceção da 2b (§8.5):** o `enviando` parado de
   origem `campanha:*` não volta à fila; vira `incerto` (pode ter saído pelo SMTP) e não é reenviado.
2. `lista`: consulta o descadastro DE NOVO; descadastrado vira `bloqueado_descadastro`.
3. **Vazão** (§3.6): sem vaga, volta a `enfileirado` e reenfileira com `DelayStamp`.
4. Monta com o layout (§3.7), aplica `IdentidadeDeEmail` (o `replyTo` do DTO tem precedência), em `lista` acrescenta
   `List-Unsubscribe` e `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, e em `lista` com rastreio os links (§3.8).
5. `MailerInterface::send`. Sucesso: `enviado` e `enviado_em`. Exceção: volta a `enfileirado` e relança, para o retry
   do Messenger.
6. Entrega "pelo menos uma vez": se o SMTP aceitou e o `flush` de `enviado` falhar, o retry manda de novo. Aceito e
   registrado aqui.

`FalhaDeEnvioListener` (`WorkerMessageFailedEvent`): para `EnviarEmail` sem `willRetry()`, marca `falhou` com o erro
**sanitizado** (endereços de e-mail trocados por `<e-mail>`, até 500 caracteres). Logs sem dado pessoal.

Os e-mails antigos (`CadastroMailer`, `ConviteMailer`, `RedefinicaoSenhaMailer`) continuam síncronos (P5). Só o
`CadastroMailer` passa a despachar um evento depois do `send` (§3.5, `cad`, N3).

### 3.2 Registro dos envios

`comercial_envio` (`EnvioDeEmail`): `id`, `token` (32 hex aleatórios, público), `tenant_id` (nullable), `destinatario`,
`nome`, `assunto`, `tipo`, `lista`, `origem`, `situacao` (`enfileirado`, `enviando`, `enviado`, `falhou`,
`bloqueado_descadastro`, `simulado`), `erro`, `criado_em`, `tentativa_em`, `enviado_em`, `primeira_abertura_em`,
`aberturas`, `primeiro_clique_em`, `cliques`. Índices: `token` UNIQUE, `(origem, criado_em)`, `(situacao,
enviado_em)`, `(destinatario)`.

**Retenção (P4, com o dono):** proposta de 24 meses; depois, `destinatario` vira hash SHA-256 com sal da aplicação,
`nome` e `erro` viram null (os números por campanha continuam). Roda no `app:purgar-dados-expirados`. O Anexo II da
Política precisa da categoria (vai ao rascunho da f6 junto com o rastreio).

**Purga de escritório (N2, identidade Tenant):** o `PurgarEscritorioUseCase` confere que nenhuma tabela com
`tenant_id` sobrou. Tratamento de cada tabela nova com `tenant_id`:

| Tabela | FK para `tenant` | Na purga |
|---|---|---|
| `comercial_envio` | sim, NO ACTION | entra em `ORDEM_DELECAO` (o conteúdo vai junto, CASCADE) |
| `comercial_confirmacao_endereco` | sim, NO ACTION | entra em `ORDEM_DELECAO` |
| `comercial_ciclo_conta` | sim, NO ACTION | entra em `ORDEM_DELECAO` |
| `comercial_consentimento` | **não** (coluna `tenant_id` sem FK) | entra em `TABELAS_RETIDAS`: prova de consentimento, 5 anos (Política L379) |
| `comercial_aviso_espaco` (1b) | sim, NO ACTION | entra em `ORDEM_DELECAO` |

Na prática quase tudo desta frente é escopo plataforma (`tenant_id` null); a purga só alcança o que um consumidor
enviar com escopo de escritório.

### 3.3 Confirmação de endereço (double opt-in reaproveitável)

`App\Comercial\Consentimento\ConfirmacaoDeEnderecoInterface`:
- `pedir(string $lista, string $email, string $origem, string $versaoDoConsentimento, string $textoDoConsentimento,
  ?Tenant $escopo = null): void`.
- `confirmar(string $token, string $ip, ?string $agente): ResultadoDaConfirmacao` (uso interno da rota).
- `estaConfirmado(string $lista, string $email, ?Tenant $escopo = null): bool`.
- `estaConfirmado` exige confirmação vigente **e** não estar descadastrado da lista (consulta a 0d); o descadastro
  não precisa avisar esta frente.

**Prova imutável (B1):** `comercial_consentimento` é só de acréscimo: cada linha é um fato (`pedido`, `confirmado`; a
revogação fica no registro do descadastro da 0d, que também não apaga), com `tenant_id`, `lista`, `email`, `origem`, `versao_consentimento`, `texto_consentimento`, `ip`,
`agente`, `em`. Nunca se atualiza nem apaga dentro do prazo. **Estado atual** em `comercial_confirmacao_endereco`:
`tenant_id`, `lista`, `email`, `token_hash` (SHA-256), `pedido_em`, `expira_em`, `confirmado_em`;
UNIQUE (`tenant_id`, `lista`, `email`), com índice parcial para o `tenant_id` null.

**`pedir` sobre endereço já confirmado (N4):** não mexe em `confirmado_em` nem manda e-mail (devolve como se tivesse
pedido; quem pede não descobre se o endereço já estava inscrito). Assim ninguém descadastra uma pessoa por tabela.
Confirmação **vigente** = `confirmado_em` preenchido e não descadastrado da lista nem em `todos` sem exceção (a 0d
decide); mudar a `versao_consentimento` não invalida a confirmação antiga (o fato com a versão fica gravado).

**Pedido para quem saiu (pergunta 1 da revisão):** se o endereço está descadastrado da lista ou em `todos`, o `pedir`
ainda manda a confirmação (é o caminho de volta), mas no máximo 1 vez a cada 30 dias para o mesmo endereço e lista.

**Token:** 32 bytes aleatórios, só o hash no banco, uso único, 7 dias. Um `pedir` novo sobre pendência troca o token
(o antigo deixa de valer). Vencido ou já usado: página "Este link venceu ou já foi usado. Peça a confirmação de novo." sem revelar o
endereço.

**À prova de robô (I9):** o link abre `GET /e/confirmar/{token}`, que só mostra a página com o botão **"Confirmar"**
(nada é gravado no GET; os leitores de link do Outlook e afins não confirmam sozinhos). O `POST` (CSRF, mesmo token)
grava `confirmado`, com IP e agente.

**Contra abuso (I9, N6):** dentro do `pedir`: por e-mail e lista, 1 pedido a cada 24 h; teto global de 200 por hora.
**O limite por IP fica no controller do consumidor** (o `pedir` não recebe IP): a a7 já tem o `vagas_alertas` (10 por
minuto). O IP real depende de `SYMFONY_TRUSTED_PROXIES` na VPS, que o dono configura; até lá, o IP gravado como prova
e usado nos limitadores é o do nginx (M-a). **Os e-mails de confirmação obedecem ao teto diário** (são provocados por
terceiros): ver a prioridade em §3.6.

**Reinscrição depois de descadastro:** confirmar de novo é consentimento novo: o POST da confirmação chama
`reativar(escopo, email, lista, 'nova_confirmacao')` da 0d, que libera só aquela lista (com `todos` ativo, fica a
exceção registrada só para ela).

**Retenção:** só a pendência **nunca confirmada** é apagada (estado e o fato `pedido`), 30 dias depois de vencer; um
estado com `confirmado_em` nunca é apagado pela purga, mesmo com um pedido novo vencido por cima. Os fatos de quem
confirmou ficam 5 anos depois do término do tratamento (o descadastro daquela lista no registro da 0d; Política L379).
A purga desses fatos precisa da data do descadastro, inclusive dos reativados, que o `listar` da 0d não devolve: fica
para a fatia 3, com uma consulta pedida à 0d. Até lá, a 1a não apaga fato confirmado (guarda mais, nunca menos).

**Rótulo da origem (M-g):** o e-mail de confirmação não mostra o identificador cru. Mapa nesta frente:
`pagina-publica-vagas` → "a página de vagas do BlueJus"; origem desconhecida → "um formulário do BlueJus".

### 3.4 As automações (modelo de dados e regras comuns)

Enum `App\Comercial\Automacao\Automacao` com as 8 chaves, título, descrição, modelo, opções de atraso e o padrão do dc.
`comercial_automacao` tem **uma linha por automação**, criada pela migration com o padrão do dc (`chave` PK, `ligada`,
`atraso`, `ligada_em`, `alterada_por`, `alterada_em`); o `ligada_em` das que nascem ligadas é o momento da migration
(M-d). Criar as linhas não dispara nada.

**Nada dispara para o passado (I8):** cada gatilho por evento só reage a evento novo. Cada gatilho por varredura só
considera fatos **posteriores a `ligada_em` daquela automação**; desligar e ligar de novo atualiza o `ligada_em`. Ligar uma automação meses depois não alcança contas antigas.

**Ciclo da conta:** `comercial_ciclo_conta` (`user_id`, `tenant_id`, `confirmado_em`, `boas_vindas_em`, `dica_em`),
UNIQUE NULLS NOT DISTINCT (`user_id`, `tenant_id`), gravado pelos ouvintes de `CadastroConfirmado` e de `ConviteDeEscritorioAceito`. É a
fonte do `dica` (o `CadastroPendente` é purgado) e a deduplicação de `val`, `conv` e `dica`: no máximo uma boas-vindas
por pessoa e escritório (quem é reativado num escritório onde já esteve não recebe de novo, M-e) e uma `dica` por
conta.

**Ouvintes nunca derrubam o fluxo (I3):** todo ouvinte de evento desta frente captura qualquer exceção, registra no
log sem dado pessoal (só ids e a chave da automação) e segue. O usuário nunca recebe erro por causa de um e-mail.

**Varredura (M3):** mensagem `VarrerAutomacoes`, no transporte `comercial`, disparada a cada 15 min pelo schedule
`cadeias` do Symfony Scheduler (desde 23/10/2026; antes se reagendava com `DelayStamp` e era semeada pelo
`app:comercial:varredura`, que saiu; ver `docs/specs/scheduler-tarefas-agendadas.md`, "Cadeias no Scheduler"). Controle `comercial_varredura` (`id` fixo 1,
`proxima_em`, `executada_em`): o handler reivindica com
`UPDATE ... SET executada_em = :agora, proxima_em = :agora + 450 s WHERE id = 1 AND proxima_em <= :agora` (metade do
intervalo); zero linhas, outra volta acabou de passar e esta não faz nada. O handler não se reagenda: uma volta que
falha vai para `failed` e a seguinte vem 15 min depois, sem comando para religar.

### 3.5 Os gatilhos da 1a

| Chave | Gatilho | Quem recebe | Tipo |
|---|---|---|---|
| `cad` | O `CadastroMailer` continua mandando (P5). Depois do `send`, despacha `ConfirmacaoDeCadastroEnviada(email, assunto)`; o ouvinte desta frente (protegido, §3.4) registra o envio em `comercial_envio` com `origem = automacao:cad` e `situacao = enviado` (para os "Últimos disparos" mostrarem "Conta aberta", I11). O Auth não lê nada do Comercial (N3) | quem se cadastrou | transacional |
| `lemb` | Varredura: `CadastroPendente` com status pendente, criado há N horas ou mais (24, 48, 72) e depois do `ligada_em`, sem lembrete ainda | quem se cadastrou | transacional |
| `val` | Evento `CadastroConfirmado(userId, tenantId)` | o novo primário | transacional |
| `conv` | Evento `ConviteDeEscritorioAceito(userId, tenantId, comoPrimario)` | quem aceitou | transacional |
| `dica` | Varredura: `comercial_ciclo_conta.confirmado_em` há N dias (3, 7, 15), depois do `ligada_em`, sem `dica_em` | o primário da conta | **lista** (`comercial_dicas`) |

**`lemb` com link novo (P3b, I1):** o link vale 24 h e o pendente vencido é purgado. Para o lembrete existir:
- um caso de uso novo em Auth, `ReemitirConfirmacaoDeCadastroUseCase(pendenteId)`: gera token novo, `expiresAt =
  agora + 24 h`, marca `lembreteEm`, e devolve os dados para o e-mail; recusa se já tiver `lembreteEm`, se confirmado,
  ou se não pendente. Exige a coluna `cadastro_pendente.lembrete_em` (migration da 1a, tabela de identidade: rigor
  ALTO);
- o `PurgarCadastrosPendentesUseCase` passa a apagar o vencido só **72 h depois de vencer** (para o lembrete de 72 h
  encontrar o pendente). Isso guarda os dados de um cadastro abandonado por mais 3 dias: pergunta Q2.
- O e-mail do lembrete é o mesmo template de confirmação, com o link novo, mandado pelo Carteiro.

**Ganchos em Auth (rigor ALTO, I3, I4):** uma linha de `dispatch` em cada, sem mudar mais nada:
- `ConfirmarCadastroUseCase`: `CadastroConfirmado` **depois de o `wrapInTransaction` retornar** (fora dele).
- `AceitarConviteEscritorioSemContaUseCase`: `ConviteDeEscritorioAceito` depois do último `flush` (depois do
  `registrarAceite`).
- `AceitarConviteEscritorioComContaUseCase`: `ConviteDeEscritorioAceito` nos **dois** retornos (reativação e vínculo
  novo), depois do último `flush` de cada; `comoPrimario` diz se o convite era de primário.
- `AceitarConvitePlataformaUseCase`: `ConvitePlataformaAceito(userId)`; o `conv` ouve (Q3 decidida: boas-vindas também
  aqui), com `tenant_id` null no ciclo da conta (UNIQUE com `NULLS NOT DISTINCT`).
- O dispatcher é o `EventDispatcherInterface` do Symfony (síncrono); o ouvinte só enfileira pelo Carteiro, protegido
  como em §3.4. **Prova no teste (M-b):** com a DAMA, o DBAL não enxerga a transação de fora: o nível de base é 0, e
  dentro do `wrapInTransaction` é 1. No `ConfirmarCadastro`, o ouvinte de teste grava
  `getTransactionNestingLevel()` no momento do evento e o teste afirma 0. Nos aceites (sem transação), o ouvinte de
  teste afirma que o efeito do último `flush` já está no banco (o aceite do termo gravado, por consulta direta).

**`conv` e convite de primário (Q4, decidida):** o `conv` manda as boas-vindas a quem entrou por qualquer convite; o
evento leva `comoPrimario` para o texto ou a regra mudarem sem mexer em Auth.

**Código no e-mail de confirmação (Q5):** o dc descreve o `cad` como "Envia o link e o código" e o modelo tem um
código de 6 dígitos. O sistema só tem o link. Acrescentar código é mudança no fluxo de cadastro (identidade, a tela de
verificação teria de aceitar o código): **não entra na 1a**; fica com a frente do desenho 10 (decisão de 19/10).

**Na tela (parcial do #56, `_comercial.html.twig`):** só o bloco "Automações" na 1a (os outros blocos nas fatias
seguintes), com:
- interruptor (POST `master_comercial_automacao`, JSON, `X-CSRF-Token`, auditado). O `cad` mostra o interruptor
  ligado e **desabilitado**, com a dica "Obrigatório: sem ele ninguém consegue criar conta" (P2, com o dono; o Auth
  não lê o estado do Comercial, então o interruptor do `cad` não teria efeito, e um interruptor sem efeito enganaria o
  Master). Se o dono recusar P2, o `cad` volta a esta spec;
- seletor de atraso; botão do modelo (abre a prévia do modelo, só leitura, com "Editando o modelo da automação "<t>".
  Duplique para ajustar o texto." como no dc; o editor e o duplicar são da fatia 2);
- **"Simular novo cadastro" como no dc (I11):** grava dois envios `situacao = simulado`, `origem = simulacao`, para
  `novo.cliente@exemplo.com` (nenhum e-mail sai), e mostra a mensagem do dc. Ficam fora de qualquer número do
  Monitoramento e são apagados pela purga depois de 7 dias.
- **"Últimos disparos":** os 6 últimos envios com `origem` `automacao:*` ou `simulacao`, com o gatilho do dc ("Conta
  aberta", "Lembrete de confirmação", "E-mail confirmado", "Convite aceito", "7 dias depois", "Espaço em 90%", "Espaço
  esgotado", "Pedido de espaço"), assunto, destinatário mascarado (`a***@dominio.com`, o Master não precisa ver o
  endereço inteiro numa lista de conferência) e data.

### 3.6 Transporte e vazão

- **Transporte próprio `comercial`** (doctrine, `queue_name: comercial`), para um lote de e-mails não atrasar o Drive e
  a IA (M4). O worker de produção passa a consumir **`comercial async`**, nesta ordem (M-m): os e-mails vêm primeiro e
  são curtos, e a vazão faz um lote esperar com `DelayStamp` em vez de ocupar o worker (`docker-compose.prod.yml`: muda
  o comando do `jusprime_worker_prod`; é deploy, o dono aplica). No `when@test`, `comercial` é `in-memory` (M-l).
- **Vazão sem cache (I6):** a fonte é o próprio `comercial_envio`, que sobrevive a deploy e a `cache:clear`:
  - **Cota da caixa (revista em 23/10/2026):** produção envia por `smtps://smtp.hostinger.com:465` (caixa
    `infra@bluejus.com.br`), plano Hostinger Business Standard/Premium: **3.000 envios por caixa em 24 h CORRIDAS**. A
    regra mora na `App\Comercial\Envio\CotaDeEnvio`, com margem:
  - por minuto: `COUNT(*) WHERE situacao = 'enviado' AND enviado_em > agora - 60 s` < `COMERCIAL_ENVIOS_POR_MINUTO`
    (padrão **10**); sem vaga, `DelayStamp` de 60 s;
  - teto total em 24 h corridas: `COUNT(*) WHERE situacao = 'enviado' AND enviado_em >= agora - 24 h` (todas as
    origens) < `COMERCIAL_TETO_DIARIO` (padrão **2.400**, 600 abaixo dos 3.000). Janela corrida, nunca o dia do
    calendário: sem vaga, a mensagem espera o enviado mais antigo da janela sair dela (`+ 24 h + 60 s`); ao reavaliar
    ainda sem vaga, adia de novo;
  - campanhas (`origem LIKE 'campanha:%'`): teto próprio `COMERCIAL_TETO_DIARIO_CAMPANHAS` (padrão **1.600**) e uma
    **reserva** dos demais e-mails, derivada (teto − teto das campanhas = **800**). No handler, a campanha sai se os
    enviados de campanha na janela < 1.600 E `enviados de campanha + max(demais, 800) < 2.400`. No expansor (§8.5),
    `vagas = min(1.600 − registrados de campanha, 2.400 − max(demais, 800) − registrados de campanha)`, mínimo 0
    (registrados = `criado_em` na janela, sem bloqueado e cancelado: reserva o que já está na fila). Assim a campanha
    nunca toma os 800 dos transacionais, e se eles passam de 800 a campanha encolhe;
  - **Prioridade (N6):** os transacionais **do ciclo da conta** (`automacao:cad`, `lemb`, `val`, `conv`, `e90`,
    `e100`, `ped`), o "Enviar teste" (`teste_campanha`, §8.4) e o `resumo_diario` respeitam só o limite por minuto
    (`CotaDeEnvio::ORIGENS_ISENTAS`, espelhada em `EnviarEmailHandler::ORIGENS_ISENTAS_DO_TETO_DIARIO`), mas **contam no
    total** (a margem até os 3.000 os cobre). Todo o resto (`lista`, confirmações de endereço, consumidores) respeita
    os dois;
  - **Conta TODOS os e-mails:** os síncronos (`MailerInterface::send` direto: cadastro, convite, senha, exclusão,
    Push, envio ao cliente, trabalho externo, falha do agendador, `app:test-mailer`) viram uma linha `sistema`
    (transacional, `enviado`, primeiro "Para", assunto, sem conteúdo) pelo
    `RegistroDeEmailSincronoListener` (`SentMessageEvent`). O do Carteiro e a confirmação do cadastro (já gravada como
    `automacao:cad`) levam o cabeçalho interno `X-Bluejus-Registrado`, que o ouvinte tira antes de a mensagem sair
    (`MessageEvent` do transporte, prioridade −1024) e usa para não duplicar. Falha ao registrar vai ao log e nunca
    derruba o envio. A linha `sistema` não aparece em "Últimos disparos" (filtra `automacao:` e `simulacao:`);
  - **Aviso ao Master** (só `ROLE_SUPER_ADMIN`, no topo de toda tela, componente `ae-aviso` do desenho 08, como o da
    falha do agendador), calculado na hora (sem tabela): com os enviados em 24 h ≥ `COMERCIAL_AVISO_DA_COTA_PERCENTUAL`
    (padrão **80**%) do teto, o visual de atenção ("E-mails: X de 2.400 enviados nas últimas 24 horas (limite da
    Hostinger). Perto do limite."); com as campanhas pausadas (campanha esperando e sem vaga), o visual cheio com a
    hora em que a vaga volta. "Dispensar" (POST com CSRF, `master_comercial_cota_dispensar`) guarda na sessão a chave
    do episódio (estado e, nas pausadas, o dia da volta) e esconde o aviso até o estado mudar. Sem origem na Central de
    Notificações.

### 3.7 Layout base e rodapé

`templates/email/comercial/_layout.html.twig`: tabelas e estilo inline, cabeçalho do dc (degradê azul, logo, selo,
título), corpo, versão texto gerada. **Rodapé sempre (B3), não editável, conforme o escopo (I5):**
- **plataforma:** "BlueJus Gestão e Tecnologia LTDA · CNPJ 66.803.163/0001-10 · Brasília/DF" e a frase do dc em
  `lista`; em `transacional`, "Você recebeu este e-mail por causa da sua conta no BlueJus." (a frase do dc seria falsa
  para quem ainda não consentiu, M1). No e-mail de confirmação de endereço (§3.3): "Este endereço foi informado em
  <origem>. Se não foi você, ignore este e-mail.";
- **escritório (`escopo` = Tenant):** "Enviado por <nome do escritório> por meio do BlueJus" (o controlador ali é o
  escritório);
- **link de descadastro em todo e-mail, como no dc (M1):** em `lista`, "Não quero mais receber" com o `gerarLink` da
  própria lista; em `transacional`, o texto diz o que o link faz (M-k): "Não quero receber novidades do BlueJus" com o
  `gerarLink(escopo, email, 'todos')` (os avisos da conta continuam, porque não consultam o descadastro);
- **link da Política de Privacidade (B4).**

### 3.8 Rastreio (desligado até a Política)

- **Ligado só quando a Política citar o rastreio (I10, M-h):** `COMERCIAL_RASTREIO_LIGADO` (padrão `false`) **e** a
  constante `PoliticaPrivacidadeVigente::CITA_RASTREIO_DE_EMAIL` (nova, `false`; quem publicar a versão que cita o
  rastreio a troca para `true`, combinado com a f6). Sem comparar versões. Desligado, `rastrear` é ignorado.
- Abertura: `<img>` 1x1 para `GET /e/a/{token}.gif`; grava a primeira abertura e soma. Responde o mesmo GIF para token
  inválido.
- Clique: cada `href` http(s), exceto o de descadastro e o da Política, vira `GET /e/c/{token}?u=<url>&s=<assinatura>`
  (UriSigner sobre `token` e `u`): grava e responde 302 para `u`; assinatura errada responde 404, sem redirecionar.
- Limitador por IP nas duas rotas (com o IP real quando o dono configurar `SYMFONY_TRUSTED_PROXIES`; até lá, vale como
  teto global, 600 por minuto).

### 3.9 Rotas públicas e segurança (M7)

`/e/a/{token}.gif`, `/e/c/{token}`, `/e/confirmar/{token}` (GET e POST): cada uma em linha própria em `security.yaml`
(`PUBLIC_ACCESS`), como pede o contrato da 0d; limitadores em trecho próprio de `framework.yaml`. Sem sessão no pixel
e no clique.

## 4. Fatia 1b: automações de espaço (sobre `contribua-c2`)

| Chave | Gatilho (eventos da f6, já depois do commit) | Quem recebe | Regra |
|---|---|---|---|
| `e90` | `UsoDoEspacoSubiu` com `cruzou(limite)`, limite = 80, 85 ou 90 (o escolhido) | os primários ativos do escritório | 1 por escritório por dia |
| `e100` | `EspacoAtingiuFaixa` com faixa `Bloqueio` **e `retrato.bloqueioAtivo`** (sem o bloqueio ligado, "novos envios pausados" seria falso, como a f6 decidiu) | idem | 1 por escritório por dia |
| `ped` | `EspacoFoiPedido(tenantId, codigoDoPlano, pedidoPorUserId)` | os primários ativos (também quando o Master pediu por eles); o aviso ao Master continua da f6 | 1 por escritório, plano e dia |

Deduplicação em `comercial_aviso_espaco` (`tenant_id`, `chave`, `dia` em America/Sao_Paulo, `plano` NOT NULL DEFAULT
`''`), UNIQUE nas quatro colunas (insert que falha = já mandou; o `plano` vazio no `e90` e no `e100` colide de
verdade, N5). Todos `transacional` (aviso do serviço contratado), escopo plataforma. Ouvintes protegidos como em §3.4.

**`e100` raro com o bloqueio ligado (I2):** com o bloqueio ligado, o envio que estoura é recusado antes do evento, e a
faixa `Bloqueio` só é atingida quando o uso fecha exatamente no teto. Pedido à f6 (pergunta Q6): um evento
`EnvioRecusadoPorEspaco(tenantId)` na primeira recusa, que dispara o `e100`.

**Decisões da implementação (21/10, técnicas, da 5d):**
- O título e o texto do `e90` usam o limite escolhido (80, 85 ou 90). O dc mostra o padrão, 90%; dizer "90%" a quem
  cruzou 80 seria falso. A prévia do Master também mostra o limite escolhido.
- A f6 entregou o `EnvioRecusadoPorEspaco` (Q6). Ele sai na hora, antes da exceção. Numa porta, a cópia de documentos
  da pasta (`CopiarDocumentosDaPastaUseCase`), a recusa acontece dentro de uma transação, e o rollback levaria junto a
  reserva e o envio (o transporte `comercial` é Doctrine na mesma conexão). Por isso, com transação aberta, o ouvinte
  guarda a recusa e manda no fim da requisição, do comando ou da mensagem do worker, com ou sem erro.
- Reserva e envio numa transação própria do ouvinte: se o envio falhar, a reserva volta e o aviso pode sair no
  próximo evento. Desligada, a automação não consome a vez.

## 5. Testes

**1a.** Unit: Carteiro (descadastrado não envia; transacional não consulta; DTO incoerente recusado; registro e
despacho atômicos); handler (reivindicação atômica com duas entregas; descadastro relido; vazão por minuto e dia;
transacional só no limite do minuto; volta a `enfileirado` na exceção); `FalhaDeEnvioListener` (`falhou` só sem
retry; erro sanitizado); layout (rodapé por escopo e tipo; link de descadastro em todo e-mail; `List-Unsubscribe` só
em `lista`); reescrita de links (descadastro e Política intocados); rastreio desligado ignora `rastrear`; enum das
automações (padrões do dc). Integração: `comercial_envio` (token único, retenção com hash e nulls); consentimento
(fatos só de acréscimo; token de uso único, vencido, trocado por novo pedido; purga das pendências); varredura
(reivindicação, `enviando` velho volta, automação ligada depois não alcança o passado, `lemb` uma vez, `dica` uma
vez). Functional: pixel e clique (token inválido igual; assinatura errada 404; sem login); confirmar endereço (GET não
grava; POST grava com IP; vencido; usado; limitadores por e-mail, IP e global); interruptor só para
`ROLE_SUPER_ADMIN`, com CSRF, auditado; "Simular" não envia e grava `simulado`; os quatro ganchos (cada evento uma
vez, nos dois retornos do `ComConta`, no nível de transação de base do DAMA); falha do ouvinte não muda a resposta do
cadastro nem do aceite; `ReemitirConfirmacaoDeCadastroUseCase` (uma vez, recusa confirmado); purga do pendente só 72 h
depois de vencer; `CadastroMailer` registra o envio. E-mails com `MailerAssertionsTrait` e transporte em memória.

**1a, casos da v3:** conteúdo do envio apagado no estado final (o corpo do `lemb` não sobra); Carteiro grava sem
`flush` do EntityManager (uma entidade pendente do chamador não é gravada); varredura devolve `enviando` velho e
redespacha `enfileirado` velho; purga de escritório com as tabelas novas (retidas e apagadas) não falha; `pedir`
sobre confirmado não envia nem desconfirma; `pedir` para descadastrado no máximo 1 a cada 30 dias; purga não apaga
estado confirmado; rótulo legível da origem; `conv` não repete na reativação; confirmações de endereço param no teto
diário e as boas-vindas não; `ConfirmacaoDeCadastroEnviada` registrada pelo ouvinte e falha dele não muda a resposta
do `/cadastro`; interruptor do `cad` desabilitado; `CITA_RASTREIO_DE_EMAIL = false` ignora `rastrear`.

**1b.** `e90` cruzando 80, 85 e 90 (só o escolhido); deduplicação por dia (com o `plano` vazio colidindo), inclusive
descendo e subindo de novo, e virada do dia no fuso de São Paulo; `e100`
com e sem `bloqueioAtivo`; `ped` pelo primário e pelo Master; destinatários = primários ativos.

## 6. O que o dono olha na tela

**1a:** no `/master#sec=com`, o bloco Automações: ligar e desligar, atraso, "Simular novo cadastro" (aparece em
"Últimos disparos", nada é enviado), e um cadastro de teste: chega a confirmação, aparece "Conta aberta" nos "Últimos
disparos", e depois de confirmar chegam as boas-vindas. **1b:** um escritório de teste perto do limite.

## 7. Perguntas (v2)

Ao dono, pelo coordenador (do pacote anterior): **P1** provedor de envio; **P2** "Ao abrir uma conta" travado em
ligado; **P4** retenção de 24 meses. Novas:
Decididas pelo coordenador em 19/10: Q2, Q3, Q4, Q5 (frente do desenho 10), Q6 (com a f6), Q7. Só a Q1 vai ao dono.
- **Q1.** A conta do `MAILER_DSN` de produção é Gmail gratuito, Workspace ou Hostinger? Define se 1.500/dia é seguro.
  **Respondida (23/10/2026):** Hostinger Business Standard/Premium, 3.000 por caixa em 24 h corridas; ver §3.6.
- **Q2.** Guardar o cadastro pendente vencido por mais 72 h (com nome, OAB e hash da senha) para o lembrete de 48 e 72 h
  funcionar. Alternativa: o lembrete só oferece 24 h (desvio do dc).
- **Q3.** Quem entra por convite de plataforma (`AceitarConvitePlataformaUseCase`, conta sem escritório) recebe
  boas-vindas? Proposta: sim, as do `val`.
- **Q4.** O `conv` vale também para convite de primário? Proposta: sim (o evento diz qual foi).
- **Q5.** Código de 6 dígitos no e-mail de confirmação, como no dc: mudança no fluxo de cadastro (ALTO), fora da 1a.
- **Q6.** (à f6) Evento na primeira recusa por espaço, para o `e100` não depender de fechar exatamente no teto.
- **Q7.** "Últimos disparos" com o destinatário mascarado (`a***@dominio.com`): o dc mostra o endereço inteiro.

## 8. Fatia 2: campanhas (v3 da fatia, 19/10)

Branch `comercial-2`, **empilhada sobre `comercial-1a` (#99)**, declarada. Desenho: dc L229-260 (template) e L482-560
(script); a lista de campanhas é dc L282-286 e L586. Risco MÉDIO (envio em massa, LGPD). v1 não aprovada na revisão
(achados R1 a R16, tratados na v2); v2 não aprovada (A1 a A11, tratados nesta v3). Dois PRs:

- **2a.** Modelos (fixos, de automação e próprios), editor de blocos, HTML importado, prévia, "Baixar HTML" e "Enviar
  teste". **Nada sai para mais de uma pessoa** (o teste vai só ao Master logado). Não cria campanha.
- **2b.** Públicos, "Enviar campanha", agendamento, frequência, expansor em lotes, a lista de campanhas do dc
  (L282-286, sem as métricas, que são da fatia 3) e o freio de console.

### 8.1 Regra de ouro: nada dispara em massa sem ação explícita

1. **Não há rascunho.** A campanha só nasce no "Enviar campanha", depois da confirmação literal do dc (L560:
   `Enviar "ASSUNTO" para N destinatário(s) agora?` ou `em AAAA-MM-DD HH:MM?`). O pedido leva uma chave de
   idempotência gerada na tela (UNIQUE): clique duplo não cria duas campanhas. (R5)
2. **Chave da instalação** `COMERCIAL_CAMPANHAS_LIGADAS` (padrão `0`). Desligada, **"Enviar campanha" é recusado**
   ("O envio de campanhas está desligado nesta instalação."). **Desligar é parada de emergência:** na volta seguinte
   o expansor cancela TODA campanha ativa (`na_fila`, `agendada`, `enviando`, `recorrente`) com o motivo "Envio de
   campanhas desligado na instalação"; religar não solta nada represado (cada campanha precisa ser confirmada de novo).
   A chave é variável de ambiente: trocar exige reiniciar o worker (em produção, deploy); **não** é o freio do dia a dia
   (item 5). **A confirmar com o dono.** (R3, A2)
3. Deploy, migration e semeadura nunca criam campanha nem envio.
4. **Nada é recuperado:** a recorrente que perdeu execuções (worker parado) pula para a próxima data futura; a
   `agendada` que acordar mais de 24 h atrasada vira `cancelada` com o motivo, sem enviar. (R3)
5. **Freio sem deploy (2b):** `app:comercial:encerrar-campanha <id>` (console) encerra qualquer campanha ativa
   (`cancelada`), e o expansor para no lote seguinte; o que já estava `enfileirado` no Carteiro também não sai: o
   `EnviarEmailHandler` confere, para origem `campanha:<id>`, se a campanha foi cancelada e marca o envio como
   `cancelado`. (A10) É o freio enquanto A3 (Pausar e Encerrar na tela) espera o dono;
   a tela da 2b mostra as campanhas ativas (lista do dc L586). **A confirmar com o dono** (A3). (R2)

### 8.2 Modelos (2a)

- **Fixos** (`ModelosDeCampanha`, dc L504-507 e L514): `convite`, `vagas`, `obrigado`, `novidades` (simples: corpo,
  itens, cta, link) e `news` (blocos). Textos literais do dc.
- **De automação** (dc L508-513: `validar`, `boasvindas`, `primeiros`, `espaco90`, `espaco100`, `pedido`): entram na
  grade como **cópias em blocos, literais do dc** (JSON de blocos em `ModelosDeCampanha`), independentes dos templates
  Twig das automações da 1a. Assim "Duplicar" (dc L550) tem blocos para copiar e a campanha congela `modo = blocos`.
  **O que as automações enviam não muda** (seguem os templates da 1a e da 1b); alinhar o estilo desses templates ao dc
  (h2 17 px contra 20 px de dc L488, `<p>` contra `<tr><td>` de L489) é follow-up da 1a, registrado. O "Ou use o código
  482 917" do `validar` (dc L508) fica fora (Q5), e o botão `validar` vira `app` (8.3). Usar `validar` ("Confirme seu
  e-mail") como campanha engana quem recebe; o dc deixa: **a confirmar com o dono**. (A1)
- **Datas comemorativas** (dc L517-521, L529) entram na grade com a **fatia 4** (`CalendarioDoSistema`).
- **Próprios** em `comercial_modelo` (no lugar do `localStorage 'bj-com-modelos'`): `id`, `nome` (1-40), `icone`
  (precisa casar `^bi-[a-z0-9-]{1,40}$`, senão `bi-file-earmark`), `modo` (`simples` | `blocos` | `html`), `assunto`,
  `selo`, `titulo`, `conteudo` (JSON dos blocos ou o HTML já sanitizado), `criado_por`, `criado_em`, `alterado_em`.
  Plataforma (sem tenant). (R8)
- Ações literais do dc: "Novo modelo" (L549), "Duplicar" (L550), "Salvar modelo" (L551, só próprio; num fixo: "Modelos
  padrão não são alterados. Use Duplicar para criar a sua versão."), "Excluir" (L552, `confirm('Excluir o modelo
  "NOME"?')`), "Importar HTML" (L553), com as mensagens do dc. Editar um fixo vale para a prévia e o envio, sem guardar;
  trocar de modelo descarta a edição sem aviso (dc). Excluir um próprio não mexe em campanha confirmada (ela guarda a
  cópia, 8.4).
- **Auditoria** (R6): `comercial_modelo` **não** é `Auditavel` (o subscriber gravaria 500 caracteres do conteúdo).
  Linha manual no `audit_log` (`action` até 10 caracteres: `modelo_new`, `modelo_upd`, `modelo_del`), entidade e id,
  `changes` só `{nome, modo}`.

### 8.3 Editor de blocos, HTML importado e renderização (2a)

- Os 7 tipos do dc L533, campos A/B e placeholders literais de L547, Subir/Descer/Remover e "+ TIPO" de L247-248.
- **`RenderizadorDeBlocos`** no servidor, estilos inline 1:1 de dc L488-494, texto sempre escapado, `\n` vira `<br>`.
  `{nome}` como no dc (titulo, texto, colunas; corpo e título do simples). Chaves de link do botão: `vagas`,
  `contribua`, `app`, `espaco` e `cadastro` (página pública de cadastro) viram as URLs do sistema; `validar` (sem token
  por destinatário numa campanha) vira `app`; outro valor só com `https://` ou `http://`, senão o botão sai sem link.
  Imagem só com `https://`. (R16)
- **Layout em três modos** (R7, A3): `padrao` (o atual da 1a, miolo num `<td>`), `linhas` (os blocos como `<tr>` da
  tabela de 600 px mais a linha espaçadora de 14 px, dc L496-499; o modo simples segue dc L500) e `cru` (o HTML
  importado mais só o rodapé legal). **Contrato do Carteiro:** `MensagemDeEmail` ganha `layout` (padrão `padrao`; a 0d
  e a a7 não mudam nada), gravado em coluna nova `layout` de `comercial_envio_conteudo` (migration da 2a, na fila) e
  lido pelo `EnviarEmailHandler` ao remontar; o `MontadorDeEmail` ganha os dois ramos. As automações continuam
  `padrao`.
- **HTML importado (desvio do dc, a confirmar com o dono)** (R7): o dc envia o HTML cru, sem cabeçalho nem rodapé (L486).
  Aqui ele entra cru, **mais só o rodapé legal** (B3); sem o cabeçalho azul. Até 200 KB.
- **Sanitização** (R8): `symfony/html-sanitizer` com tags de e-mail e estilos inline; sem script, iframe, form, object,
  eventos; URLs só http(s) e mailto. O `html-sanitizer` não analisa CSS: um sanitizador de atributo próprio tira do
  `style` as declarações com `url(`, `expression(` e `@import`. `<head>` e `<style>` caem (a tela
  avisa: "Estilos em bloco (<style>) não são mantidos; use estilos na própria tag."). Sanitiza **ao salvar e em toda
  montagem** (prévia, teste, "Baixar HTML", envio). `{nome}` no HTML importado é trocado **depois** da sanitização, pelo
  nome **escapado** (o nome pode vir do CSV). (A11)
- **Prévia** (R8, A4): como o dc (L253, "a prévia atualiza na hora"): a tela pede o HTML ao servidor (JSON `{html}`,
  com espera de 400 ms entre teclas) e o põe no `srcdoc` de um iframe `sandbox=""` (sem `allow-scripts` nem
  `allow-same-origin`); estilos inline e imagens funcionam, scripts não. Nome e e-mail de exemplo **como o dc** (L537): os do primeiro
  destinatário do público escolhido; sem público (2a), "Dra. Ana" e teste@bluejus.com.br. (R12)
- "Baixar HTML" (dc L558): o mesmo HTML da prévia, `email-CHAVE.html`.

### 8.4 Enviar teste (2a) e campanha (2b)

**Enviar teste (C4):** monta como a campanha e manda ao Master logado, `tipo = lista`, lista `comercial_campanhas`,
origem `teste_campanha` (fora do prefixo `campanha:` que o Monitoramento agrupa), `layout` conforme o modo. Isento do
teto diário: a lista `EnviarEmailHandler::ORIGENS_DO_CICLO_DA_CONTA` vira `ORIGENS_ISENTAS_DO_TETO_DIARIO` (ciclo da
conta mais `teste_campanha`) e a §3.6 é emendada; sujeito ao limite por minuto; teto de 10 testes por hora por Master. Mensagens: "Teste enviado para EMAIL.";
descadastrado da lista: "Você está descadastrado da lista de campanhas; o teste não foi enviado."; teto: "Limite de 10
testes por hora atingido." (R13, R14)

**`comercial_campanha` (2b):** `id`, `chave_idempotencia` (UNIQUE), `nome` (= assunto, como o dc), cópia congelada do
conteúdo (`modelo_chave`, `modelo_nome`, `modo`, `assunto`, `selo`, `titulo`, `conteudo`), `publico`,
`publico_parametros` (só os e-mails do CSV ou digitados **que passaram no filtro**), `frequencia` (`unica` | `semanal`
| `quinzenal` | `mensal`), `agendada_para` e `proxima_execucao` (local America/Sao_Paulo), `estado` (`na_fila` |
`agendada` | `recorrente` | `enviando` | `enviada` | `cancelada`), `motivo`, `execucoes` (quantas rodaram), `total`
(destinatários da última execução), `criada_por`, `criada_em`. **Não é `Auditavel`** (R6): linha manual no
`audit_log` na confirmação e no encerramento, `changes` = `{publico, frequencia, total}` (o dc audita "Campanha de
e-mail: ASSUNTO · N destinatário(s)", L560; aqui sem o assunto por minimização).

Estados (R10): única `na_fila`/`agendada` → `enviando` → `enviada` (quando o último destinatário foi entregue ao
Carteiro; o resultado de cada envio é do Monitoramento); recorrente fica `recorrente` entre execuções (marca a execução
em curso em `comercial_campanha_destino`, não no estado). Recorrente sem data começa agora. Próxima data: +7 dias, +14
dias, ou o **dia original** do mês seguinte limitado ao último dia (31/01 → 28/02 → 31/03; ancora sempre no dia da
primeira execução). (A9) Rótulos e cores do dc (L586) para
"Na fila", "Agendada", "Recorrente"; os demais em texto ("Enviando", "Enviada", "Cancelada").

### 8.5 Públicos e envio (2b)

| chave | fonte no sistema |
|---|---|
| `leads` "Base de contatos" | confirmados na lista **`comercial_campanhas`** e não descadastrados (consulta nova). Hoje não há formulário que peça esse consentimento: o público mostra 0. **Não usa `vagas_alerta`**: o consentimento dele é para alertas de vagas (`RotulosDoConsentimento`), e a Política exige consentimento para comercial a não clientes (LGPD art. 8 §4). Usar `vagas_alerta` para campanha de vagas seria decisão do dono. **A confirmar.** (R1) |
| `prim` "Usuários primários" | vínculo ativo com papel de sistema, usuário ativo, escritório não suspenso nem excluído |
| `sec` "Usuários secundários" | vínculo ativo sem papel de sistema, mesmas condições (clientes da plataforma: legítimo interesse com descadastro; **a confirmar com o dono**) |
| `todos` "Todos os cadastrados" | `prim` + `sec` |
| `csv` "Importar lista (CSV)" | parsing do dc L545 no servidor; até 5.000 linhas / 1 MB |
| `indiv` "Individual" | e-mails digitados (dc L239), até 50 |

- **Envio:** `tipo = lista`, `lista = comercial_campanhas`, `origem = campanha:<id>`, `rastrear = true` (só vale com a
  constante da 1a), `layout` conforme o modo; reply-to pela `PoliticaDeDestinatarios`. (A7)
- O rótulo do dc "Inscritos pela página de vagas e campanhas" (L539) fica literal, embora a fonte hoje não inclua a página
  de vagas: **desvio de texto a confirmar** (trocar o rótulo ou a fonte é do dono). (A8)
- Deduplicação por e-mail minúsculo e retirada dos descadastrados (`comercial_campanhas` ou `todos`), como o dc. A
  linha do dc L254 conta K **desta lista** (o dc conta o global: número errado; desvio a confirmar). (R12)
- **B1 restritivo (a confirmar com o dono):** `PoliticaDeDestinatarios::exigirAutorizacaoEmCsvEIndividual()` = `true`:
  no CSV e no Individual só entra usuário ativo cadastrado ou confirmado em `comercial_campanhas`; os demais saem e a
  linha ganha "· J sem autorização fora da lista". Os recusados não são gravados. O B1 é **reaplicado a cada execução**
  da recorrente (quem foi desativado depois da confirmação sai). (R1, R9, A10)
- **Reply-to (a confirmar):** `PoliticaDeDestinatarios::replyToDaCampanha(): ?string` = `null` (padrão da 1a).
- Textos do dc: "Nenhum destinatário nesta lista." (L560), rótulo "Agendar" / "Primeiro envio" (L557). Data passada:
  "Escolha uma data futura." (o dc aceita; convenção, a confirmar). Mensagem de sucesso: o dc diz "Campanha
  agendada/enfileirada: N e-mail(s). O servidor envia em lotes e registra entregas, aberturas e cliques." (L560); com o
  rastreio desligado, "aberturas e cliques" seria falso: a frase termina em "registra as entregas." até o rastreio
  ligar. **A confirmar.** (R11) O N da confirmação é o da hora; o da execução pode mudar (novos cadastros, descadastros).
- **Expansor** (R4, R5, R10, A5): volta `ExpandirCampanhas` no transporte `comercial`, com **linha de controle**
  `comercial_expansor` (id 1, `proxima_em`) e **reivindicação atômica** como a varredura da 1a (`UPDATE ... SET
  proxima_em = :depois WHERE id = 1 AND proxima_em <= :agora`; quem não reivindica sai sem fazer nada, então
  despachos extras não criam cadeia paralela). Campanha **ativa** = `na_fila`, `agendada`, `enviando` ou `recorrente`.
  Desde 23/10/2026 o schedule `cadeias` a dispara a cada 60 s (`docs/specs/scheduler-tarefas-agendadas.md`, "Cadeias no Scheduler"); sem nenhuma ativa,
  ela sai sem tocar na linha. A janela da reivindicação é de 30 s (metade do intervalo). Saíram o reagendamento com
  `DelayStamp`, o "acordar" da confirmação (a campanha confirmada sai na volta do minuto seguinte), a rede de
  segurança da `VarrerAutomacoes` e a semente do `app:comercial:varredura`. A cada volta: confere a chave
  (8.1.2); materializa a execução devida em `comercial_campanha_destino` (`campanha_id`, `execucao`, `email`, `nome`,
  `envio_id`; UNIQUE nos três primeiros); libera um lote dentro de **uma transação da conexão**: `SELECT ... WHERE
  envio_id IS NULL FOR UPDATE SKIP LOCKED LIMIT n`, `Carteiro::enviar` e grava o `envio_id` (o Carteiro vira savepoint
  dentro dela, `use_savepoints: true`). Queda no meio = nada gravado, nada duplicado.
- **Vazão própria da campanha** (R4): lote de até **10 por minuto** (o limite por minuto do handler vale para todos e
  segura a vazão real), **teto das campanhas em 24 h corridas de 1.600** (configurável, sem tomar a reserva de 800 dos
  demais dentro dos 2.400; regra completa em §3.6, `CotaDeEnvio::vagasDeCampanha`), e **contrapressão**:
  o próximo lote só sai se os envios de campanha ainda `enfileirado` ou `enviando` forem menos que um lote. Assim o conteúdo não se
  acumula em `comercial_envio_conteudo`.
- **No máximo uma vez** (decisão do coordenador, 20/10): campanha em massa nunca sai duas vezes para a mesma pessoa.
  O handler marca `enviando` (a reivindicação) antes do SMTP; se o worker morre ali, a recuperação de 15 min marca o
  envio de campanha como `incerto` (nova situação de `comercial_envio`, coluna VARCHAR sem CHECK, sem migration), apaga o
  conteúdo e não reenvia. Uma exceção do SMTP num envio de campanha também vira `incerto`, com o conteúdo apagado, e
  não relança para o retry do Messenger (um timeout depois de o servidor aceitar duplicaria a mensagem). O `incerto` não conta na contrapressão nem nos enviados da janela, mas ocupa a vaga do expansor (é registrado de campanha). Os transacionais, os
  consumidores e o `teste_campanha` continuam "pelo menos uma vez" (§3.1). O freio do console só cancela o
  `enfileirado`; o `enviando` fica com o worker.
- **Retenção** (R9): `comercial_campanha_destino` segue a da §3.2 (P4, 24 meses; depois `email` vira hash e `nome`
  nulo); `publico_parametros` é apagado quando a campanha vira `enviada` ou `cancelada`.

### 8.6 Rotas (Master, `ROLE_SUPER_ADMIN`, JSON com `X-CSRF-Token` `master_comercial`, padrão da 1a)

2a: `GET /master/comercial/modelos`, `POST /master/comercial/modelos` (novo, duplicar, importar), `POST
/master/comercial/modelos/{id}` (salvar), `POST /master/comercial/modelos/{id}/excluir`, `POST
/master/comercial/previa-campanha` (HTML, CSP sandbox), `POST /master/comercial/teste`. 2b: `POST
/master/comercial/publicos/contagem`, `POST /master/comercial/campanhas` (confirmar), `GET /master/comercial/campanhas`.

### 8.7 Testes

2a: renderizador (cada bloco contra o estilo do dc, escape, URL recusada, `{nome}`, chave `validar` vira `app`);
sanitizador (script, evento, `javascript:`, `url(` no style, `<style>` cai); modelo fixo não se salva; ícone fora da
lista; teste: teto por hora, descadastrado, isento do teto diário; prévia com CSP sandbox; auditoria sem conteúdo;
rotas com 403 sem super admin, CSRF e 422. 2b: chave desligada recusa; idempotência; B1; deduplicação e descadastro;
expansor (UNIQUE, transação na queda, contrapressão, teto diário da campanha, agendada atrasada 24 h cancela,
recorrente pula, +1 mês no fim do mês, comando de encerrar).

### 8.8 Decisões provisórias (coordenador e orquestrador, 19/10): todas "a confirmar" com o dono

1. `COMERCIAL_CAMPANHAS_LIGADAS` nasce desligada e recusa o envio enquanto desligada (nada represado).
2. B1 restritivo no CSV e no Individual, com "sem autorização fora da lista".
3. **Revista na v2 (R1):** "Base de contatos" = confirmados em `comercial_campanhas` (hoje 0), não `vagas_alerta`.
   Nenhum desenho colhe consentimento para campanhas (busca em todos os `.dc.html`, 19/10): só "Autorizo o BlueJus a me
   enviar vagas por e-mail" (Vagas Publicas L126, lista `vagas_alerta`), o do currículo (06 Trabalho Externo L283) e o
   aceite de Termos no cadastro (10 Login L101). A lista fica vazia até o dono decidir a fonte; opções: autorizar
   `vagas_alerta` só no modelo "Ofertas · vagas abertas" (dc L506), ou uma caixa nova no desenho.
4. Recorrente sem Encerrar na tela (A3); freios: a chave e o comando de console.
5. HTML importado cru mais o rodapé legal (o dc manda sem rodapé; B3 exige).
6. A frase de sucesso sem "aberturas e cliques" enquanto o rastreio estiver desligado.
7. K "desta lista" e recusa de data passada (convenção).
8. Secundários como clientes (legítimo interesse).
9. O rótulo "Inscritos pela página de vagas e campanhas" (dc L539) mantido literal com a fonte nova (A8).
10. O modelo `validar` disponível como campanha (A1).
11. O botão `espaco` aponta para `/contribua#espaco` (comprar espaço fica no Contribua); o dc aponta para a Lista do
    Expediente (revisão da 2a).
12. Para a 2b (revisão da 2a): `{nome}` vindo do CSV entra no `style`/`href` do HTML importado só escapado para HTML;
    atinge só o e-mail da própria pessoa, mas a 2b sanitiza o nome do CSV (sem `;:()<>"'`). O teto de 10 testes por
    hora não é atômico (só o Master chega lá; o limite por minuto vale).
13. A recorrente com até 24 h de atraso executa uma vez; com mais, pula (mesma tolerância da agendada).
14. Desempenho: a contagem e a execução consultam o descadastro um endereço por vez; consulta em lote com a 0d é follow-up.

### 8.9 Inventário do dc (19/10), para conferir item a item

G1 arquivo só no repositório principal; G2 estados além dos 3 do dc; G3 recorrência sem regra (8.4); G4 agendamento
sem fuso (local America/Sao_Paulo); G5 envio em massa sem lote (8.5); G6 reply-to (8.5); G7 B1 (8.5); G8 listas de
descadastro (`comercial_campanhas`); G9 contagens brutas e K global (8.5); G10 base de contatos (8.5, R1); G11 modelos
(8.2); G12 selo, link do CTA simples e itens sem editor (mantido como o dc); G13 `{nome}` só onde o dc troca; G14 HTML
importado (8.3); G15 URLs (8.3); G16 CSV (8.5); G17 teste (8.4); G18 auditoria (8.2, 8.4); G19 `MODELOS[mk].r` quebra
com próprio (a campanha guarda `modelo_nome`); G20 rastreio (`rastrear = true`, vale com a constante da 1a); G21
"Baixar HTML" (8.3); G22 teto diário (8.5).

## 9. F2: motor dos filtros da campanha (desenho 05 VS 21.10.26)

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`, "Filtrar destinatários" (template
L258-264, script L631-671). Frente 5d; a tela é a F3. Depende da F1 da 7d (#188, gênero e atuação no Meu Perfil). Risco
MÉDIO (envio em massa). Sem migration: o filtro fica congelado no `publico_parametros`.

### O que o desenho pede (e entra inteiro)
- Nove campos, cada um um select de valor único (vazio = todos), combinados com E: busca livre (e-mail ou nome; termos
  separados por espaço, vírgula ou ponto e vírgula, com OU entre eles), Gênero (F, M), Idade (18 a 24, 25 a 34, 35 a
  44, 45 a 54, 55 ou mais, em anos completos), Estado (as 27 UFs), Perfil (primário, secundário), Cargo (Advogado(a),
  Estagiário(a), Controladoria, Financeiro, Administrativo, Outros), OAB (confirmada, pendente, sem), Plano e
  Aniversário (este mês, próximo mês). Chaves de troca com a tela: `q gen id uf papel cargo oab plano aniv`.
- Os atalhos (Só mulheres, Só homens, Advogados, Estagiários, Primários, Aniversariantes do mês) trocam o filtro
  inteiro: são da tela (F3).
- "N de M atendem ao filtro · K sem essa informação no cadastro (ficam de fora)": M é a lista depois de tirar
  repetidos, descadastrados e o B1; K conta cada pessoa uma vez, no primeiro campo ativo cujo dado falta, na ordem do
  dc (gênero, idade, UF, cargo, aniversário). Perfil, OAB e plano não contam como falta: quem não tem conta só não
  atende. A grade de "2 · PARA QUEM" continua sem o filtro (dc L640).

### Decisões do coordenador (21/10, por delegação do dono; o dono pode rever)
- **D1. Listas sem cadastro:** os e-mails do CSV, do Individual e da Base de contatos são cruzados com as contas: quem
  tem conta ganha os dados dela; quem não tem fica sem informação.
- **D2. UF:** a regra do mapa do Master (`LocalizacaoDaPessoa`): lotação "Cidade/UF", depois a OAB, depois o estado do
  escritório.
- **D3. Pessoa em vários escritórios:** Perfil = primário se é primário em algum; Plano bate se algum escritório válido
  tem o plano.
- **D4. Opções de Plano:** as do catálogo (inclui o Avançado), e não só as três do dc. Sem espaço gravado = Free.
- **D5. Cargo:** só a atuação declarada no Meu Perfil (F1, sem backfill), sem deduzir do vínculo. No começo quase todos
  ficam sem informação, como o dono pediu ("o filtro tem que corresponder à realidade do sistema").
- **D6. OAB pendente:** não verificada ou divergente.
- **D7. Idade e aniversário:** calculados na execução; numa campanha recorrente, cada mês pega os aniversariantes da vez.

### Como funciona
- `FiltroDoPublico` (DTO, valida os valores do dc), `AtributosDosDestinatarios` (três leituras em lote: vínculos
  válidos, Meu Perfil e o plano pelo Contribua, que é o dono do espaço) e `FiltroDeDestinatarios` (puro, na ordem do dc).
- O filtro roda no fim do `PublicosDaCampanha::resolver`, só quando está ativo.
- A contagem devolve `filtro: {ativo, quantos, base, atendem, semInformacao}` para a tela.
- **Congelamento:** sem filtro, a campanha guarda o que guardava (a lista do CSV ou do Individual já resolvida). Com
  filtro, guarda `{informados, filtro}`, com a lista que chegou ao filtro (sem repetidos, descadastrados e barrados pelo
  B1: não guarda quem nunca vai receber), e o expansor reaplica os dois a cada execução.
  O repositório lê os dois formatos. O `publico_parametros` já era apagado ao terminar; a busca livre (nomes e e-mails)
  segue a mesma regra. A trilha `camp_conf` continua só com `{publico, frequencia, total}` (R6).
- **Robustez (revisão de 21/10):** filtro congelado que não passa mais na validação cancela a campanha com motivo
  próprio (tirar o filtro alargaria o público); os IN vão em blocos de 10 mil (limite de parâmetros do PostgreSQL);
  nascimento no futuro conta como sem a informação; "Prefiro não informar" (`nao_informado`) também (contrato da F1);
  OAB com número e sem status é pendente, como no dc; plano fora do catálogo não atende ninguém, sem erro.

## 10. F3: a tela da Central de envios (desenho 05 VS 21.10.26)

Divisão (coordenador, 21/10): F3a editor (selo e nome na prévia, emojis, barra de ações com "Programar envio", "Ver
como vai chegar" computador e celular, "Salvar como meu modelo", linha de destinatários com repetidos e inválidos), F3b
UI dos filtros, F3c KPIs embaixo e Descadastrados, F3d e F3e relatório em caixa de entrada (da 9b), F3f teste novo e
gravado, F3g confirmação de leitura, F3h modo venda na seção Indicações, F3i imagem no modo simples, F4 Datas
comemorativas. Com migration: F3e, F3g e, provavelmente, F3i e F4 (números pedidos na vez).

### Decisões do coordenador (21/10, por delegação do dono; o dono pode rever)
- O teste gravado conta no KPI "Campanhas", como no dc.
- Confirmação de leitura por campanha com o rastreio global desligado (Política): a chave aparece desligada, com a
  explicação, e Lidos e Taxa de leitura mostram "—".
- "Entregue" (✓ verde) = enviado sem falha, até haver provedor com recibo.
- "Reenviar a quem não leu" = uma campanha nova, com confirmação, só com o rastreio ligado.
- "Excluir do relatório" só oculta (a trilha e a retenção de 24 meses ficam). Estrela e Arquivar são por usuário
  Master.
- Descadastrados: lista `comercial_campanhas` e `todos`; "Registrar pedido" grava em `comercial_campanhas`; "Excluir"
  (reativar) é um por vez, com confirmação e auditoria.
- "E-mails na fila" = o que está de fato na fila (envios enfileirados), não a soma do total das campanhas.
- Modo venda: "Cadastrar e-mail" manda a confirmação do endereço (dupla), como já existe; o relatório na seção
  Indicações mostra tudo, como no dc.
- Nome na prévia: o primeiro nome do primeiro destinatário, como no dc; "Dra. Ana" quando não há ninguém.
- Exportar destinatários (CSV): auditado, sem os e-mails já com hash (depois de 24 meses).
- "Cancelar envio" de recorrente = Encerrar (A3); o dc não tem "Pausar".

### Com o dono (não implementar a parte em dúvida; o controle fica desabilitado ou de fora)
- Teste para qualquer endereço (dc L668, "Encaminhar para um e-mail"): contraria a C4 (só o Master logado).
- Imagem no modo simples: o dc grava como `data:`, que o Gmail bloqueia; hospedar no servidor exige decidir tamanho,
  retenção e URL pública.
- "Limpar lista" dos Descadastrados (reativação em massa, risco LGPD).
- Datas comemorativas: o A2 (o dc liga todas; a decisão anterior era desligadas e só primários).
- Remetente: mostrar o real (`nao-responda@`) ou passar a enviar de `contato@` como no dc. Até a resposta, a tela
  mostra o remetente real.

### F3b (entregue)
- O painel "Filtrar destinatários" logo abaixo da grade de públicos (L260): fechado, o selo com quantos campos estão
  preenchidos (a busca conta) e "N de M com filtro" ou "Todos (M)"; aberto, a busca, os 7 atalhos (L664, cada um troca
  o filtro inteiro), os 8 selects (Plano pelo catálogo, D4) e "N de M atendem ao filtro · K sem essa informação no
  cadastro (ficam de fora)" ou "Enviando para todos da lista (M)", com "Limpar filtro". O `filtro` vai na contagem e na
  confirmação; quem conta é o servidor (F2). O modo venda (só busca e Estado) é da F3h.

### F3a (entregue)
- Cabeçalho "Central de envios · nova campanha" (L251) com o botão "Descadastrados (N)": dispara
  `mc:descadastrados-abrir` (o bloco é da F3c, do 0d) e lê o N do `mc:resumo`.
- "Salvar como meu modelo" no modelo fixo (L653): pede o nome (padrão "<nome> (meu)") e guarda a cópia em Meus
  modelos no MESMO modo (`SalvarComoMeuModeloUseCase`, ação `salvar_como`); o "Duplicar" continua convertendo em blocos.
- Selo (em maiúsculas ao digitar) e "Nome na prévia" entre Assunto e Título (L270); o nome digitado troca o `{nome}`
  como foi escrito, só na prévia (`nomeDaPrevia`). Os 10 emojis entram no fim do texto simples (L274). A imagem do
  mesmo trecho está com o dono.
- Linha de destinatários do L665: "N destinatário(s) · X descadastrado(s) retirado(s) · Y repetido(s) removido(s) · Z
  inválido(s) ignorado(s)" ou "· lista limpa"; o B1 entra no mesmo tom. Para contar os inválidos, a leitura do CSV e do
  Individual passou a manter os endereços errados (o CSV só os que têm "@"); quem filtra é o `PublicosDaCampanha`.
- Barra (L287-292): "Enviar" ou "Programar envio" com a seta do PROGRAMAR ENVIO (data, REPETIR, Concluir); ver, testar
  e baixar em ícones; a LGPD na dica do escudo; o relógio com a data e a repetição na linha de destinatários.
- "Como o destinatário vai receber" (L499-501): computador e celular (375 × 640), o remetente real do sistema.
- Ganchos para as outras partes: `window.McCampanha.modeloAtual()` (teste da F3f, 7d) e
  `window.McCampanha.extrasDoEnvio` (F3g, 0d: funções que devolvem um objeto para mesclar no "Enviar campanha").

### F3h: modo venda na seção Indicações (b5, combinado com a 5d em 21/10)

Desenho 05 VS 21.10.26: `secCentral = sec === 'com' || sec === 'ind'` e `venda = sec === 'ind'` (dc L627-645 e o
render). A mesma Central de envios aparece na seção "Indicações do BlueJus", abaixo do resumo e dos limites (#185/#190).
Escrita depois da F3a, em cima do código dela; a 5d revisa.

**Tela (modo `venda`):**
1. Modelos só `convite`, `vagas`, `news` e `novidades`, mais os do usuário; padrão `convite`. O seletor de modelo não
   aparece (`cmMostraModelo: sec !== 'ind'`) e os passos viram 1 PARA QUEM e 2 MENSAGEM (`cmNPara`/`cmNMsg`).
2. Públicos só `leads` (rótulo "Novos contatos (base)", descrição "Interessados ainda sem conta"), `csv` e `indiv`, em
   3 colunas (`cmPubCols`). Público fora da lista volta para `leads`.
3. Botão "Cadastrar e-mail" no cabeçalho do PARA QUEM (`cmLeadAdd`, borda tracejada do dc).
4. Filtro (F3b) só com a busca e o Estado: os selects e os atalhos somem.
5. Relatório: o mesmo da seção Comercial, mostrando tudo (sem origem na campanha; spec §10, sem migration).

**Contrato com o código da 5d:**
- Os ganchos globais da F3a (`window.McCampanha.modeloAtual()`, `window.McCampanha.extrasDoEnvio`) continuam
  apontando para a instância do modo `com` (a F3f e a F3g dependem deles); a instância `venda` não se registra neles.
- `_comercial.html.twig` recebe `modo` (padrão `'com'`) e põe `data-modo` na raiz.
- `comercial-campanha.js` deixa de ser singleton: roda por raiz (`[data-mc-campanha]`), cada uma com o seu token, as
  suas URLs e ids únicos, e carrega com `sec === 'com'` ou `sec === 'ind'` conforme a raiz. Uma tabela única
  `MODOS = {com: {...}, venda: {modelos, publicos, filtros, passos}}`.
- Não mexer no motor do filtro (`FiltroDoPublico`, `FiltroDeDestinatarios`, `AtributosDosDestinatarios`) nem no
  relatório da 9b.

**Servidor:**
- "Cadastrar e-mail": `CadastrarContatoDoMasterUseCase` (Comercial, só Master), que chama
  `ConfirmacaoDeEnderecoInterface::pedir(...)` para a lista `comercial_campanhas` (`ComposicaoDaCampanha::LISTA`): a
  confirmação dupla da 1a, com os limitadores (e-mail, IP, global). Quem se descadastrou da lista NÃO recebe o pedido
  por aqui, nem depois de 30 dias (LGPD, coordenação 21/10): conta à parte na resposta, sem o endereço.
  Origem `cadastro-manual-master` (rótulo "pela equipe do BlueJus, em um cadastro manual" em `RotulosDoConsentimento`).
  Vários e-mails separados por vírgula, espaço ou ponto e vírgula; a resposta diz quantos pedidos saíram, quantos já
  existiam, quantos foram barrados e quantos são descadastrados (`descadastrados`), sem travessão. O texto do dc ("Só cadastre quem autorizou receber (LGPD)") aparece no campo.
- `modo: 'venda'` no pedido de contagem e de envio, validado no `PedidoDePublico` e no `PedidoDeCampanha`: público fora
  de `leads`/`csv`/`indiv` ou modelo fora dos quatro (e dos do usuário) é recusado (422). É coerência, não barreira:
  `modo` e o `cu<id>` são rótulos que o próprio cliente manda (o Master pode enviar pela seção Comercial de qualquer
  forma). No modo venda, o filtro aceita só busca e Estado (o resto recusado).

**Testes:** a seção ind tem a Central como filho direto (combinador), com `data-modo="venda"`; os dois modos na mesma
página não se confundem (token, URLs, ids); a recusa no servidor (público, modelo, filtro); o UseCase do cadastro
(confirmação pedida, limitadores, origem, descadastrado); 403 para quem não é Master; CSRF.

**Estado (21/10, implementado na branch `comercial-f3h`, a revisar pela 5d):**
- Feito: `_comercial.html.twig` com `modo` (raiz da venda `.mc.mc-venda[data-mc-venda][data-modo="venda"]`, sem
  `data-mc`, ids com prefixo `mc-venda-`), incluído pelo `_indicacoes.html.twig` como filho direto do `[data-mi]`;
  `comercial-campanha.js` por raiz, com `MODOS`; `PedidoDePublico::modo` e as recusas da venda no `PedidoDePublico` e
  no `PedidoDeCampanha`; `CadastrarContatoDoMasterUseCase` em `POST /master/comercial/contatos` (origem
  `cadastro-manual-master`, rótulos da origem e da lista `comercial_campanhas` no `RotulosDoConsentimento`).
- Limite por IP do cadastro: `comercial_contatos_master` (20 por hora) no `framework.yaml`, 429 com mensagem.
- Cada raiz expõe `raiz.mcModeloAtual` (o modelo da própria instância; o teste da F3f lê o da instância do clique).
- Depois do #223, em PRs próprios (coordenação, 21/10): o Relatório na venda (9b, include no `_comercial.html.twig`
  dentro da raiz) e o botão "Descadastrados (N)" na venda (0d, o mesmo bloco movido para antes da Central do clique;
  `raiz.mcExtrasDoEnvio` para a confirmação de leitura).
- Coordenação, 21/10 (o dono pode rever): o descadastrado não recebe o pedido pelo cadastro do Master; no e-mail do
  cadastro manual, a segunda frase é "Se não quiser receber, é só ignorar este e-mail." (as outras origens seguem com
  "Se não foi você, ignore este e-mail.").

## 11. F3.3 (a): quem autorizou vagas recebe o modelo de vagas (dono, 21/10)

Decisões do dono (21/10): F3.1 (a), `COMERCIAL_CAMPANHAS_LIGADAS` nasce desligada (como já está); F3.3, as duas
saídas: (a) já, (b) a caixa "aceito novidades" quando houver desenho; F3.9 (b), o rótulo da Base de contatos passa a
"Contatos que autorizaram campanhas". Risco MÉDIO (envio em massa, consentimento). Migration `Version20261020149600`.

### Regra
- Público `leads` ("Base de contatos"):
  - com o modelo `vagas` ("Ofertas · vagas abertas", dc L506): a união sem repetidos dos confirmados de
    `comercial_campanhas` e de `vagas_alerta`, nessa ordem;
  - com qualquer outro modelo, inclusive cópias `cu<id>`: só os confirmados de `comercial_campanhas`, como hoje.
- Cada destinatário leva a lista pela qual entrou: quem está nas duas sai por `comercial_campanhas`, e quem só autorizou
  vagas sai por `vagas_alerta`. Essa lista decide o link de descadastro, o List-Unsubscribe e a checagem do Carteiro.
- No modelo `vagas`, quem se descadastrou de QUALQUER uma das duas listas fica de fora, para que o "não quero mais" de
  uma não volte pela outra.
- Âncora no servidor (decisão técnica): com a chave `vagas`, a campanha só é aceita no modo `simples` com o botão
  apontando para as vagas (`link = vagas`). O texto continua editável. Fora disso, a campanha é recusada com uma
  mensagem clara. Isso não é barreira contra o Master, porque a rota é só dele. Serve para o consentimento de vagas
  não sair num e-mail que não leva às vagas.
- A lista de cada destino fica gravada na fila (`comercial_campanha_destino.lista`, padrão `comercial_campanhas`). A
  recorrente e a agendada resolvem o público com o `modelo_chave` gravado na campanha.
- A contagem recebe o modelo: a tela manda `modelo` e reconta ao trocar de modelo.
- Ficam como estão: o B1 do CSV e do Individual (só `comercial_campanhas`) e o KPI "Base de contatos" do painel.

### Confirmações do dono (21/10) sobre o §8.8
- Item 1: confirmado (a chave nasce desligada). Item 3: revisto por esta seção. Item 9: o rótulo muda para "Contatos que
  autorizaram campanhas". Item 12: confirmado (a limpeza dos sinais do nome do CSV fica). Item 13: confirmado (a
  recorrente com até 24 h de atraso sai uma vez; com mais, pula aquela vez).

### Decisões técnicas da implementação
- A âncora vale na confirmação e no envio de teste; a prévia não recusa, porque roda a cada edição. A URL completa das
  vagas não substitui o `link = vagas`.
- No modelo `vagas`, o descadastro das duas listas vale para qualquer público, não só para a Base de contatos.

### Para o dono (nota, sem bloquear)
- Sair pelo link de uma campanha de vagas também desliga os alertas de vagas, porque é o mesmo consentimento.
- Uma campanha recorrente de vagas cresce sozinha conforme a lista `vagas_alerta` cresce.

## 12. F3i: imagem na campanha do modo simples (dono, 21/10 à noite; o dono pode rever)

Decisão do dono, F3i (a): a imagem fica guardada no BlueJus com endereço público, até 1 MB, só JPG ou PNG, e é apagada
1 ano depois da campanha. Desenho: dc 05 VS 21.10.26, L274 (controle), L596 (no e-mail) e L659 (JS). Risco MÉDIO
(arquivo público, envio em massa). Uma migration (tabela `comercial_imagem`).

### O que o desenho mostra (entra inteiro)
- No modo simples, abaixo do TEXTO e na linha do "Inserir:", o botão "Adicionar imagem", que vira "Trocar imagem"
  quando já há uma, e o "Remover". Uma imagem por e-mail.
- No e-mail, a imagem vai logo abaixo do cabeçalho azul e antes dos parágrafos, com 540 px de largura e cantos de 6 px.
  Com imagem, o espaço interno do texto cai de 28 para 18 px.
- No navegador, a imagem é reduzida a no máximo 1080 px e convertida em JPEG (qualidade 0,85), como no desenho.

### Regras
- **Envio:** `POST /master/comercial/imagens`, só para o Master, com o CSRF da seção. O tipo é conferido pelo conteúdo,
  só JPEG ou PNG, nunca pela extensão. O servidor recusa arquivo acima de 1 MB e dimensão acima de 4000 px. A imagem é
  regravada no servidor para tirar EXIF e localização. O nome é aleatório e a URL usa um token próprio.
- **Endereço público:** `GET /e/i/{token}.{jpg|png}`, sem login. Leva Content-Type exato, `nosniff`, cache de um dia e
  `noindex`. Token inexistente ou vencido dá 404.
- **Na campanha e no modelo:** a mensagem guarda só o token (`imagem`), nunca URL livre nem `data:`. Token desconhecido
  ou vencido é recusado com "A imagem expirou; envie de novo."
- **Alt:** o título do e-mail. O desenho usa um alt mas não tem campo para preenchê-lo; pela convenção, nenhum campo novo
  entra na tela.
- **Validade:**
  - campanha: 1 ano a partir do último envio, o que inclui cada execução da recorrente;
  - modelo salvo: 1 ano a partir do último salvamento;
  - imagem enviada e nunca usada: 30 dias.
  - A purga entra no `app:purgar-dados-expirados`, e o arquivo só é apagado depois do COMMIT.
- **Escopo:** só o modo simples, como no desenho. O bloco "Imagem" do modo blocos continua com endereço `https://`.
- **Efeito esperado:** um e-mail antigo perde a imagem depois de 1 ano.

### Decisões técnicas (o dono pode rever)
Contagem de 1 ano na recorrente pelo último envio; 30 dias para a imagem sem uso; o alt é o título; o JPEG do desenho é
mantido.

## 13. F4: datas comemorativas (dono, 21/10 à noite; o dono pode rever)

Decisão do dono, F4 (a), como o desenho: as datas nascem LIGADAS e vão para todos. A chave geral das campanhas
(`COMERCIAL_CAMPANHAS_LIGADAS`, F3.1) nasce desligada e segura tudo: nada sai em massa até o Master ligá-la. Isto
substitui a A2. Desenho: dc 05 VS 21.10.26, L335-339 (tela), L614-618 (as 17 datas), L626-627 (modelo de cada data) e
L678-682 (estado). Risco MÉDIO. Migration `Version20261020149800`.

### O que o desenho mostra (entra inteiro)
- A seção "Datas comemorativas", com o sub "mensagens automáticas nas datas nacionais · N ativas".
- Uma linha por data, com:
  - o interruptor;
  - o selo com dia da semana, dd/mm e ícone;
  - o nome e "em N dias · envio dd/mm às 9h", em laranja com 7 dias ou menos;
  - a antecedência: No dia, 1 dia antes ou 3 dias antes;
  - o "Para quem": Todos, Primários ou Base de contatos;
  - o "Ver mensagem", com o aviso "Prévia da mensagem de X. Use Duplicar para personalizar o texto.".
- As 17 datas:
  - fixas: Ano Novo, Mulher, Tiradentes, Trabalho, Namorados, Advogado, Independência, Cliente, Crianças, Finados,
    Consciência Negra, Natal e Réveillon;
  - móveis: Carnaval (Páscoa menos 47), Páscoa, Mães (2º domingo de maio) e Pais (2º domingo de agosto).
- Os textos são literais do dc. Padrão de cada data: ligada, no dia, para Todos.
- O modelo de cada data:
  - assunto: título + " · BlueJus";
  - selo: o nome em maiúsculas;
  - título: o título sem "!" + ", {nome}!";
  - blocos: o texto mais o botão "Abrir o BlueJus".
  As datas também entram na grade de modelos (dc L627), para o "Duplicar".

### Regras
- **Estado no servidor:** a tabela `comercial_data_comemorativa` (chave, ligada, antecedência 0/1/3, público
  `todos|prim|leads`, `alterada_por` e `alterada_em`, auditável) nasce semeada com as 17 ligadas, no dia, para Todos.
  O `localStorage` do dc vira servidor.
- **Disparo:** o passo `datas` no `VarrerAutomacoesHandler`. Para cada data ligada cujo dia de envio é hoje e que já
  passou das 9h (America/Sao_Paulo), cria uma campanha única do sistema (`criada_por` nulo, `modelo_chave =
  data-<id>`, o público da data). Ela herda o expansor: vazão, teto, B1, descadastro, Relatório e Cancelar.
- **Uma vez por ano:** `chave_idempotencia = data:<id>:<ano da data>`. O ano é o da data, então o Ano Novo "1 dia
  antes" sai em 31/12 com o ano seguinte.
- **Janela:** até 23:59 do dia de envio. Ligar a data nesse dia, depois das 9h, ainda manda. Passado o dia, nada é
  recuperado.
- **Chave geral desligada:** o passo não cria nada e não represa nada.
- **Público:**
  - Todos: primários mais secundários, ativos;
  - Primários: só os primários;
  - Base de contatos: os confirmados de `comercial_campanhas`.
  A lista de envio e de descadastro é `comercial_campanhas`. A base legal de Todos e Primários é o legítimo interesse,
  como o item 8 da §8.8, que esta decisão confirma na prática.
- **Texto da tela:** pelo catálogo (`comercial.datas.*` e `comercial.js.datas_*`). Os nomes e os textos das datas
  ficam no PHP, como os outros modelos fixos.

### Para o dono (nota, sem bloquear)
- Ligar a chave geral solta a próxima data para todos, sem confirmação nova.
- Vazão: com o teto de 1.600 campanhas em 24 h corridas, uma base maior que isso recebe o Natal em dois dias.
- O Réveillon e o Ano Novo saem em dias seguidos.
- As campanhas das datas aparecem no Relatório e no KPI "Campanhas". Um selo "Data comemorativa" no Relatório fica
  para depois.
