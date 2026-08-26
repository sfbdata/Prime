# Spec — Integração DJEN (captação de comunicações/publicações processuais)

> ⚠️ **Renomeado em 2026-08-19 — o módulo se chama "Push Processual" na interface.** As URLs passaram
> de `/djen*` para `/push-processual*` e os nomes de rota de `djen_*` para `push_processual_*`. As URLs
> antigas seguem vivas com 301 (`RotasLegadasDjenController`), porque 199 notificações em produção têm
> `url = '/djen'` gravada na linha.
>
> **O que continua valendo abaixo:** classes, namespace `App\Djen`, tabela `publicacao_djen` e o código
> da permissão `modules.djen.view` seguem com o nome DJEN — que é o nome do sistema do CNJ, não o do
> nosso módulo. **O que foi atualizado junto com esta nota:** as menções a nomes de rota (`djen_*` →
> `push_processual_*`), nas seções "Onde está o código" e RS06. O texto de erro que fala do sistema do
> CNJ (`MotivoFalhaDjen`) e a mensagem da notificação ("capturada no DJEN") **não** mudaram, de
> propósito: falam da origem, não do módulo.

> **Risco:** MÉDIO — integração externa (API pública do CNJ) que **escreve dados por tenant** e gera
> notificações. Não toca ponto eletrônico nem identidade User/Tenant, mas cria domínio novo multi-tenant
> + comando CLI que escreve com o TenantFilter desligado (o ponto mais sensível).
> **Data:** 2026-07-06 · **Última atualização:** 2026-07-06
> **Status:** ✅ Implementada (F1–F5), testada e revisada (3 revisores adversariais; furos corrigidos) ·
> Suíte completa verde; testes DJEN unit + functional + isolamento cross-tenant · **NÃO commitada** (git é do humano)
> **Domínios tocados:** `App\Djen` (novo) · `App\Processo` (vínculo, read-only) · `App\Entity\Notificacao`
> + `App\Service\NotificacaoService` (aditivo: novo tipo) · permissões (novo módulo `djen`) · `services.yaml`/`.env`

---

## 🧭 Estado atual / Handoff (leia primeiro)

**✅ FEATURE IMPLEMENTADA, TESTADA E REVISADA (2026-07-06). NÃO commitada (git é do humano).**

Fases F1–F5 + formatação do teor. Suíte completa **1271/1271 verde**; **68 testes DJEN** (unit + functional + isolamento
cross-tenant). Revisada por 3 agentes adversariais (isolamento LIMPO, núcleo correto, conformidade OK) +
1 re-revisão das correções. Todos os furos acionáveis dos reviews foram corrigidos (ver "Correções pós-review").

**Onde está o código (tudo novo em `app/src/Djen/`):** Entity (`OabMonitorada`, `PublicacaoDjen`),
Repository, Service (`DjenClient`+Interface, `DjenPublicacaoMapper`, `NotificadorPublicacoesDjen`+Interface, `FormatadorTeorDjen`),
UseCase (`AdicionarOabMonitorada`, `Remover`, `AlternarStatus`, `SincronizarPublicacoesDjen`), Command
(`SincronizarDjenCommand` = `app:djen:sincronizar`), Controller (`DjenController`, rotas `push_processual_*`
— ver a nota de renomeação no topo), DTO,
Form, Enum (`MotivoFalhaDjen`), Exception. Templates em `app/templates/djen/`. Testes em `app/tests/Djen/`.

**Integrações (edições em arquivos existentes):** `config/services.yaml` (bind `$djenBaseUrl` + 2 aliases de
interface), `config/packages/doctrine.yaml` (mapping `AppDjen` — **obrigatório**: cada domínio precisa do
seu bloco de mapping), `.env` (`DJEN_BASE_URL=https://comunicaapi.pje.jus.br`, sem chave — API pública),
`src/DataFixtures/PermissionFixture.php` (`modules.djen.view`), `src/Entity/Notificacao.php`
(`TIPO_DJEN_PUBLICACAO` + ícone), `src/Tenant/UseCase/PurgarEscritorioUseCase.php` (2 tabelas na purga),
`tests/Auditoria/Functional/AuditavelCoberturaTest.php` (allowlist), `templates/_sidebar.html.twig` (item DJEN).
**Nova dependência:** `symfony/html-sanitizer` (composer.json/lock) + `config/packages/html_sanitizer.yaml`
(sanitizador nomeado `djen`) — usados pelo `FormatadorTeorDjen` para exibir o teor com formatação segura
(HTML sanitizado quando o CNJ envia HTML; quebras heurísticas por rótulos/dispositivo quando é texto corrido).

**Migration:** `app/migrations/Version20260706195821.php` — cria `oab_monitorada` + `publicacao_djen` +
insere `modules.djen.view` (idempotente). **Já aplicada em dev e no banco de teste** via
`doctrine:migrations:execute --up` (o dev tem 2 migrations-fantasma pré-existentes — Ponto/`20260401000000`
e `20260408180237` — NÃO rodar `migrations:migrate` cego, quebra em "table exists").

### ⬜ Pendente (retomar por aqui)
1. **Re-revisão CONCLUÍDA** (2026-07-06): as 6 correções estão corretas, sem regressão/brecha. Fechado o
   gap de segurança apontado (teste own-tenant + token inválido → sem mutação; + happy-path token válido).
   Resíduo OPCIONAL (baixo): nenhum teste assera os valores projetados (`lida`/`avulsa`/data) de
   `listarItensDoTenant` — impacto cosmético, não isolamento. Fechar quando conveniente.
2. **Commit** (decisão do humano). ⚠️ A working tree mistura 3 frentes: (a) DJEN inteiro [esta feature],
   (b) um refactor pré-existente do Datajud (`Processo/Enum/MotivoFalhaDatajud`, `Processo/Exception/`,
   `ProcessoController`, `DatajudClient`, testes Processo — **NÃO é desta sessão**), (c) branch chamada
   `gerenciador-arquivos-pasta` (terceira frente). **Recomendo commitar o DJEN isolado** (os arquivos
   `app/src/Djen/**`, `app/tests/Djen/**`, `app/templates/djen/**`, a migration, a spec, e as 8 edições de
   integração listadas acima). Montar os comandos git e entregar ao humano (bloco `# Execute manualmente`).
3. **Deploy em prod** (`./scripts/deploy-prod-tls.sh` na VPS — rebuild, entrypoint roda migrations). Antes:
   adicionar `DJEN_BASE_URL=https://comunicaapi.pje.jus.br` ao `.env.prod` em `/opt/jusprime` (a API é
   pública, sem chave).
4. **Cron da sincronização** (crontab no host, espelha a purga):
   `0 5 * * * docker exec jusprime_php_prod php bin/console app:djen:sincronizar >> /var/log/jusprime-djen.log 2>&1`
5. **Smoke em prod:** cadastrar uma OAB real, "Sincronizar agora", conferir publicações + notificação.

### Correções pós-review (já aplicadas)
- Dedup unificado (`djenIdDoItem` == `djen_id` gravado) → evita crash latente de unique.
- Controller: busca tenant-safe ANTES do CSRF nas ações de escrita → 404 cross-tenant determinístico (guard
  IDOR provado por teste); mutação continua atrás do CSRF.
- `ehTransitorio()` passou a ser usado: falha permanente (config/resposta inválida) → comando sai FAILURE
  (alarma o cron); transitória (429/ocupado) → SUCCESS (recuperado no próximo run). Spec reconciliada.
- `NotificadorPublicacoesDjenInterface` extraída (notifier voltou a `final`).
- Listagem projetada por DQL (`listarItensDoTenant` + `PublicacaoDjenListaItem`) — não hidrata `texto`/`payloadDjen`.
- Testes adicionados: Remover/Alternar UseCases, paginação multi-página, dedup cross-OAB, purga DJEN, exit-code.

### Follow-ups aceitos (não bloqueantes) — ver seção própria no fim
"Sincronizar agora" sem lock (corrida → rollback seguro); `show` marca lida em GET; sync on-demand síncrono.

---

### Contexto da API (confirmado por engenharia reversa 2026-07-06 — ver memória `reference_djen_api` + `DJEN_API.md`)
`GET https://comunicaapi.pje.jus.br/api/v1/comunicacao` é **público (sem auth)**; filtra por `numeroOab`+`ufOab`,
`dataDisponibilizacaoInicio/Fim`, `siglaTribunal`; devolve `{status,message,count,items[]}`; rate limit 20/IP;
teto 10.000; sob carga → `500 "O sistema está muito ocupado"`. Template da feature = captação **Datajud**
(`DatajudClient`/mapper/command no domínio Processo).

---

## 📋 Visão Geral

**Problema.** Escritórios precisam saber, todo dia, quais **intimações/publicações** saíram no Diário de
Justiça Eletrônico Nacional para os advogados (OABs) do escritório — sem consultar manualmente o portal do
CNJ. Hoje o JusPrime só puxa **metadados de processo** (Datajud); não capta **publicações**.

**Solução.** Um domínio novo `App\Djen` que:
1. deixa cada escritório **cadastrar as OABs a monitorar** (`OabMonitorada`);
2. **capta as publicações** do DJEN para essas OABs (client HTTP desacoplado, espelhando o Datajud);
3. **persiste** as publicações por tenant, **vinculando ao Processo** quando o número CNJ já existe;
4. **notifica** os usuários do escritório (evento interno → `NotificacaoService`);
5. roda **por cron** (comando idempotente) e também **sob demanda** (botão na tela).

**Decisões de produto (aprovadas pelo usuário em 2026-07-06):**
- **OABs:** entidade dedicada por escritório (`OabMonitorada`), não derivar de `User.oab*`.
- **Gatilho:** comando agendado por cron-no-host **+** botão "Sincronizar agora".
- **Sem processo cadastrado:** persistir a publicação mesmo assim (fica **avulsa**); vincular se o número casar.
- **Notificar:** **todos** os usuários do escritório com acesso ao módulo `djen`.

---

## 🧱 Estado atual (o que já existe) vs. Gap (o que falta)

| Capacidade | Hoje | Gap (a construir) |
|---|---|---|
| Client HTTP p/ API CNJ | `DatajudClient` (modelo) | `DjenClient` (novo, modernizado) |
| Classificação de falha | `MotivoFalhaDatajud` + `ConsultaDatajudException` | `MotivoFalhaDjen` + `ConsultaDjenException` |
| Persistência por tenant | `Processo` (TenantAware) | `OabMonitorada`, `PublicacaoDjen` (TenantAware) |
| Vínculo por número CNJ | `ProcessoRepository::findByNumeroProcessoDoTenant` | reusar (read-only) |
| Comando multi-tenant CLI | `SeedFeriados`/`PurgarDadosExpirados` (modelo) | `SincronizarDjenCommand` |
| Notificação in-app | `NotificacaoService` + entidade `Notificacao` | novo tipo `TIPO_DJEN_PUBLICACAO` (aditivo) |
| Módulo de permissão | catálogo (`PermissionFixture` + migration) | novo módulo `djen` |
| Config de integração | `bind` + `.env` (Datajud) | `DJEN_BASE_URL` (sem API key — API é pública) |

**Arquivos-âncora de referência (todos existentes):**
- `app/src/Processo/Service/DatajudClient.php` · `DatajudProcessoMapper.php` — template client+mapper
- `app/src/Processo/Enum/MotivoFalhaDatajud.php` · `app/src/Processo/Exception/ConsultaDatajudException.php`
- `app/src/Command/PurgarDadosExpiradosCommand.php` (final, `--dry-run`/`--force`, flock, `em->clear()` por tenant)
- `app/src/Command/SeedFeriadosNacionaisCommand.php` (loop `tenantRepository->findAll()` + `processarTenant`)
- `app/src/Processo/Entity/Processo.php` (PK int, TenantAware, unique `tenant_id`+X, jsonb) · `ProcessoRepository.php`
- `app/src/Shared/Contract/TenantAware.php` · `app/src/Shared/Doctrine/Filter/TenantFilter.php`
- `app/src/Entity/Notificacao.php` (const `TIPO_*` + `getIcone()`) · `app/src/Service/NotificacaoService.php`
- `app/src/Tenant/UseCase/CriarEscritorioUseCase.php` · `app/src/Tenant/DTO/CriarEscritorioInput.php` (padrão UseCase/DTO)
- `app/src/Cliente/Controller/ClienteController.php` (padrão controller + `canAccessModule`)
- `app/src/Auth/Service/ValidadorOab.php` · `app/src/Auth/Enum/StatusOab.php` (reuso p/ validar OAB)
- `app/config/services.yaml` (bind) · `app/templates/_sidebar.html.twig` (menu)

---

## 🚀 Jornadas do Usuário

**A) Configurar OABs monitoradas.** Advogado/gestor abre *DJEN → OABs monitoradas*, cadastra `numero`+`uf`
(+ apelido opcional). Sistema valida formato (reusa `ValidadorOab`) e impede duplicata no escritório.

**B) Captação automática (cron).** Toda madrugada o cron dispara `app:djen:sincronizar --force`. Para cada
escritório, para cada OAB ativa, consulta o DJEN da data de referência; grava as publicações novas
(deduplica); vincula ao Processo se existir; notifica os usuários do módulo.

**C) Captação sob demanda.** Na tela *DJEN*, o usuário clica **"Sincronizar agora"** → capta as publicações
do dia **apenas do escritório atual** (`TenantContext`), mostra flash com o resultado.

**D) Consultar publicações.** Tela *DJEN* lista as publicações do escritório (paginado, filtro por data /
tribunal / vinculada-ou-avulsa). Abrir uma publicação mostra o teor (HTML sanitizado) e o processo vinculado
(se houver).

**E) Receber notificação.** Ao capturar publicações novas, cada usuário do escritório com acesso ao módulo
recebe uma notificação in-app ("N novas publicações no DJEN") com link para a tela filtrada.

---

## 🛠 Regras de Negócio (RN) & Regras de Sistema (RS)

### Identidade & dados
- **RN01 — OAB monitorada é por escritório.** `OabMonitorada` é `TenantAware`; unique `(tenant_id, numero, uf)`.
  Número armazenado em **dígitos** (sem pontuação). Reusar `ValidadorOab` para normalizar/validar.
- **RN02 — Publicação é por escritório.** `PublicacaoDjen` é `TenantAware`; deduplicada por
  **`(tenant_id, djen_id)`** (o `id` da comunicação no DJEN). Guarda o item bruto da API em **jsonb**
  (`payloadDjen`) — igual ao `datajudRaw` do Processo (auditoria + campos futuros).
- **RN03 — Vínculo com Processo.** Ao gravar, normaliza `numero_processo` para dígitos e busca
  `ProcessoRepository::findByNumeroProcessoDoTenant($digitos, $tenant)`. Se achar, seta `processo`
  (FK int, `onDelete: SET NULL`); se não, `processo = null` (**publicação avulsa**). **Nunca cria** Processo.
- **RN04 — Origem rastreável.** Cada publicação referencia a `OabMonitorada` que a trouxe (FK nullable),
  para o usuário saber por qual OAB ela entrou.

### Comportamento de sistema
- **RS01 — Client desacoplado.** `DjenClient` depende de `HttpClientInterface` (contrato) + `DJEN_BASE_URL`
  via `bind`. Sem API key (API pública). `final`, `declare(strict_types=1)`, timeout explícito nas options.
- **RS02 — Classificação de falha.** Espelha o Datajud: try/catch de transporte (`Timeout` antes de
  `Transport`; `Decoding|JsonException` → resposta inválida) + `match` sobre `getStatusCode()`/`toArray(false)`.
  Enum `MotivoFalhaDjen`: `NaoConfigurado`, `Indisponivel`, `Timeout`, `LimiteExcedido` (429),
  `SistemaOcupado` (500 "sistema muito ocupado", **retentável**), `RespostaInvalida`. `statusHttp()` evita 503.
  Trata também o envelope `{"status":"error"}` como falha (SistemaOcupado).
- **RS03 — Rate limit respeitado (recuperação idempotente, sem retry no run).** Chamadas **serializadas**
  (uma por vez), `itensPorPagina` fixo e `--dias` pequeno. Ao receber `LimiteExcedido`/`SistemaOcupado`, a
  OAB é registrada como **falha transitória** e a sincronização segue (isola por OAB/tenant); como a
  captação é idempotente (dedupe por `djen_id`), o que falhou é **recuperado na próxima execução diária** —
  **não há retry/backoff dentro do run** (decisão consciente: evita segurar o processo síncrono e
  complexidade não-testável; `MotivoFalhaDjen::ehTransitorio()` classifica). **Nunca** paralelizar por
  múltiplos IPs. Falhas **NÃO transitórias** (config ausente, resposta inválida) fazem o comando sair com
  **exit FAILURE**, para o cron alarmar.
- **RS04 — CLI escreve com TenantFilter OFF.** O comando **itera `tenantRepository->findAll()`**, escopa
  **toda** query por `$tenant` explícito, seta `->setTenant($tenant)` em toda entidade nova, `try/catch` por
  tenant (uma falha não derruba os demais), `em->clear()` por iteração, **flush por tenant**, `flock` contra
  sobreposição. Modelo: `PurgarDadosExpiradosCommand` + `SeedFeriadosNacionaisCommand`.
- **RS05 — Idempotência.** Reexecutar a sync do mesmo dia **não duplica** (dedupe por `djen_id`) e **não
  renotifica** publicações já existentes (notifica só as realmente novas do batch).
- **RS06 — Notificação = evento interno síncrono.** O `SincronizarPublicacoesDjenUseCase`, após persistir as
  novas, resolve **os usuários do tenant com `canAccessModule($u,$tenant,'djen')`** e cria **uma**
  notificação-resumo por usuário (`Notificacao::TIPO_DJEN_PUBLICACAO`, `url` → `push_processual_index`). Não instala
  messenger (não existe no projeto). Reusa `NotificacaoService`.
- **RS07 — Permissão de módulo.** Toda action e o botão de sync checam
  `PermissionChecker::canAccessModule($user, $tenant, 'djen')`. Novo módulo `djen` entra no catálogo.

---

## ⚠️ Casos de Borda & Tratamento de Erros

| Cenário | Tratamento |
|---|---|
| OAB sem publicações no dia | `count=0` → nada gravado, sem erro, sem notificação. |
| DJEN 429 (rate limit) | `LimiteExcedido` (transitório) → registra a falha da OAB e segue; recuperado no próximo run diário. |
| DJEN 500 "sistema muito ocupado" | `SistemaOcupado` (transitório) → registra a falha da OAB e segue; recuperado no próximo run. |
| Falha NÃO transitória (config/resposta inválida) | Registrada; o comando termina com **exit FAILURE** para o cron alarmar. |
| Timeout / rede | `Timeout`/`Indisponivel` → isola por OAB; não derruba o tenant nem o batch. |
| `numero_processo` inválido/ausente na publicação | grava avulsa (não tenta vincular); não quebra. |
| Processo homônimo em outro tenant (CLI, filtro OFF) | busca **sempre** com `tenant` explícito → sem corrupção cross-tenant (achado B1). |
| Publicação já existente | dedupe por `(tenant_id, djen_id)` → ignora, não renotifica. |
| `DJEN_BASE_URL` vazia | `NaoConfigurado` antes de qualquer chamada (guard barato). |
| Certidão indisponível | download responde erro amigável; não quebra a tela. |
| Texto HTML da publicação | sanitizar na exibição (autoescape Twig + `|raw` só de HTML limpo) — nunca renderizar cru sem sanitização. |

---

## 📌 Critérios de Aceite (BDD)

```gherkin
Funcionalidade: Captação de publicações do DJEN por escritório

  Cenário: Sincronização grava publicação nova e vincula ao processo
    Dado um escritório com a OAB 12345/PR monitorada
    E um Processo "50636766220224047000" cadastrado nesse escritório
    E o DJEN retorna uma publicação dessa OAB para esse número
    Quando executo a sincronização do escritório
    Então uma PublicacaoDjen é criada nesse tenant
    E ela fica vinculada ao Processo correspondente
    E os usuários do módulo recebem uma notificação

  Cenário: Publicação de processo não cadastrado fica avulsa
    Dado a OAB 12345/PR monitorada e nenhum Processo com o número da publicação
    Quando sincronizo
    Então a PublicacaoDjen é criada com processo = null (avulsa)

  Cenário: Idempotência
    Dado uma publicação já capturada
    Quando sincronizo o mesmo dia de novo
    Então nenhuma duplicata é criada e nenhuma notificação nova é gerada

  Cenário: Isolamento multi-tenant (inegociável)
    Dado uma PublicacaoDjen do escritório B
    Quando um usuário do escritório A acessa a rota show dessa publicação
    Então recebe 404
    E a listagem do escritório A não exibe publicações do escritório B
    E a publicação de B continua existindo (com o filtro 'tenant' desligado)

  Cenário: CLI não corrompe cross-tenant
    Dado o mesmo número de processo em dois escritórios
    Quando o comando sincroniza ambos
    Então cada publicação é gravada e vinculada ao Processo do seu próprio tenant
```

---

## 🗺 Faseamento (cada fase entrega valor isolado e é revisável)

| Fase | Escopo | Risco | Valor |
|---|---|---|---|
| **F1 — Fundação** | Entidades `OabMonitorada` + `PublicacaoDjen` (TenantAware, PK int, jsonb, uniques) · Repositories (`...DoTenant`) · **migration** (2 tabelas) · módulo de permissão `djen` (fixture + migration) · item no sidebar | MÉDIO | esquema pronto, isolado por tenant |
| **F2 — Client** | `DjenClient` + `DjenPublicacaoMapper` + `MotivoFalhaDjen` + `ConsultaDjenException` + `ConsultaComunicacoesQuery` · config `DJEN_BASE_URL` · **testes unit** (MockHttpClient) | BAIXO | captação testada sem UI |
| **F3 — UseCases** | `AdicionarOabMonitoradaUseCase`, `RemoverOabMonitoradaUseCase`, `SincronizarPublicacoesDjenUseCase` (dedupe + vínculo + notificação) · `TIPO_DJEN_PUBLICACAO` no `Notificacao`/`NotificacaoService` · **testes unit dos UseCases** | MÉDIO | regra de negócio pronta e testada |
| **F4 — Command** | `SincronizarDjenCommand` (`app:djen:sincronizar`, `--dry-run`/`--tenant`/`--dias`, flock, loop multi-tenant re-buscando Tenant gerenciado por iteração) · linha de crontab documentada. Sem `--force`: a sync é aditiva (não destrutiva). | MÉDIO | captação automática |
| **F5 — UI** | `DjenController` (index/show/oabs/new/toggle/sincronizar) · Forms/DTO Input+Output · templates · **testes functional + isolamento cross-tenant** | MÉDIO | uso completo pelo escritório |

### ⚠️ Notas de deploy / dívidas
- **Migration** (F1): cria `oab_monitorada` e `publicacao_djen` + linhas do módulo `djen` no catálogo de
  permissão. Deploy em prod = `deploy-prod-tls.sh` (rebuild; entrypoint roda migrations).
- **Cron** (F4): adicionar linha no crontab do host (espelha a purga), ex.:
  `0 5 * * * docker exec jusprime_php_prod php bin/console app:djen:sincronizar >> /var/log/jusprime-djen.log 2>&1`.
- **`.env`**: bloco `###> djen ###  DJEN_BASE_URL=https://comunicaapi.pje.jus.br  ###< djen ###`.

---

## 🔒 Notas de Segurança / Multi-tenancy

- Ambas as entidades `TenantAware` (filtro automático em request) + métodos de repositório `...DoTenant`
  (para `find()` por PK, que o filtro não cobre) — **guard IDOR fechado**.
- Comando CLI: filtro OFF → **escopo explícito por tenant** em toda query e criação (RS04). Precedente B1.
- `show`/`certidao`/`delete` buscam por `findOneByIdDoTenant($id, $tenant)` → cross-tenant = 404.
- Notificação sempre `setTenant()` do tenant da publicação; destinatários só do próprio `UserTenant`.
- **Teste de isolamento cross-tenant obrigatório** (3 ângulos: show 404, index não vaza, entidade persiste
  com filtro desligado) — modelo `ProcessoIsolamentoControllerTest`.

## 🔗 Dependências & coordenação
- **Read-only** sobre `App\Processo` (`findByNumeroProcessoDoTenant`, `TribunalCnjResolver`). Não altera Processo.
- **Aditivo** sobre `Notificacao`/`NotificacaoService` (novo tipo + ícone; não altera comportamento existente).
- Sem novas libs (sem messenger/scheduler) — mantém a stack minimalista consciente do projeto.

## ✅ Decisões registradas
- OAB monitorada = entidade dedicada por tenant (não reusar `User.oab*`). · Sync = cron + botão. ·
  Sem processo = persistir avulsa. · Notificar = todos com acesso ao módulo. · Vínculo por `(tenant, numero_digitos)`.
- PK **int** (padrão do projeto, não UUID). · Sem messenger (evento interno = chamada síncrona no UseCase).
- **Auditoria não expandida** (mesma decisão de Processo/Cliente): ambas as entidades em `NAO_AUDITAVEIS`.
- **Purga:** `publicacao_djen` e `oab_monitorada` na `ORDEM_DELECAO` do `PurgarEscritorioUseCase` (FK→tenant NO ACTION).
- **Desacoplamento/testabilidade:** cliente e notificador atrás de interface (`DjenClientInterface`,
  `NotificadorPublicacoesDjenInterface`). · **Listagem projetada** por DQL (não hidrata `texto`/`payloadDjen`).
- **Rate limit:** sem retry/backoff no run; recuperação idempotente no próximo run; falha permanente → exit FAILURE.

## ⏭ Follow-ups aceitos (não bloqueantes)
- **"Sincronizar agora" (request síncrono) sem lock:** uma corrida com o cron/duplo-clique pode colidir no
  unique `(tenant_id, djen_id)` → rollback seguro (nada gravado/corrompido, recuperável no próximo run).
  Mitigação (lock ou execução assíncrona) fica para depois. Também: sync on-demand pode demorar com muitas OABs.
- `show` marca `lida=true` em GET (benigno, dado do próprio tenant).

## ⏭ Fora de escopo (v1)
- Download da **certidão em PDF** (`/comunicacao/{hash}/certidao`) — a API suporta; adiado (guardamos o `hash`).
- Download do **caderno** diário (.zip) — a API suporta, mas não é necessário para captação por OAB.
- Busca por texto/parte/CPF no DJEN (a API pública não oferece filtro por CPF; e texto é pesado/500).
- E-mail/push das publicações (só in-app nesta versão; Mailer existe se virar requisito).
- Entidades filhas de destinatários/advogados (guardadas no jsonb bruto; podem virar tabelas depois).
- Derivar OABs automaticamente dos usuários (decisão foi entidade dedicada).

## 🧪 Alvo de revisão
Após cada fase: `/review` (feature-review-agent, read-only) contra esta spec + suíte verde no container.
Antes de fechar: `tenant-safety-review` e o teste cross-tenant obrigatório. Em risco MÉDIO, re-revisar F1/F3/F5.
