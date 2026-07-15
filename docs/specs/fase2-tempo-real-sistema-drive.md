# Spec — Fase 2: baixa latência sistema→Drive (gatilho por evento)

> Spec própria da **Fase 2** do programa de sincronização Drive↔sistema. A
> visão de 4 fases, as decisões D1–D12 e o motor (Fase 1) estão na spec-mãe
> `sincronizacao-drive-bidirecional.md` — **leia-a antes** (em especial §8 o
> `GoogleDriveClient`, §10 o motor, §21 o estado operacional atual).

**Risco:** MÉDIO. Toca UseCases do domínio Pasta (dispatch de eventos), adiciona
infraestrutura de fila (Messenger) e um processo worker em prod, e — no
pré-requisito "Conectar meu Drive" — armazena **credenciais OAuth por tenant**
(secret). Exige spec (este documento), revisão adversarial (`feature-review-agent`)
antes de integrar, e smoke manual.

**Status:** design (não implementado). **Depende de:** Fase 1 (0/1/1a/1b) em prod
e provada (✅ 2026-07-14).

---

## 1. Objetivo (o que a Fase 2 entrega)

1. **Latência de segundos** no sentido **sistema→Drive**: criar pasta / anexar
   documento no sistema reflete no Drive quase na hora, em vez dos ~15 min do cron.
2. **Fim da varredura completa por rodada.** Hoje o `app:sync:reconciliar` varre
   **todas** as pastas do tenant a cada execução só para descobrir o que mudou —
   custo O(nº de pastas) que cresce com o acervo. A Fase 2 processa **apenas o
   que mudou** (a pasta/documento do evento).

**Não-objetivo (fica na Fase 3, adiada — D11):** o sentido inverso Drive→sistema
em tempo real (arquivo criado *no Drive* aparecer na hora no sistema). Isso exige
webhooks/`changes feed` do Google e continua na latência periódica do cron.

---

## 2. Pré-requisito: "Conectar meu Drive" (credenciais por tenant)

A Fase 1 roda com credenciais **globais** (env `GOOGLE_DRIVE_OAUTH_*` no
`.sync-oauth.env`, da conta do rclone, tenant 1). Um gatilho por evento é, por
natureza, **por tenant** — quando um usuário do escritório X cria uma pasta, o
handler precisa das credenciais do Drive **do escritório X**. Logo, antes da
Fase 2 valer para além do tenant 1, é preciso a peça de conexão por tenant.
Decisão de arquitetura já registrada (spec-mãe, commit `acc44c1`): **cada
escritório conecta a PRÓPRIA conta** (OAuth "login de pessoa"), nunca um robô
único — por isolamento multi-tenant e custódia.

### 2.1 Entidade `TenantDriveConexao` (nova, domínio `App\Sync`)
Uma linha por tenant conectado:
- `tenant` (FK, único) · `refreshToken` (**cifrado em repouso** — ver 2.4) ·
  `rootFolderId` (a "raiz" do acervo daquele escritório no Drive) ·
  `contaEmail` (identificação da conta conectada, para exibir) ·
  `escopo` · `conectadoEm` / `conectadoPor` · `ativo`.
- **Multi-tenant:** toda leitura filtra por tenant; nunca buscar conexão de
  outro tenant. Segue [[feedback_multitenant_isolamento]].

### 2.2 Fluxo de conexão (UI + OAuth)
- Tela em Configurações do escritório: botão **"Conectar meu Drive"** (gate de
  permissão adequado — quem administra o escritório).
- Inicia o OAuth do Google (mesmo app `bluejus-sync`, já **publicado em
  Produção** → refresh_token não expira): redirect para o consentimento →
  callback no app → troca o `code` por `refresh_token` (server-side) → cria/atualiza
  `TenantDriveConexao`. Também escolher/definir o `rootFolderId` (a pasta-raiz do
  acervo daquele escritório).
- Botão "Desconectar" (revoga/limpa a conexão).
- **CSRF** no início do fluxo; `state` do OAuth validado no callback (anti-CSRF do
  próprio protocolo).

### 2.3 `GoogleDriveClient` por tenant
Hoje o `GoogleDriveClient` lê as credenciais de env (global). Para multi-tenant:
- Uma **fábrica** `GoogleDriveClientFactory::paraTenant(Tenant): GoogleDriveClientInterface`
  que injeta as credenciais da `TenantDriveConexao` do tenant.
- O motor (`ReconciliarCommand`) e o handler da Fase 2 passam a obter o client
  pela fábrica, por tenant. Env global vira apenas *fallback* do tenant 1 (ou é
  migrado para uma `TenantDriveConexao` do tenant 1 e o env é aposentado).
- O `reconciliar` deixa de ser hardcoded em `--tenant-id=1`: passa a iterar sobre
  os tenants **com conexão ativa** (ou aceitar `--tenant-id` para um só).

### 2.4 Segurança das credenciais
- `refreshToken` **cifrado** no banco (ex.: `halite`/`sodium` ou o secret vault do
  Symfony; nunca texto puro). Chave de cifra fora do banco (env/secret).
- Nunca logar o token. Nunca expor via API/serialização.

---

## 2bis. Decisões travadas da implementação (2026-07-14)

Escopo confirmado com o P.O.: **multi-tenant completo** ("Conectar meu Drive" +
Fase 2). Decisões abaixo fecham os pontos que a spec original deixou em aberto.
Baseadas na investigação do código-base atual (master `1b10f3c`).

**D-a. Entidade `TenantDriveConexao` (domínio `App\Sync\Entity`, tabela
`sync_drive_conexao`).** Segue o padrão do projeto (Carteira/JornadaTenant):
- `id` **int identity** (o projeto **não usa UUID** em lugar nenhum);
- `tenant` **`OneToOne`** com `Tenant` (`JoinColumn` único, `nullable: false`) —
  uma conexão por escritório; lado dono nesta entidade;
- `refreshTokenCifrado` (`TEXT`, blob base64 do sodium — **nunca** texto puro);
- `rootFolderId` (`VARCHAR`, a pasta-raiz do acervo no Drive daquele escritório);
- `contaEmail` (`VARCHAR`, identificação da conta conectada, para exibir);
- `escopo` (`VARCHAR`), `ativo` (`bool`), `conectadoEm`/`conectadoPor` (User),
  `atualizadoEm`.
- Registrar o mapping do domínio: **novo bloco `AppSync`** em
  `config/packages/doctrine.yaml` (`dir: src/Sync/Entity`, `prefix:
  'App\Sync\Entity'`, `alias: AppSync`) — hoje `App\Sync` só tem Service/Command.
- Repository `TenantDriveConexaoRepository` com **`findAtivaDoTenant(Tenant)`** e
  `findOneByTenant` no padrão `findOneByIdDoTenant` (filtro explícito de tenant;
  o `TenantFilter` automático não cobre `find()` por PK — [[feedback_multitenant_isolamento]]).

**D-b. Cifra = `sodium_crypto_secretbox` nativo (zero dependência).** Serviço
`App\Sync\Service\CifradorDeSegredo` (`cifrar(string): string` /
`decifrar(string): string`), nonce aleatório por operação (prefixado ao
ciphertext), saída base64. Chave de 32 bytes numa **env nova
`SYNC_CRYPTO_KEY`** (fora do banco, no `.env.prod`; bind em `services.yaml`).
Testável isoladamente (unit). Nunca logar/serializar o token decifrado.

**D-c. `rootFolderId` = colar o ID/URL da pasta.** Após o OAuth, o admin informa
o link/ID da pasta-raiz do acervo do escritório no Drive (helper extrai o id de
uma URL `…/folders/<id>`). Picker visual de pastas fica como follow-up.

**D-d. Permissão = reusar `admin.tenant.settings.manage`** (a mesma da config do
escritório — `TenantController`). **Sem** data-migration de permissão. Gate no
padrão existente: `ROLE_SUPER_ADMIN` OU (`existeVinculoAtivo` +
`canAdminister($user,$tenant,'admin.tenant.settings.manage')`).

**D-e. Fluxo OAuth in-app** (não existe hoje; o `GoogleDriveClient` só consome
refresh_token pronto). Adicionar ao client/factory:
- `criarUrlDeAutorizacao(string $state): string` (`createAuthUrl`, escopo
  `drive`, `access_type=offline`, `prompt=consent` p/ garantir refresh_token);
- `trocarCodePorRefreshToken(string $code): array{refreshToken,email,escopo}`
  (`fetchAccessTokenWithAuthCode`).
- Rotas (domínio `App\Sync\Controller\DriveConexaoController`, sob a config do
  escritório): **início** (`GET`, gera `state` na sessão + CSRF, redireciona ao
  Google), **callback** (`GET`, valida `state`, troca o code, cifra e persiste a
  `TenantDriveConexao`, pede/salva o `rootFolderId`), **desconectar**
  (`POST` + CSRF, limpa/desativa a conexão). `redirect_uri` fixo do app
  `bluejus-sync` (já publicado em Produção → refresh_token não expira).

**D-f. Fábrica `GoogleDriveClientFactory::paraTenant(Tenant): GoogleDriveClientInterface`.**
Lê a `TenantDriveConexao` ativa do tenant, decifra o refresh_token, e instancia o
`GoogleDriveClient` (client_id/secret **globais** do app + refresh_token +
`rootFolderId` **por tenant**). Sem conexão ativa → exceção `TenantSemDriveException`
(o dispatch/handler tratam como no-op; o comando pula o tenant). O `client()` do
`GoogleDriveClient` já resolve OAuth a partir do refresh_token — a fábrica só o
alimenta. O env global `GOOGLE_DRIVE_OAUTH_*` do tenant 1 vira **fallback/semente**:
migro o tenant 1 para uma `TenantDriveConexao` (comando one-time) e o env pode ser
aposentado depois.

**D-g. Camada de dispatch = CONTROLLER, não UseCase.** Motivo (investigação): (1)
`CriarPastaUseCase` é chamado pelo próprio `ReconciliarCommand` → dispatch no
UseCase dispararia sync durante a reconciliação (duplo-disparo); (2) os dois
fluxos mais usados de "adicionar documento" (aba Documentos e financeiro)
**persistem `PastaDocumento` inline no `PastaController`, sem UseCase**. Logo, um
helper central no controller (`despacharSyncDaPasta(Pasta $pasta)`) cobre de forma
uniforme e evita o duplo-disparo. Pontos de dispatch (pós-flush): criar pasta,
`UploadPecaUseCase`, `SalvarPecaTextoUseCase`, `PastaController::uploadDocumento`,
`PastaController::financeiroUpload`. Só dispara se o tenant tem conexão ativa.

**D-h. Refator `ReconciliadorDePasta`** (pré-Messenger): extrair
`processarArquivosDaPasta` + helpers do `ReconciliarCommand` para um serviço que
**recebe `GoogleDriveClientInterface` por parâmetro** (não injeta o global) e
**devolve contadores numa estrutura** (`ResultadoReconciliacaoPasta`), sem
`SymfonyStyle` (o handler Messenger não tem `$io`). O comando vira loop fino sobre
o serviço; o handler chama o mesmo serviço para uma pasta. Refatoração **sem mudar
comportamento**, testes primeiro.

**D-i. Messenger.** `symfony/messenger` + `symfony/doctrine-messenger` (não
instalados). Transport `async` = doctrine (tabela `messenger_messages`; migration
via `messenger:setup-transports` ou DDL na migration). Retry 3× backoff; `failed`
transport. Testes usam transport `in-memory` (sem tocar o Drive). Worker em prod =
serviço no `docker-compose.prod.yml` (`messenger:consume async --time-limit=3600`,
`restart: unless-stopped`), lê credenciais do **banco** (via fábrica), não do
`.sync-oauth.env`.

---

## 3. Arquitetura da Fase 2 (o gatilho)

### 3.1 Por que fila e não chamada direta
Chamar o `GoogleDriveClient` **inline** no UseCase (dentro da request HTTP) é
ruim: (a) trava a tela do usuário esperando a API do Google; (b) se o Drive
falhar, a ação do usuário falha junto. Solução: **enfileirar** e processar em
segundo plano, com retry.

### 3.2 Symfony Messenger + transport Doctrine
- Transport **doctrine** (tabela `messenger_messages` no próprio banco) — sem
  infra extra, coerente com o porte atual. (Redis fica para escala futura.)
- Roteia a mensagem da Fase 2 para o transport `async`.
- **Retry** configurado (ex.: 3 tentativas, backoff exponencial) para erros
  transitórios da API do Drive. Após o limite → `failed` transport (fila morta),
  visível para inspeção.

### 3.3 Mensagem + Handler (novos, `App\Sync\Message` / `MessageHandler`)
- Mensagem: **`SincronizarPastaNoDrive(int $pastaId, int $tenantId, int $usuarioId)`**.
  (Granularidade por PASTA — cobre "pasta criada" e "documento novo na pasta"
  com uma única mensagem; naturalmente deduplicável/coalescível.)
- Handler: carrega o client do tenant (fábrica 2.3), e executa a **lógica de
  reconciliação daquela pasta** — a mesma que o motor já faz por pasta (garante o
  `drive_folder_id`, sobe documentos sem `drive_file_id`, cria subpasta-espelho por
  seção). Idempotente por `drive_file_id`/`drive_folder_id`.

### 3.4 Refatoração pré-requisito: extrair o motor por-pasta
Hoje a lógica por pasta vive dentro do `ReconciliarCommand`. Extrair para um
**serviço reutilizável** `ReconciliadorDePasta` (ou `SincronizadorDePasta`) que:
- recebe `(pastaId, tenantId, usuarioId, GoogleDriveClientInterface)`;
- faz a Via A (sistema→Drive) e, opcionalmente, a Via B (Drive→sistema) daquela
  pasta;
- é chamado **tanto** pelo `ReconciliarCommand` (loop de todas as pastas) **quanto**
  pelo handler da Fase 2 (uma pasta). Evita duplicar regra e mantém uma fonte de
  verdade. (O `ReconciliarCommand` passa a ser um loop fino sobre o serviço.)

### 3.5 Onde disparar (os gatilhos)
Nos UseCases do domínio Pasta, **após o `flush`** (nunca antes), injetar o
`MessageBusInterface` e `dispatch(new SincronizarPastaNoDrive(...))`:
- **Criar pasta** — `CriarPastaUseCase`.
- **Adicionar documento** — o(s) UseCase(s) de upload/criação de `PastaDocumento`
  (ex.: `UploadPecaUseCase` e afins — mapear na investigação).
- **Rename** (opcional; D10 hoje é só-reporta) — pode ficar de fora do MVP.
- Só dispara se o **tenant tem conexão ativa** (senão é no-op — nada a sincronizar).

### 3.6 O cron continua como rede de segurança
O `app:sync:reconciliar` periódico **permanece** (frequência pode cair, ex.: 1×/h),
porque: (a) pega eventos perdidos (worker fora do ar, mensagem descartada); (b)
faz o sentido **Drive→sistema** (Fase 3 não existe). O gatilho é o caminho rápido;
o cron é a garantia de convergência.

### 3.7 Worker em produção
- Um **serviço/container** rodando `php bin/console messenger:consume async
  --time-limit=3600` (reiniciado periodicamente), adicionado ao
  `docker-compose.prod.yml` + ao fluxo de deploy. Precisa das credenciais (via a
  `TenantDriveConexao`, então lê do banco — não precisa do `.sync-oauth.env`).
- Alternativa mais simples (sem container novo): um cron `*/1` que roda
  `messenger:consume --limit=N --time-limit=55` — porém um worker dedicado é o
  padrão e evita latência de até 1 min.
- Supervisão: `restart: unless-stopped` no compose (ou systemd) para o worker
  voltar sozinho.

---

## 4. Multi-tenancy e isolamento
- A mensagem carrega `tenantId`; o handler resolve o client **daquele** tenant e
  só toca dados **daquele** tenant. Nunca cruza.
- `TenantDriveConexao` é por-tenant; queries filtram por tenant.
- Teste cross-tenant obrigatório (um tenant não dispara/afeta o Drive de outro).

## 5. Testes
- **Unit** do `ReconciliadorDePasta` (com `FakeGoogleDriveClient`): Via A/B de uma
  pasta, idempotência, guards.
- **Unit/Functional** do handler: consome a mensagem, chama o serviço, trata falha
  (retry — mensagem volta à fila; não persiste vínculo pela metade).
- **Functional** do dispatch: criar pasta / subir documento **enfileira** a mensagem
  (assert no transport de teste `in-memory`), sem chamar o Drive de verdade.
- **Functional** do fluxo "Conectar meu Drive": conexão criada/atualizada, token
  cifrado, gate de permissão, CSRF, cross-tenant.
- Fronteira `GoogleDriveClient` real segue **validação manual** (fora do CI).

## 6. Critério de aceite (DoD)
- Conectar meu Drive: um escritório conecta a própria conta; token cifrado; sync
  passa a usar as credenciais do tenant.
- Criar pasta / subir documento no sistema → aparece no Drive em **segundos**
  (worker no ar), sem travar a tela.
- Drive fora do ar → a mensagem faz retry e conclui quando volta; o usuário não é
  afetado; nada duplicado (idempotência).
- O cron periódico continua e reconcilia o que escapou + o sentido Drive→sistema.
- Suíte verde; revisão adversarial sem bloqueante; smoke manual no dev.

## 7. Ordem de implementação sugerida
1. **Conectar meu Drive** (pré-requisito): entidade + migration, fluxo OAuth,
   cifra do token, fábrica de client por tenant, tornar o `reconciliar`
   multi-tenant (iterar conexões ativas). Migrar o tenant 1 do env para uma
   `TenantDriveConexao` e aposentar o `.sync-oauth.env` (ou mantê-lo como fallback).
2. **Extrair `ReconciliadorDePasta`** do `ReconciliarCommand` (refatoração sem
   mudar comportamento; testes primeiro).
3. **Messenger**: transport doctrine + retry + mensagem + handler.
4. **Gatilhos** nos UseCases de Pasta (dispatch pós-flush).
5. **Worker** no `docker-compose.prod.yml` + deploy.
6. Baixar a frequência do cron (rede de segurança) e validar ponta a ponta.

## 8. Riscos / pontos de atenção
- **Ordem criar-pasta → subir-documento:** o handler por pasta cria o folder e
  sobe os docs na mesma execução (a lógica do motor já garante essa ordem).
- **Coalescência:** muitos documentos numa pasta geram muitas mensagens iguais; a
  idempotência torna duplicatas inofensivas, mas dá para deduplicar/`stamp` por
  pasta se o volume incomodar.
- **Segredos:** cifra do `refreshToken` é inegociável; chave fora do banco.
- **Worker órfão / travado:** healthcheck + restart; o cron cobre a lacuna.
- **Deploy:** worker é imagem baked; subir/reiniciar o worker faz parte do deploy.

---

## 9. Ativação em produção (passo a passo — humano)

Implementado no worktree `.worktrees/fase2-sync` (commits `787feaf` Conectar meu Drive,
`23c166c` ReconciliadorDePasta+multi-tenant, `39db266` Messenger, `527134f` gatilhos,
e este de worker/deploy). Suíte 1902/1902 (Cobranças + Fase 2 integradas). Ordem de ativação em prod:

1. **`.env.prod` — 3 variáveis (ANTES do deploy).**
   - `SYNC_CRYPTO_KEY=$(openssl rand -base64 32)` — sem ela o reconcile e o worker falham ao
     decifrar o token do tenant.
   - **`GOOGLE_DRIVE_OAUTH_CLIENT_ID` e `GOOGLE_DRIVE_OAUTH_CLIENT_SECRET` — MOVER do
     `/opt/jusprime/.sync-oauth.env` para o `.env.prod`.** ⚠️ Crítico: o container `worker` recebe
     env **só do `.env.prod`** (não enxerga o `.sync-oauth.env`, que hoje o wrapper do cron injeta
     com `-e`). O refresh_token agora vem do banco, mas o client_id/secret continuam GLOBAIS e são
     exigidos pela fábrica — sem eles o worker morre com "OAuth do Drive incompleto".
   - `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=1` já vem por default do `.env`
     (o worker/handler criam a tabela `messenger_messages` sozinhos no 1º uso); só sobrescrever
     se quiser outro transport.

   Depois de semear o tenant 1 (passo 4), o `.sync-oauth.env` e os `-e` do wrapper do cron ficam
   dispensáveis (refresh_token e pasta-raiz passam a vir do banco).

2. **OAuth web (redirect_uri) — NÃO bloqueia o deploy.** Só é necessário quando um SEGUNDO
   escritório for conectar pela tela "Conectar meu Drive" (o tenant 1 é semeado por CLI, sem UI).
   Registrar `https://bluejus.com.br/sync/drive/conexao/callback`
   como redirect autorizado no app `bluejus-sync` (Google Cloud → Credenciais). O cliente atual
   é **Desktop app**; o fluxo in-app precisa de um cliente **Web application** com esse
   redirect_uri. Usar o novo `client_id/secret` (Web) em `GOOGLE_DRIVE_OAUTH_CLIENT_ID/SECRET`
   no `.env.prod`.

3. **PAUSAR o cron do sync (antes do deploy).** Entre o deploy e a semeadura do tenant 1 existe
   uma janela em que o `reconciliar` erraria ("tenant sem Drive conectado"). Comentar a linha:
   ```
   crontab -l > ~/crontab.bak && crontab -l | sed '/sync-reconciliar\.sh/ s/^/#/' | crontab -
   ```

4. **Deploy.** `./scripts/deploy-prod-tls.sh` na VPS (rebuild+recreate). Sobe o serviço
   **`jusprime_worker_prod`** e aplica a migration `Version20260715120000` (cria `sync_drive_conexao`).
   *(Renumerada de `20260714130000`, que colidiu com a migration do ajuste 7 de Cobranças já aplicada
   em prod — com o número antigo o Doctrine a pularia e a tabela nunca seria criada.)*

5. **Semear o tenant 1.** O reconcile agora lê a conexão do BANCO (não mais do env), então ele só
   volta a funcionar depois que a `TenantDriveConexao` do tenant 1 existir. Semear a partir do
   refresh_token/pasta-raiz que já rodavam o cron:
   ```
   set -a; . /opt/jusprime/.sync-oauth.env; set +a
   docker exec -e GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN -e GOOGLE_DRIVE_SHARED_DRIVE_ID \
     -w /var/www/app jusprime_php_prod \
     php bin/console app:sync:conectar-tenant --tenant-id=1 --usuario-id=1
   ```
   (`SYNC_CRYPTO_KEY` vem do `.env.prod` via `env_file`.) Conferir:
   `SELECT tenant_id, ativo, root_folder_id, conta_email FROM sync_drive_conexao;` → 1 linha, `ativo=t`.

6. **Provar o reconcile à mão, com o cron ainda pausado:**
   ```
   docker exec -w /var/www/app jusprime_php_prod \
     php bin/console app:sync:reconciliar --tenant-id=1 --usuario-id=1 --dry-run
   ```
   Tem de imprimir "Reconciliando tenant 1 contra a raiz `1WBY-…`" e um resumo sem erro. Se disser
   "não tem Drive conectado", a semeadura falhou — NÃO religue o cron.

7. **Religar o cron, com frequência menor.** Com o worker no ar (caminho rápido sistema→Drive), o
   `sync-reconciliar.sh` vira rede de segurança + sentido Drive→sistema: descomentar e mudar de
   `*/15` para `0 * * * *` (de hora em hora). Opcional: tirar o `--tenant-id=1` do wrapper para
   cobrir todos os tenants conectados (`reconciliar --usuario-id=1`, itera as conexões ativas).

8. **Prova final.** Criar pasta / subir documento no sistema → conferir `docker logs jusprime_worker_prod`
   e o Drive: a pasta/arquivo aparece em segundos, sem esperar o cron.

**Notas de operação do worker:** sai a cada `--time-limit=3600` (1h) e é reerguido pelo
`restart: unless-stopped` (memória fresca). Mensagens que falharem 3 retries vão para o transport
`failed` (`doctrine://default?queue_name=failed`) — inspecionar com
`php bin/console messenger:failed:show`. O worker lê tudo do banco (não precisa do `.sync-oauth.env`).
