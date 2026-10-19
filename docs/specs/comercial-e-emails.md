# Comercial e e-mails (painel do Master)

Desenho: `docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html`, seção "Comercial e e-mails" (template
L222-287, script L482-588). "dc L.n" abaixo são linhas desse arquivo. Regra do dono: tudo do desenho entra; onde o
desenho mostra diferente do sistema, o desenho manda; desviar exige perguntar antes.

## Estado da frente (atualizado em 19/10/2026; reler antes de continuar se a sessão for compactada)

- **Sessão:** 5d. **Spec:** branch `comercial-emails` (worktree `.claude/worktrees/comercial-emails`), do master.
- **Versão:** 2 (19/10). A v1 não foi aprovada (B1, I1 a I11, M1 a M7); a v2 trata todos. Precisa de nova revisão.
- **Risco:** MÉDIO no geral (envio em massa, LGPD). Os ganchos em Auth (§3.5) tocam identidade User/Tenant: rigor
  ALTO (revisão e re-revisão).
- **Fatias (um PR cada, na fila de migrations):**
  - **1a.** Infraestrutura de envio, registro, confirmação de endereço, rastreio (desligado até a Política), e as
    automações do ciclo da conta (`cad`, `lemb`, `val`, `conv`, `dica`). Empilhada sobre o **#87** da 0d
    (`push-avisar-quando-chegar`, que traz `email_descadastro`).
  - **1b.** As automações de espaço (`e90`, `e100`, `ped`). Empilhada sobre `contribua-c2` (#74, que contém o #64), da
    f6, depois da 1a (ou sobre a 1a, se a 1a entrar antes).
  - **2.** Campanhas: modelos, editor de blocos, públicos, agendamento, teste.
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
  - Vazão 20/min e 1.500/dia, configurável, até o dono responder.
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
  avisar o dono, é deploy), e três UseCases de Auth mais o `CadastroMailer` (uma linha cada).
- **Pendências:** nova revisão da v2; respostas do dono; o push do `reativar` e `listar` no #87 (a 0d avisa o hash).
- **Próximo passo:** revisão da v2; aprovada, abrir a frente da 1a sobre `push-avisar-quando-chegar` e implementar.

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
| `origem` | `automacao:<chave>`, `campanha:<id>`, `teste`, `consumidor:<nome>` (`[a-z0-9_:]{3,60}`) |
| `replyTo` | opcional; a 0d manda o responsável |
| `cc` | lista opcional; a 0d manda a Controladoria |
| `rastrear` | bool; só vale em `lista` e só com o rastreio ligado (§3.8) |

Em uma transação só (insert do registro e da mensagem no transporte doctrine, mesma conexão; sem registro órfão):
1. `lista`: `estaDescadastrado(escopo, para, lista)`. Descadastrado (inclusive em `todos`): grava o envio como
   `bloqueado_descadastro` e devolve sem despachar.
2. Grava o `EnvioDeEmail` como `enfileirado` e despacha `EnviarEmail(envioId)` no transporte **`comercial`** (§3.6).

`EnviarEmailHandler`:
1. **Reivindica** o envio: `UPDATE comercial_envio SET situacao = 'enviando', tentativa_em = :agora WHERE id = :id
   AND situacao = 'enfileirado'`. Zero linhas: outro worker pegou, ou já saiu; para. (Um `enviando` com `tentativa_em`
   de mais de 15 min volta a `enfileirado` na varredura, para não travar depois de um worker morto.)
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
`CadastroMailer` ganha uma linha que registra o envio (§3.5, `cad`).

### 3.2 Registro dos envios

`comercial_envio` (`EnvioDeEmail`): `id`, `token` (32 hex aleatórios, público), `tenant_id` (nullable), `destinatario`,
`nome`, `assunto`, `tipo`, `lista`, `origem`, `situacao` (`enfileirado`, `enviando`, `enviado`, `falhou`,
`bloqueado_descadastro`, `simulado`), `erro`, `criado_em`, `tentativa_em`, `enviado_em`, `primeira_abertura_em`,
`aberturas`, `primeiro_clique_em`, `cliques`. Índices: `token` UNIQUE, `(origem, criado_em)`, `(situacao,
enviado_em)`, `(destinatario)`.

**Retenção (P4, com o dono):** proposta de 24 meses; depois, `destinatario` vira hash SHA-256 com sal da aplicação,
`nome` e `erro` viram null (os números por campanha continuam). Roda no `app:purgar-dados-expirados`. O Anexo II da
Política precisa da categoria (vai ao rascunho da f6 junto com o rastreio).

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

**Token:** 32 bytes aleatórios, só o hash no banco, uso único, 7 dias. Um `pedir` novo troca o token (o antigo deixa de
valer). Vencido ou já usado: página "Este link venceu ou já foi usado. Peça a confirmação de novo." sem revelar o
endereço.

**À prova de robô (I9):** o link abre `GET /e/confirmar/{token}`, que só mostra a página com o botão **"Confirmar"**
(nada é gravado no GET; os leitores de link do Outlook e afins não confirmam sozinhos). O `POST` (CSRF, mesmo token)
grava `confirmado`, com IP e agente.

**Contra abuso (I9):** limitadores em `framework.yaml`, trecho próprio: por e-mail e lista, 1 pedido a cada 24 h; por
IP, 10 por hora (efetivo quando o #77 entregar o IP real; até lá, vale o teto global); teto global de 200 pedidos por
hora (protege a vazão das boas-vindas). Os e-mails de confirmação são `transacional`, mas contam no teto diário (§3.6).

**Reinscrição depois de descadastro:** confirmar de novo é consentimento novo: o POST da confirmação chama
`reativar(escopo, email, lista, 'nova_confirmacao')` da 0d, que libera só aquela lista (com `todos` ativo, fica a
exceção registrada só para ela).

**Retenção:** pendência não confirmada é apagada (estado e o fato `pedido`) 30 dias depois de vencer; os fatos de quem
confirmou ficam 5 anos depois do término do tratamento, isto é, do descadastro daquela lista no registro da 0d
(Política L379); enquanto a pessoa segue inscrita, ficam.

### 3.4 As automações (modelo de dados e regras comuns)

Enum `App\Comercial\Automacao\Automacao` com as 8 chaves, título, descrição, modelo, opções de atraso e o padrão do dc.
`comercial_automacao` guarda só o que o Master mudou: `chave` PK, `ligada`, `atraso`, `ligada_em`, `alterada_por`,
`alterada_em`.

**Nada dispara para o passado (I8):** cada gatilho por evento só reage a evento novo. Cada gatilho por varredura só
considera fatos **posteriores a `ligada_em` daquela automação** (para as que nascem ligadas, o `ligada_em` é o momento
da primeira execução depois do deploy). Ligar uma automação meses depois não alcança contas antigas.

**Ciclo da conta:** `comercial_ciclo_conta` (`user_id`, `tenant_id`, `confirmado_em`, `boas_vindas_em`, `dica_em`),
gravado pelo ouvinte de `CadastroConfirmado`. É a fonte do `dica` (o `CadastroPendente` é purgado) e a deduplicação de
`val` e `dica` (no máximo um de cada por conta).

**Ouvintes nunca derrubam o fluxo (I3):** todo ouvinte de evento desta frente captura qualquer exceção, registra no
log sem dado pessoal (só ids e a chave da automação) e segue. O usuário nunca recebe erro por causa de um e-mail.

**Varredura (M3):** mensagem `VarrerAutomacoes`, no transporte `comercial`, que se reagenda a cada 15 min
(`DelayStamp`). Controle `comercial_varredura` (`id` fixo 1, `proxima_em`, `executada_em`): o handler reivindica com
`UPDATE ... SET executada_em = :agora, proxima_em = :agora + 15 min WHERE id = 1 AND proxima_em <= :agora`; zero
linhas, outra cadeia já passou e esta morre. O comando `app:comercial:varredura` (idempotente) semeia a cadeia e
pode rodar a qualquer momento (no deploy ou à mão); se a cadeia morrer em `failed`, rodar o comando de novo resolve.

### 3.5 Os gatilhos da 1a

| Chave | Gatilho | Quem recebe | Tipo |
|---|---|---|---|
| `cad` | O `CadastroMailer` continua mandando (P5). Ganha uma linha: depois do `send`, registra o envio em `comercial_envio` com `origem = automacao:cad` e `situacao = enviado` (para os "Últimos disparos" mostrarem "Conta aberta", I11) | quem se cadastrou | transacional |
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
- `AceitarConvitePlataformaUseCase`: `ConvitePlataformaAceito(userId)`; **nenhuma automação ouve na 1a** (pergunta Q3).
- O dispatcher é o `EventDispatcherInterface` do Symfony (síncrono); o ouvinte só enfileira pelo Carteiro, protegido
  como em §3.4. A prova de "depois do commit" no teste: o ouvinte de teste grava o nível de transação da conexão no
  momento do evento, e o teste afirma que é o nível de base do DAMA (1), não o do `wrapInTransaction` (2).

**`conv` e convite de primário (Q4):** o dc diz "(secundário)". Proposta: o `conv` manda as boas-vindas a quem entrou
por convite, primário ou secundário; o evento leva `comoPrimario` para o texto ou a regra mudarem sem mexer em Auth.

**Código no e-mail de confirmação (Q5):** o dc descreve o `cad` como "Envia o link e o código" e o modelo tem um
código de 6 dígitos. O sistema só tem o link. Acrescentar código é mudança no fluxo de cadastro (identidade, a tela de
verificação teria de aceitar o código): **não entra na 1a**; vai ao dono.

**Na tela (parcial do #56, `_comercial.html.twig`):** só o bloco "Automações" na 1a (os outros blocos nas fatias
seguintes), com:
- interruptor (POST `master_comercial_automacao`, JSON, `X-CSRF-Token`, auditado; o `cad` aparece travado em
  "ligada" com a dica "Obrigatório: sem ele ninguém consegue criar conta" se o dono aprovar P2; até lá, segue o dc e
  desligar o `cad` só para de **registrar**, porque o `CadastroMailer` continua mandando: a dica diz isso);
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
  a IA (M4). O worker de produção passa a consumir `async comercial` (`docker-compose.prod.yml`: muda o comando do
  `jusprime_worker_prod`; é deploy, o dono aplica).
- **Vazão sem cache (I6):** a fonte é o próprio `comercial_envio`, que sobrevive a deploy e a `cache:clear`:
  - por minuto: `COUNT(*) WHERE situacao = 'enviado' AND enviado_em > agora - 60 s` < `COMERCIAL_ENVIOS_POR_MINUTO`
    (padrão 20);
  - por dia: `COUNT(*) WHERE situacao = 'enviado' AND enviado_em >= hoje 00:00` < `COMERCIAL_TETO_DIARIO` (padrão
    1.500).
  - Sem vaga no minuto: `DelayStamp` de 60 s. Sem vaga no dia: `DelayStamp` até 00:05 do dia seguinte.
  - **Prioridade:** `transacional` só respeita o limite por minuto (as boas-vindas nunca esperam o dia seguinte por
    causa de campanha); `lista` respeita os dois, e o teto diário conta tudo o que saiu.
  - Os e-mails antigos síncronos não passam pela conta nem pelo limite (P5); o padrão conservador deixa folga para
    eles. Se o provedor for Gmail gratuito, o teto de 1.500 é alto demais (Q1).

### 3.7 Layout base e rodapé

`templates/email/comercial/_layout.html.twig`: tabelas e estilo inline, cabeçalho do dc (degradê azul, logo, selo,
título), corpo, versão texto gerada. **Rodapé sempre (B3), não editável, conforme o escopo (I5):**
- **plataforma:** "BlueJus Gestão e Tecnologia LTDA · CNPJ 66.803.163/0001-10 · Brasília/DF" e a frase do dc em
  `lista`; em `transacional`, "Você recebeu este e-mail por causa da sua conta no BlueJus." (a frase do dc seria falsa
  para quem ainda não consentiu, M1). No e-mail de confirmação de endereço (§3.3): "Este endereço foi informado em
  <origem>. Se não foi você, ignore este e-mail.";
- **escritório (`escopo` = Tenant):** "Enviado por <nome do escritório> por meio do BlueJus" (o controlador ali é o
  escritório);
- **link "Não quero mais receber" em todo e-mail, como no dc (M1):** em `lista`, o `gerarLink` da própria lista; em
  `transacional`, o `gerarLink(escopo, email, 'todos')`, que tira de toda comunicação comercial daquele remetente
  (os transacionais continuam saindo, porque não consultam o descadastro);
- **link da Política de Privacidade (B4).**

### 3.8 Rastreio (desligado até a Política)

- **Ligado só quando a Política citar o rastreio (I10):** `COMERCIAL_RASTREIO_LIGADO` (padrão `false`) **e**
  `PoliticaPrivacidadeVigente::VERSAO` igual ou posterior à versão que citar o rastreio (constante
  `VERSAO_QUE_CITA_RASTREIO`, preenchida quando a f6 publicar). Desligado, `rastrear` é ignorado.
- Abertura: `<img>` 1x1 para `GET /e/a/{token}.gif`; grava a primeira abertura e soma. Responde o mesmo GIF para token
  inválido.
- Clique: cada `href` http(s), exceto o de descadastro e o da Política, vira `GET /e/c/{token}?u=<url>&s=<assinatura>`
  (UriSigner sobre `token` e `u`): grava e responde 302 para `u`; assinatura errada responde 404, sem redirecionar.
- Limitador por IP nas duas rotas (efetivo com o #77; até lá, só o teto global de 600 por minuto).

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

Deduplicação em `comercial_aviso_espaco` (`tenant_id`, `chave`, `dia`, `plano`), UNIQUE (insert que falha = já
mandou). Todos `transacional` (aviso do serviço contratado), escopo plataforma. Ouvintes protegidos como em §3.4.

**`e100` raro com o bloqueio ligado (I2):** com o bloqueio ligado, o envio que estoura é recusado antes do evento, e a
faixa `Bloqueio` só é atingida quando o uso fecha exatamente no teto. Pedido à f6 (pergunta Q6): um evento
`EnvioRecusadoPorEspaco(tenantId)` na primeira recusa, que dispara o `e100`.

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

**1b.** `e90` cruzando 80, 85 e 90 (só o escolhido); deduplicação por dia, inclusive descendo e subindo de novo; `e100`
com e sem `bloqueioAtivo`; `ped` pelo primário e pelo Master; destinatários = primários ativos.

## 6. O que o dono olha na tela

**1a:** no `/master#sec=com`, o bloco Automações: ligar e desligar, atraso, "Simular novo cadastro" (aparece em
"Últimos disparos", nada é enviado), e um cadastro de teste: chega a confirmação, aparece "Conta aberta" nos "Últimos
disparos", e depois de confirmar chegam as boas-vindas. **1b:** um escritório de teste perto do limite.

## 7. Perguntas (v2)

Ao dono, pelo coordenador (do pacote anterior): **P1** provedor de envio; **P2** "Ao abrir uma conta" travado em
ligado; **P4** retenção de 24 meses. Novas:
- **Q1.** A conta do `MAILER_DSN` de produção é Gmail gratuito, Workspace ou Hostinger? Define se 1.500/dia é seguro.
- **Q2.** Guardar o cadastro pendente vencido por mais 72 h (com nome, OAB e hash da senha) para o lembrete de 48 e 72 h
  funcionar. Alternativa: o lembrete só oferece 24 h (desvio do dc).
- **Q3.** Quem entra por convite de plataforma (`AceitarConvitePlataformaUseCase`, conta sem escritório) recebe
  boas-vindas? Proposta: sim, as do `val`.
- **Q4.** O `conv` vale também para convite de primário? Proposta: sim (o evento diz qual foi).
- **Q5.** Código de 6 dígitos no e-mail de confirmação, como no dc: mudança no fluxo de cadastro (ALTO), fora da 1a.
- **Q6.** (à f6) Evento na primeira recusa por espaço, para o `e100` não depender de fechar exatamente no teto.
- **Q7.** "Últimos disparos" com o destinatário mascarado (`a***@dominio.com`): o dc mostra o endereço inteiro.
