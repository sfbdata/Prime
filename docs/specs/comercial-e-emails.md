# Comercial e e-mails (painel do Master)

Desenho: `docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html`, seção "Comercial e e-mails" (template
L222-287, script L482-588). "dc L.n" abaixo são linhas desse arquivo. Regra do dono: tudo do desenho entra.

## Estado da frente (atualizado em 19/10/2026; reler antes de continuar se a sessão for compactada)

- **Sessão:** 5d. **Branch da spec e da fatia 1:** `comercial-emails` (worktree `.claude/worktrees/comercial-emails`),
  cortada do master (e59ac064).
- **Risco:** MÉDIO no geral (envio em massa, LGPD); os ganchos nos casos de uso de cadastro e convite (§3.4) tocam
  identidade User/Tenant e seguem o rigor ALTO (revisão e re-revisão).
- **Fatias (um PR cada, na fila de migrations):**
  1. Infraestrutura de envio, rastreio, confirmação de endereço e as 8 automações. **Em spec.**
  2. Campanhas: modelos, editor de blocos, públicos, agendamento, teste.
  3. Monitoramento (números e CSV por campanha) e descadastrados.
  4. Indicações (tabela da 7d) e datas comemorativas (CalendarioDoSistema).
- **Fila de migrations (coordenador, 19/10):** a da fatia 1 entra logo DEPOIS da P4b da 0d
  (`push-avisar-quando-chegar`, que cria `email_descadastro`); as das fatias 2 a 4 em seguida, uma por vez. Renomear o
  timestamp na vez.
- **Decisões (coordenador, 19/10, por delegação):** A1 transacionais nascem ligadas e disparam só pelo evento do
  próprio usuário; A4 o convite de indicação é da 7d e consulta o descadastro; B2 link de descadastro assinado da 0d,
  escopo plataforma; B3 rodapé legal SEMPRE, também no HTML importado; B4 link da Política de Privacidade no rodapé;
  C2 aberturas (pixel) e cliques (link rastreado) entram, em números e CSV por campanha, sem gráfico, e a Política de
  Privacidade precisa citar o rastreio (rascunho da f6, com o advogado); C4 "Enviar teste" vai ao Master logado.
- **Com o dono (pacote do coordenador):** A2 datas comemorativas desligadas e só para primários; A3 Pausar e Encerrar
  na recorrente; B1 CSV e Individual só para quem consentiu; reply-to; C3 limite de envio. Novas (§6): P1 a P5.
- **Contratos com outras sessões:**
  - 0d: `App\Shared\Email\Descadastro\DescadastroDeEmailInterface` com `?Tenant` (null = plataforma),
    `estaDescadastrado`, `descadastrar`, `gerarLink`; `ASSUNTO_TODOS = 'todos'`; assunto `[a-z_]{3,40}`;
    List-Unsubscribe e List-Unsubscribe-Post. Assinatura final quando o PR dela abrir. Falta combinar: leitura
    paginada do escopo plataforma para a lista de Descadastrados (fatia 3) e o "Reativar" (LGPD).
  - a7: alertas de vagas (`trabalho_externo_interessado`, #84) só recebem depois do double opt-in desta frente (§3.3)
    e consultando o descadastro (`vagas_alerta`).
  - 0d (Push) e a7 (campanha de vagas): consomem o `Carteiro` desta frente (§3.1).
  - f6 (#64, #74): ouvir `EspacoAtingiuFaixa`; pedir a f6 um evento `EspacoFoiPedido` no `PedirEspacoUseCase` (§3.4).
  - 4b (#54): datas do `CalendarioDoSistema` (fatia 4).
  - 7d: UseCase público de leitura das indicações (fatia 4), combinar antes da migration dela.
  - #56 (b5): parcial `master/_comercial.html.twig`, evento `mp:secao`, CSP do `/master` com 'self'.
- **Pendências:** revisão desta spec; respostas do dono; PR da 0d (dependência dura da fatia 1); evento da f6.
- **Próximo passo:** revisão da spec (feature-review-agent); depois, a fatia 1 empilhada sobre a branch da 0d quando o
  PR dela abrir.

## 1. O que já existe (levantamento de 19/10)

- **Envio:** Symfony Mailer, síncrono, dentro da requisição (`SendEmailMessage` não está roteado no Messenger).
  `IdentidadeDeEmail::aplicar()` põe remetente (`MAILER_FROM`, nao-responda@bluejus.com.br), reply-to
  (`MAILER_REPLY_TO`) e o prefixo `[BlueJus]`. Mailers: `CadastroMailer`, `ConviteMailer`, `RedefinicaoSenhaMailer`,
  `TrabalhoExternoMailer`, legado `InvitationService`. Templates soltos em `templates/email/`, sem layout base.
- **Produção envia pelo SMTP do Gmail** (`.env.prod`: `smtp.gmail.com:587`), e não pela Hostinger: teto diário do
  Gmail, sem aviso de entrega nem devolução. Ver P1.
- **Messenger:** transporte `async` (doctrine), 3 tentativas, `failed` sem consumidor; worker de produção
  `jusprime_worker_prod` (`messenger:consume async`).
- **Cadastro:** `/cadastro` grava `CadastroPendente` (link de 24 h) e o `CadastroMailer` manda a confirmação;
  `ConfirmarCadastroUseCase` cria User e Tenant. **Não há e-mail de boas-vindas** nem evento de domínio de cadastro,
  confirmação ou convite aceito.
- **LGPD:** Política de Privacidade (`PoliticaPrivacidadeVigente`) já prevê comunicação comercial a clientes por
  legítimo interesse com descadastro, a não clientes por consentimento, e que cookies não essenciais exigem
  consentimento. Não há campo de consentimento de marketing em User, Tenant ou perfil.
- **Agendamento:** sem Scheduler nem cron no código; o #76 usa uma mensagem que se reagenda (`DelayStamp`), semeada
  por comando. É o padrão desta frente.
- **Master:** `ROLE_SUPER_ADMIN`; rotas `master_*`; JSON com `X-CSRF-Token`; CSP do painel aceita script 'self'.

## 2. O que o desenho mostra (resumo; inventário completo nas linhas citadas)

Página corrida, nesta ordem: 4 KPIs (Campanhas, E-mails na fila, Base de contatos "inscritos com autorização (LGPD)",
Descadastrados, que abre a lista) (L223); Descadastrados (L224-228); Nova campanha (L229-260); Automações (L262-268);
Indicações (L269-275); Datas comemorativas (L276-281); Monitoramento (L282-286). Rodapé de todo e-mail: razão social,
CNPJ, cidade, "Você recebeu este e-mail porque se cadastrou no BlueJus ou autorizou o envio de novidades. Não quero
mais receber." (L501). Layout: cabeçalho em degradê azul com logo, selo e título; corpo; rodapé (L496-502).

**Automações (L262-268, regras L561-566):** 8 linhas com interruptor, título, descrição, atraso e o botão do modelo.

| Chave | Título | Modelo | Atraso | Estado no dc |
|---|---|---|---|---|
| `cad` | Ao abrir uma conta | Confirmar e-mail | imediato | ligada |
| `lemb` | E-mail não confirmado | Confirmar e-mail | 24h (24, 48, 72h) | ligada |
| `val` | Ao confirmar o e-mail | Boas-vindas | imediato | ligada |
| `conv` | Convite aceito (secundário) | Boas-vindas | imediato | ligada |
| `dica` | 7 dias depois do cadastro | Primeiros passos | 7 dias (3, 7, 15) | **desligada** |
| `e90` | Conta chegou a 90% do espaço | Espaço em 90% | 90% (80, 85, 90) | ligada |
| `e100` | Espaço esgotado (100%) | Espaço esgotado | | ligada |
| `ped` | Pedido de mais espaço | Pedido de espaço recebido | imediato | ligada |

"Simular novo cadastro" (L263, L566) e "Últimos disparos" (L267, L565: os 6 últimos, com gatilho, assunto,
destinatário e data; vazio "Nenhum disparo ainda.").

## 3. Fatia 1: infraestrutura e automações

Domínio novo `app/src/Comercial/` (Controller, UseCase, Entity, Repository, DTO, Service, Message, MessageHandler).

### 3.1 O Carteiro: a porta única de envio (contrato para 0d e a7)

`App\Comercial\Envio\CarteiroInterface::enviar(MensagemDeEmail $m): ResultadoDoEnvio`.

`MensagemDeEmail` (DTO imutável): `para` (e-mail, normalizado em minúsculas e sem espaços), `nome` (opcional),
`assunto` (do e-mail), `corpoHtml` (o miolo, já renderizado pelo chamador ou por um modelo desta frente), `tipo`
(`transacional` ou `lista`), `lista` (assunto do descadastro, `[a-z_]{3,40}`, obrigatório quando `tipo = lista`;
proibido em `transacional`), `escopo` (`?Tenant`, null = plataforma), `origem` (`automacao:<chave>`,
`campanha:<id>`, `teste`, `consumidor:<nome>`), `rastrear` (bool; só vale para `lista`).

O que o Carteiro faz, nesta ordem:
1. Valida o DTO (e-mail com `filter_var`; `lista` coerente com o `tipo`).
2. `tipo = lista`: consulta `DescadastroDeEmailInterface::estaDescadastrado(escopo, para, lista)` (que já cobre o
   `todos`). Descadastrado: grava o envio como `bloqueado_descadastro` e devolve sem enviar.
3. Grava um `EnvioDeEmail` (§3.2) como `enfileirado` e despacha `EnviarEmail(envioId)` no transporte `async`.
4. Devolve o resultado (enfileirado, bloqueado ou recusado).

O handler `EnviarEmailHandler`:
1. Relê o envio; se não está `enfileirado`, para (idempotente: a mensagem pode chegar duas vezes).
2. Para `lista`, consulta o descadastro DE NOVO (a pessoa pode ter saído entre o enfileirar e o enviar).
3. Pede um token ao limitador `comercial_envio` (vazão, §3.5). Sem token: reenfileira com `DelayStamp` e para.
4. Monta o e-mail com o layout base (§3.6), aplica `IdentidadeDeEmail`, e em `lista` acrescenta `List-Unsubscribe`
   (link do `gerarLink`) e `List-Unsubscribe-Post: List-Unsubscribe=One-Click`.
5. Em `lista` com `rastrear`: pixel de abertura e reescrita dos links (§3.7).
6. `MailerInterface::send` (síncrono dentro do worker). Sucesso: `enviado`, `enviadoEm`. Falha de transporte:
   relança para o retry do Messenger; na última tentativa, `falhou` com o erro (sem dado pessoal no log).

Os e-mails transacionais que já existem (`CadastroMailer`, `ConviteMailer`, `RedefinicaoSenhaMailer`) **não migram
nesta fatia**: continuam síncronos e iguais. Só o que esta frente cria passa pelo Carteiro. Migrar os antigos é
follow-up, se o dono quiser (P5).

### 3.2 Registro dos envios

Tabela `comercial_envio` (entidade `EnvioDeEmail`, sem `TenantAware`: é do Master e cruza escritórios; o escopo fica
na coluna `tenant_id` nullable): `id`, `token` (32 hex aleatório, público, para pixel e clique), `tenant_id` (null =
plataforma), `destinatario`, `nome`, `assunto`, `tipo`, `lista`, `origem`, `situacao` (`enfileirado`, `enviado`,
`falhou`, `bloqueado_descadastro`), `erro`, `criado_em`, `enviado_em`, `primeira_abertura_em`, `aberturas`,
`primeiro_clique_em`, `cliques`. Índices: `token` UNIQUE, `(origem, criado_em)`, `(destinatario)`.

**Retenção (LGPD):** os envios guardam e-mail e nome. Proposta: 24 meses, depois o destinatário vira hash (o número
por campanha continua). Entra no `app:purgar-dados-expirados`. Ver P4.

### 3.3 Confirmação de endereço (double opt-in reaproveitável)

Para qualquer lista que exija consentimento (alertas de vagas da a7, base de contatos das campanhas):
`App\Comercial\Consentimento\ConfirmacaoDeEnderecoInterface`:
- `pedir(lista, email, origem, versaoDoConsentimento, textoDoConsentimento): void`: grava a pendência e manda, pelo
  Carteiro como `transacional`, o e-mail "Confirme que quer receber <nome da lista>", com link assinado de 7 dias.
  Repetir o pedido para o mesmo endereço e lista reenvia no máximo 1 vez por 24 h (limitador por e-mail).
- `confirmar(token)`: marca `confirmado_em`, com IP e agente (prova do consentimento). Página pública simples
  "Endereço confirmado" com o link de descadastro.
- `estaConfirmado(lista, email): bool`.

Tabela `comercial_confirmacao_endereco`: `id`, `lista`, `email`, `origem`, `versao_consentimento`,
`texto_consentimento`, `token_hash`, `pedido_em`, `expira_em`, `confirmado_em`, `ip`, `agente`. UNIQUE
(`lista`, `email`). O texto do consentimento fica gravado como foi mostrado (prova).

Quem envia para uma lista com consentimento confere `estaConfirmado` E o descadastro. A a7 chama `pedir` na
inscrição de alertas e não envia nada antes da confirmação.

### 3.4 As 8 automações

Enum `App\Comercial\Automacao\Automacao` com as 8 chaves, título, descrição, modelo, opções de atraso e o padrão do
dc. Tabela `comercial_automacao` guarda só o que o Master mudou (`chave` PK, `ligada`, `atraso`, `alterada_por`,
`alterada_em`); sem linha, vale o padrão. **Nada dispara no deploy:** cada automação só reage a um evento novo,
depois que o código entrar; nenhuma varre o passado (a varredura só olha cadastros criados depois do deploy, pela data
de corte gravada na primeira execução).

| Chave | Gatilho no sistema | Quem recebe | Como |
|---|---|---|---|
| `cad` | É o e-mail de confirmação que o `CadastroMailer` já manda | quem se cadastrou | não muda (P2) |
| `lemb` | Varredura: `CadastroPendente` sem confirmação há N horas (24/48/72) e ainda válido | quem se cadastrou | 1 lembrete por cadastro; como o link vale 24 h, o lembrete de 24 h sai antes de vencer só se o atraso for menor que a validade (P3) |
| `val` | Evento novo `CadastroConfirmado(userId, tenantId)`, disparado pelo `ConfirmarCadastroUseCase` depois do commit | o novo primário | Boas-vindas |
| `conv` | Evento novo `ConviteDeEscritorioAceito(userId, tenantId)`, nos dois UseCases de aceite | o secundário | Boas-vindas |
| `dica` | Varredura: conta confirmada há N dias (3/7/15) | o primário | Primeiros passos; desligada por padrão (dc) |
| `e90` | Ouvinte de `EspacoAtingiuFaixa` (f6, #64), faixa que corresponde ao limite escolhido (P3) | o primário | no máximo 1 por dia por escritório (spec da f6) |
| `e100` | Ouvinte de `EspacoAtingiuFaixa`, faixa `Bloqueio` | o primário | idem |
| `ped` | Evento `EspacoFoiPedido(tenantId, plano)` pedido à f6 no `PedirEspacoUseCase` | o primário, e o aviso ao Master que a f6 já faz | Pedido de espaço recebido |

Ganchos de identidade (rigor ALTO): no `ConfirmarCadastroUseCase` e nos dois UseCases de aceite de convite entra só
um `dispatch` do evento depois do `flush`/commit, sem mudar mais nada; o ouvinte enfileira pelo Carteiro (falha de
e-mail nunca desfaz o cadastro). O ouvinte de `EspacoAtingiuFaixa` (que é disparado antes do commit, síncrono) só
enfileira.

Todas as automações são `tipo = transacional` (são do próprio ciclo da conta, legítimo interesse/execução do
contrato), exceto `dica`, que é `lista` (`comercial_dicas`) e respeita o descadastro. Ver P2.

**Varredura:** mensagem `VarrerAutomacoes` que se reagenda a cada 15 min (`DelayStamp`), semeada por
`app:comercial:varredura` (padrão do #76). Controle em `comercial_varredura` (última execução, data de corte).

**Na tela (parcial do #56):** o bloco "Automações" do dc com o interruptor (POST `master_comercial_automacao`, JSON,
`X-CSRF-Token`, auditado), o seletor de atraso, o botão do modelo (abre a prévia do modelo; o editor é da fatia 2) e
"Últimos disparos" (os 6 últimos envios com `origem` de automação). "Simular novo cadastro": envia ao **Master logado**
o e-mail de confirmação (com link de exemplo, sem efeito) e o de boas-vindas, marcados `origem = teste`, que aparecem
em "Últimos disparos". Não cria conta.

### 3.5 Vazão

Limitador `comercial_envio` (token bucket) com taxa e teto diário em parâmetro (`COMERCIAL_ENVIOS_POR_MINUTO`,
`COMERCIAL_TETO_DIARIO`), padrão conservador para o Gmail (P1): 20 por minuto e 1.500 por dia. Atingido o teto, as
mensagens esperam o dia seguinte (DelayStamp). Os transacionais antigos não passam por aqui.

### 3.6 Layout base

`templates/email/comercial/_layout.html.twig`: tabelas e estilo inline (clientes de e-mail), cabeçalho do dc
(degradê azul, logo, selo, título), corpo, e o **rodapé legal sempre** (B3): razão social, CNPJ, cidade, a frase do
dc, o link "Não quero mais receber" (só em `lista`), o link da Política de Privacidade (B4). Versão texto gerada do
HTML. O rodapé não é editável por modelo.

### 3.7 Rastreio (só em `lista` com `rastrear`)

- Abertura: `<img>` 1x1 para `GET /e/a/{token}.gif` (público, sem sessão): grava a primeira abertura e soma.
- Clique: cada `href` http(s) vira `GET /e/c/{token}?u=<url>&s=<assinatura>` (UriSigner, sem redirecionamento aberto):
  grava e redireciona 302 para a URL original. Links de descadastro e de privacidade não são reescritos.
- As duas rotas respondem igual com token inválido (sem revelar nada) e têm limitador por IP.
- A Política de Privacidade precisa citar o rastreio (C2, rascunho da f6).

### 3.8 Fora da fatia 1

Campanhas, editor de blocos e modelos editáveis (fatia 2); números e CSV por campanha e a lista de descadastrados
(fatia 3); indicações e datas (fatia 4).

## 4. Testes da fatia 1

- Unit: Carteiro (lista descadastrada não envia e grava `bloqueado_descadastro`; transacional não consulta o
  descadastro; DTO incoerente recusado); handler (idempotência, descadastro relido, vazão sem token reenfileira,
  falha na última tentativa vira `falhou`, rodapé e List-Unsubscribe presentes em `lista`, ausentes em
  `transacional`); reescrita de links (descadastro e privacidade não reescritos); enum das automações (padrões do dc).
- Integração: `comercial_envio` (token único, retenção), `comercial_confirmacao_endereco` (UNIQUE, expiração,
  reenvio limitado).
- Functional: rotas de pixel e clique (token inválido igual ao válido para quem olha; assinatura errada não
  redireciona); confirmar endereço; interruptor da automação só para `ROLE_SUPER_ADMIN`, com CSRF, auditado;
  "Simular novo cadastro" envia só ao Master logado; `ConfirmarCadastroUseCase` e aceites disparam o evento só
  depois do commit (falha do e-mail não desfaz o cadastro); nada é enviado a cadastros anteriores à data de corte.
- E-mails nos testes: `MailerAssertionsTrait` com transporte em memória.

## 5. O que o dono olha na tela (fatia 1)

No `/master#sec=com`, o bloco Automações: ligar e desligar, mudar o atraso, "Simular novo cadastro" (os dois e-mails
chegam na caixa do Master) e os "Últimos disparos". Fazer um cadastro de teste e ver chegar as boas-vindas depois de
confirmar.

## 6. Perguntas novas ao dono (pelo coordenador)

- **P1. Provedor de envio.** Produção manda pelo SMTP do Gmail, não pela Hostinger (a mensagem do coordenador supunha
  Hostinger). O Gmail limita o volume diário e não dá aviso de entrega nem de devolução; envio em massa por ele arrisca
  o bloqueio da conta. Recomendação: campanhas e datas por um provedor transacional (ou o SMTP da Hostinger, se o
  plano permitir volume), com SPF e DKIM do domínio. Até lá, a vazão fica no padrão conservador (§3.5).
- **P2. O interruptor de `cad`.** Desligar "Ao abrir uma conta" impediria qualquer cadastro (a conta só nasce com a
  confirmação). Proposta: o interruptor de `cad` aparece travado em "ligada", com a dica "Obrigatório: sem ele
  ninguém consegue criar conta". É desvio do dc.
- **P3. Faixas e prazos.** (a) O dc oferece 80/85/90% para o `e90`, mas a f6 só emite as faixas dela
  (Atencao, Alerta, Critico, Bloqueio) e a tela de Armazenamento usa 70/90/95/100. Proposta: o seletor do `e90`
  oferece as faixas que a f6 emite (90% = Alerta). (b) O `lemb` em 24 h coincide com a validade do link (24 h):
  proposta, o lembrete manda um link NOVO (renova a validade do `CadastroPendente` por mais 24 h), uma vez só.
- **P4. Retenção dos envios.** 24 meses, depois o e-mail vira hash.
- **P5. Migrar os e-mails antigos** (senha, convite, confirmação) para o Carteiro e o layout novo: follow-up, fora
  desta frente, se o dono quiser.
