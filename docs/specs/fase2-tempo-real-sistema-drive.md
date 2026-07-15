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
