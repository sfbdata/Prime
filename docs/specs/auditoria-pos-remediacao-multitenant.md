# Auditoria adversarial pós-remediação multi-tenant — JusPrime/BlueJus

> Varredura adversarial read-only (jun/2026) **depois** que toda a remediação (P0–P2.3, C1–C3,
> C4a/b/c, C5.1) foi deployada em produção (bluejus.com.br, commit `16fc10d`, 1 tenant).
> Objetivo: **quebrar** o isolamento entre escritórios — achar o que foi esquecido/errado, com PROVA.
> Método: 12 dimensões fan-out (workflow `wf_b6c2c373-42b`, 44 agentes) → refutação adversarial por
> achado → dedup → crítico de completude. **Cada achado abaixo foi re-verificado pelo orquestrador**
> lendo o código literal / rodando query no banco — não é resumo de subagente.
> Risco residual classificado pela régua do projeto: **qualquer vazamento/corrupção cross-tenant = ALTO/CRÍTICO**.

---

## Resumo executivo

**A remediação funcionou no que mais importa, mas o sistema ainda NÃO está 100% pronto para muitos
escritórios.** O plano de dados das 23 entidades de negócio TenantAware (Cliente, Processo, Pasta,
Tarefa, Agenda, Ponto, ServiceDesk, Marcador) está sólido: o `TenantFilter` cobre `findAll/findBy/find()`
(IDOR por id vira 404 automático), **todas as 23 tabelas têm índice em `tenant_id`**, os caminhos que
escapam do filtro (SQL nativo, bulk DQL) estão escopados, e as entidades não-TenantAware de admin
(Sede, TenantRole, AccessRequest) ganharam guards explícitos. **Não há vazamento catastrófico do
core de negócio alcançável por um atacante comum.** Porém sobraram leaks cross-tenant nas **bordas**:
(1) os dropdowns de usuários (ServiceDesk/Expediente) listam **todos os usuários de todos os escritórios**
— o problema mais claro de "pronto para N escritórios"; (2) notificações de usuário multi-tenant vazam
entre sessões de escritórios; (3) o `PecaImagemController` serve imagem de peça sem checar tenant. Há
ainda uma **brecha de CSRF no domínio ALTO (ponto eletrônico)** — o formulário de lançamento manual de
ponto tem `csrf_protection => false`, gap que a frente C4 não pegou — e uma **migration (Agenda) que
aborta ao migrar um banco multi-tenant**. Por fim, **a suíte está vermelha (827/829)** e **não roda pelo
comando documentado** (estoura `memory_limit=128M`), então a alegação "829/829 verde" não se sustenta
neste ambiente. Nenhum desses é o "P0 catastrófico" de antes; são bordas e dívidas que devem ser
fechadas antes de escalar para muitos escritórios.

---

## Achados rankeados por severidade

### 🔴 CRÍTICO
Nenhum. Não há vazamento/corrupção cross-tenant do core de negócio (clientes, processos, pastas,
tarefas, agenda, ponto) alcançável por usuário comum — o `TenantFilter` + índices + guards fecham essa
classe. Os achados cross-tenant remanescentes são de borda (nomes de usuário, notificações, imagem de
peça) ou exigem super-admin/usuário multi-tenant — todos ALTO ou abaixo.

---

### 🟠 ALTO

#### A1 — Vazamento cross-tenant do diretório de usuários (ServiceDesk + Expediente)
**Prova:** `app/src/Controller/ServiceDeskController.php:102` e `:298`; `app/src/Expediente/Controller/ExpedienteController.php:203`.
`User` **não é TenantAware** (`app/src/Entity/Auth/User.php`), logo `findBy()` não é filtrado.
- `:102` (index do ServiceDesk): `$tecnicos = $userRepository->findBy(['isActive' => true], ['fullName' => 'ASC'])` — popula o dropdown de técnicos com **todos os usuários ativos da plataforma**, de todos os escritórios.
- `:203` (Expediente `pastasPorMarcador`/`acervoGeral`): `'responsaveis' => $this->userRepository->findBy(['isActive' => true], ...)` — idem, no filtro de responsável.

**Vetor:** um admin/usuário do escritório A com acesso a ServiceDesk ou Expediente abre a tela e vê, no
dropdown, o **nome completo de todos os usuários de todos os escritórios** do SaaS. Com muitos
escritórios, isso expõe o diretório inteiro de pessoas (advogados, funcionários) entre firmas
concorrentes. **Variante de escrita (`:298`):** `atribuir` faz `$userRepository->find($responsavelId)`
sem validar tenant — um admin de A pode atribuir um chamado de A a um `userId` de B; `setResponsavel`
persiste e a notificação de atribuição é enviada ao usuário de B (vaza título/URL do chamado de A para B).
**Fix:** trocar os `findBy(['isActive'=>true])` por uma query escopada por tenant (ex.:
`UserRepository::findColaboradoresAtivosPorTenant($tenant)`, que já existe e foi usada na Agenda/S4);
em `atribuir`, validar que o responsável tem vínculo ativo no tenant antes de `setResponsavel`.

#### A2 — CSRF desabilitado no lançamento/edição **manual de ponto** (domínio ALTO)
**Prova:** `app/src/Ponto/Form/RegistroPontoManualType.php:54` → `'csrf_protection' => false`. Usado em
`app/src/Controller/TenantController.php:741` (`pontoAdd`, rota `app_tenant_user_ponto_add`, persist em :762)
e `:826` (`pontoEdit`). **Nenhum** dos dois faz `isCsrfTokenValid` de reserva (verificado: as ações irmãs
`pontoDelete:909`, `aprovarJustificativa:971`, `rejeitar:1065` etc. checam CSRF; add/edit **não**).

**Vetor:** ponto eletrônico é classificado **ALTO** pelo próprio projeto (registro trabalhista/legal). Um
atacante induz um admin logado (`admin.users.manage`) a submeter um formulário cross-site forjado →
cria/edita batidas de ponto de um funcionário sem consentimento. **A frente C4 (CSRF) endureceu
`ponto_batida` mas esqueceu este formulário** — é exatamente o tipo de gap que a remediação deveria ter
fechado. **Fix:** remover `'csrf_protection' => false` do form (ou adicionar `isCsrfTokenValid` explícito
em `pontoAdd`/`pontoEdit`, como nas ações irmãs).

#### A3 — Suíte vermelha (827/829) e impossível de rodar pelo comando documentado
**Prova (verificada empiricamente):**
- 2 falhas em `app/tests/ServiceDesk/Functional/AnexoDownloadControllerTest.php` (`testDonoBaixaAnexo`,
  `testAdminDoMesmoTenantBaixa`). Causa-raiz: o revert `16fc10d` apontou `chamados_uploads_dir` para
  `public/uploads/chamados` (`config/services.yaml:17`); no container o usuário é `uid 1000` e
  `public/uploads` é `uid 33 / mode 775` → o teste não consegue `mkdir`/escrever o arquivo
  (`Permission denied`, confirmado por `mkdir` manual) → controller `existe()` falso → 404.
- O comando documentado `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'` **estoura
  `memory_limit=128M`** (default do container; `phpunit.xml.dist` não eleva) com `Fatal error: Allowed
  memory size exhausted` antes de terminar. Só completa com `-d memory_limit>=512M`.

**Vetor / impacto:** o revert foi commitado **sem rodar a suíte completa** (a alegação "829/829 verde" não
se sustenta neste ambiente). Uma suíte que não roda como documentado e está vermelha **mascara regressões
futuras** — exatamente o risco que esta auditoria existe para evitar. **Fix:** (a) tornar o diretório de
upload gravável no ambiente de teste/dev (ou usar `var/uploads` só em teste, ou ajustar dono/permissão de
`public/uploads/*`); (b) elevar `memory_limit` no `phpunit.xml.dist`/php-cli do container; (c) re-rodar a
suíte e travar verde antes de qualquer commit.

---

### 🟡 MÉDIO

#### M1 — CSRF ausente em ServiceDesk `atribuir` e `status` (POST de formulário legado)
**Prova:** `app/src/Controller/ServiceDeskController.php:286` (`servicedesk_atribuir`) e `:334`
(`servicedesk_status`) leem `$request->request->get(...)` diretamente (`:297`, `:345`), sem Symfony Form,
sem `isCsrfTokenValid`, sem `ValidaCsrfAjaxTrait`. **Vetor:** CSRF muda responsável/status de chamado de
qualquer admin induzido. A frente C4 cobriu AJAX/JSON e forms Symfony, mas estes dois POSTs legados
(form HTML cru) escaparam da enumeração. **Fix:** adicionar token CSRF nos `<form>` de `show.html.twig` +
`isCsrfTokenValid` nas duas ações.

#### M2 — `AccessRequest` sem coluna `tenant`: admin do escritório errado aprova/nega
**Prova:** `app/src/Entity/Permission/AccessRequest.php` (sem `tenant`); `AccessRequestRepository::findPendingByTenant`
escopa só por JOIN `UserTenant` (vínculo do solicitante); `AccessRequestController::assertBelongsToAdminTenant:57`
valida apenas `existeVinculoAtivo(solicitante, tenantDoAdmin)`. **Vetor:** usuário U membro de A **e** B cria
uma solicitação (sem tenant; `resourceId` é global). Ela aparece no painel de **ambos**. O admin de B aprova
(o guard passa porque U tem vínculo em B), criando um `ResourceAccess(U, tipo, resourceId)` para um recurso
que pode ser de A — concessão por um admin sem autoridade sobre aquele recurso. **Limitador (verificado):** o
`ResourceAccess` só vira acesso efetivo quando U opera no tenant **dono** do recurso, onde o `TenantFilter`
ainda gateia o load — então não é bypass do filtro, é violação de **autoridade de concessão** entre
escritórios que compartilham um usuário. **Fix:** adicionar `tenant` em `AccessRequest` (preenchido no
`submit` via `tenantContext`), filtrar `ar.tenant = :tenant` em `findPendingByTenant` e checar
`accessRequest.getTenant() === $tenant` em `approve`/`deny`.

#### M3 — Kanban: BAC horizontal intra-tenant (membership do board não checado em sub-recursos)
**Prova:** `KanbanChecklistController.php:79-150` (excluir/criar item/toggle/excluir item),
`KanbanMarcadorController.php:76-113` (editar/excluir), `KanbanAnexoController.php:80,99` (servir/excluir).
Essas ações validam o **tenant** do board (`getCard().getBoard().getTenant() !== $tenant`, ou
`findPorTenantEId`) — então **não é cross-tenant** — mas **não chamam `temAcesso($user)`** (membership do
board), ao contrário de `upload`/`criar` e dos UseCases de card. **Vetor:** dentro do mesmo escritório, um
usuário que **não é participante** de um board privado consegue baixar anexo (`/kanban/anexo/{id}`),
ler/alterar checklist, itens e marcadores desse board, bastando o id (enumerável). **Fix:** adicionar
`!$entidade->...->getBoard()->temAcesso($user)` → 404 nessas ações, igual às demais.
*(Nota: o follow-up conhecido `kanban_card_mover` foi VERIFICADO e está fechado — `MoverCardUseCase`
checa `temAcesso` e escopa a coluna destino via `findPorBoardEId($board)`.)*

#### M4 — `Notificacao` não-TenantAware: usuário multi-tenant vê notificações de todos os escritórios
**Prova:** `app/src/Entity/Notificacao.php` (só FK `usuario`/`tarefa`, sem `tenant`; tabela `notificacao` só
tem `usuario_id`); `NotificacaoRepository::findNaoLidasByUsuario:42`, `findPaginadasByUsuario:75`,
`countNaoLidasByUsuario` filtram **só** por `n.usuario`. **Vetor:** usuário membro de A e B, logado em B, vê
no sino notificações geradas no contexto de A (título/URL de tarefa, chamado, etc.) — vazamento cross-tenant
de metadados para o próprio usuário multi-tenant. **Fix:** adicionar `tenant` em `Notificacao` (gravado na
criação) e escopar as queries por tenant da sessão.

#### M5 — `PecaImagemController`: imagem de peça servida sem checar tenant/posse (residual conhecido — confirmado)
**Prova:** `app/src/Pasta/Controller/PecaImagemController.php:41-54` — rota `GET /uploads/pastas/{nome}`,
**só** autenticação (firewall `^/ ROLE_USER`), sem tenant. Anti path-traversal OK (`basename` + regex).
**Vetor:** qualquer usuário logado (de qualquer escritório) baixa a imagem de peça de qualquer pasta **se
souber o nome** (hex aleatório de 16 bytes = 128 bits → **não enumerável**). Exposição real apenas se o nome
vazar (HTML de peça compartilhado/exportado, referer, logs). É o resíduo aceito conscientemente quando
C5.2/3/4 foram cancelados. **Fix (deferido):** servir a imagem por entidade (validando tenant/posse da
pasta) em vez de por nome de arquivo.

#### M6 — F1: `show/edit/delete` de Cliente/Pasta/Processo não checam o módulo (escalada intra-tenant)
> **✅ RESOLVIDO (2026-06-30) — ACEITO POR DESIGN, sem mudança de código.** Decisão do dono (brainstorming):
> modelo **paralelo** — o `ResourceAccess` é concedido só por admin e só sobre recurso do próprio tenant
> (`recursoPertenceAoTenant` + `ResourceAccess` TenantAware), então o **grant explícito JÁ é a autorização**
> do item; o módulo gateia só a descoberta/listagem. F1 rebaixada de "crítica" a comportamento aceito.
> Documentado em `docs/AUTORIZACAO.md` §7 (reescrita) e §F1 (rebaixada); §5b/§F3 corrigidas (Processo agora wired).
**Prova:** `ClienteController.php:204,224`; `ProcessoController.php:139,168,219`; `PastaController` (show/editar/delete) —
chamam só `denyResourceAccessUnlessGranted` → `canAccessResource` (`ResourceAccessTrait.php:23`), nunca
`canAccessModule`. **Vetor:** Cliente/Pasta/Processo **são TenantAware** → cross-tenant já fecha (find→null→404);
o resíduo é **intra-tenant**: um usuário com `ResourceAccess` para um item mas **sem** `modules.*.view`
acessa o item por URL direta sem ter o módulo. É a Falha F1 do `AUTORIZACAO.md`, mitigada (não eliminada)
pelo filtro. **Fix:** decidir o modelo sequencial (módulo ⇒ recurso) e checar `canAccessModule` também nas
item-actions, ou documentar como aceito.

#### M7 — Migration da Agenda aborta ao migrar um banco **multi-tenant**
> **✅ RESOLVIDO (2026-06-30) — ACEITO/VERIFICADO, sem mudança de código.** O abort é o **mesmo freio
> consciente** dos outros 8 backfills (single-tenant fallback) e só ocorre num banco multi-tenant
> pré-existente com legendas globais (não acontece: deploy do zero = tabela vazia = `NOT NULL` em 0
> linhas OK; prod = 1 tenant = fallback resolveu). **2º tenant verificado SEGURO:** legendas criadas sob
> demanda com `setTenant` (`AgendaController::salvarLegendas:499`), `TenantBootstrapService` não faz seed
> de legenda, agenda renderiza com zero legendas. Documentado em `DEPLOY-PROD-multitenant.md` (§Riscos).
**Prova:** `app/migrations/Version20260625200952.php:50-56` — `legenda_cor` não tem dono/autor, então o
backfill de `tenant_id` depende **exclusivamente** do fallback de tenant único
(`WHERE ... AND (SELECT COUNT(*) FROM tenant) = 1`). Em banco com >1 tenant e legendas existentes, o UPDATE
afeta 0 linhas → `ALTER COLUMN tenant_id SET NOT NULL` (`:56`) **aborta**. **Vetor:** funcionou em prod (1
tenant, tabela vazia), mas qualquer ambiente multi-tenant que rode essa migration do zero quebra. Padrão
parecido (sem âncora) é dívida de robustez. **Fix:** na duplicação por-tenant das legendas (seed) decidir o
dono; ou tornar a coluna nullable até o seed; documentar no runbook.

#### M8 — Revert de uploads (`16fc10d`) quebra escrita de cliente/justificativa/chamado em DEV
> **✅ RESOLVIDO (2026-06-30).** Causa-raiz confirmada no container: roda como **uid 1000**, mas
> `public/uploads/{clientes,justificativas,perfil}` eram **uid 33 (www-data da img prod), modo 755** →
> uid 1000 não escrevia (e `chamados` nem existia). **Test já estava OK** (A3 → `var/uploads-test/*`).
> **Fix aplicado no DEV:** `chown -R 1000:1000 public/uploads` (via root no container; reflete no host pelo
> bind-mount) + `mkdir chamados` → os 6 dirs ficaram graváveis (verificado). Documentado no root `CLAUDE.md`
> (§Docker, troubleshooting de permissão). Prod inalterado (volume `uploads_prod` é www-data-gravável).
**Prova:** `config/services.yaml:16-18` aponta `clientes`/`justificativas`/`chamados` para `public/uploads/*`.
Em DEV/teste o container roda como `uid 1000`, mas `public/uploads` e subpastas são `uid 33 / mode 755-775`
→ não graváveis (confirmado por `mkdir` → `Permission denied`). **Vetor:** além das 2 falhas de teste (A3),
qualquer upload novo de cliente/justificativa/chamado em DEV falha silenciosamente. Em **prod** funciona
(volume `uploads_prod` é `www-data`-gravável), então é um problema de ambiente dev/teste, mas mascara
regressões. **Fix:** alinhar dono/permissão de `public/uploads/*` ao usuário do container, ou usar `var/`
em ambiente de teste.

---

### 🔵 BAIXO

- **B1 — CLI Datajud `findOneBy` cross-tenant.** `AtualizarProcessoDatajudCommand.php:77` e
  `DatajudProcessoMapper.php:60` fazem `findOneBy(['numeroProcesso'=>...])` sem tenant; no CLI o filtro está
  OFF e `numero_processo` só é único **por** tenant → pode casar/atualizar processo de outro tenant. **Só por
  console** (não-web); prod tem 1 tenant. Robustez. **Fix:** escopar pelo tenant de `$processoBase`.
- **B2 — `NotificacaoRepository::removerAntigas:164`** — bulk DELETE global por data, **sem chamador**
  (código morto). Risco só futuro (se plugado a cron sem escopo). **Fix:** remover ou exigir escopo.
- **B3 — `ResourceAccess` sem `tenant`** (`Entity/Permission/ResourceAccess.php`) — grant identificado só por
  `(user, resourceType, resourceId)` global. Não vaza hoje (filtro gateia o load); defesa-em-profundidade.
- **B4 — `audit_log` sem índice em `tenant_id`** (única das tabelas com `tenant_id` sem índice — verificado no
  catálogo pg). Seq scan na tela de auditoria com N escritórios. `AuditLog` não é TenantAware, mas as queries
  de timeline **filtram `tenant_id` manualmente** (verificado), então é só escala. **Fix:** criar índice.
- **B5 — Frestinha super-admin (resíduo).** Com super-admin **sem tenant na sessão** (filtro OFF):
  `TenantController::downloadAnexoJustificativa:1408` e `listUsers:311` **não** chamam `escoparFiltroNoTenant`
  (ao contrário de editUserRole/aprovar/rejeitar) → servem atestado/labels cross-tenant. Só super-admin
  (conta do dev). `FeriadoController`/`JornadaTenantController` sob `{tenantId}` não foram mapeados linha-a-linha
  (mesma classe). C6 está BLOQUEADO por decisão de produto.
- **B6 — Permissões fantasma.** `admin.tarefas.manage` (sidebar/form, zero controller) e `canActOnResource`
  (`PermissionChecker.php:64`, nunca chamado) — código/permissão morta. Higiene.
- **B7 — `DemandasController:23` sem guard de módulo** — confirmado, mas **sem vazamento** (o UseCase filtra por
  `tenant` + responsável). Inconsistência de autorização, não leak.
- **B8 — `ProcessoController::datajudSearch:233` (POST /processos/api/search) sem guard de permissão** — só
  autenticado; chama API externa CNJ e monta `Processo` efêmero (não persiste). Sem leak; risco de abuso/custo.
- **B9 — Migration ServiceDesk `Version20260625120342` sem fallback de tenant único** — backfill só via
  `solicitante → user_tenant` ativo; aborta se algum solicitante não tiver vínculo ativo. Robustez de migration.
- **B10 — cpf/cnpj sem trava no banco** (`Version20260626150000` só dropou os uniques globais) — unicidade
  intra-tenant depende 100% do app (`#[UniqueEntity]`); corrida/caminho fora do form pode duplicar. Custo
  aceito em C3.

---

## Refutados (NÃO são achados — registrados para dar confiança)

- **Convite com email global** (`InvitationService`/`user.email` UNIQUE global) — **correto por design**:
  `User` é a identidade multi-tenant (via `UserTenant`), não uma entidade por escritório; email global é a
  chave de login. Sem leak.
- **Backfill por autor deixaria órfão e abortaria Cliente/Processo/Tarefa em multi-tenant** — o mecanismo é
  real (órfão sem autor aborta o `SET NOT NULL`), mas as migrations **abortam de propósito** (freio
  consciente, documentado) e prod tem 1 tenant; rebaixado a robustez (ver M7/B9), não vulnerabilidade.

---

## Verificado e OK (dá confiança no que não virou achado)

- **Cobertura do `TenantFilter`:** as **23 entidades de negócio** implementam `TenantAware`
  (Cliente/PF/PJ via herança, ClienteDocumento, Processo+3 filhas, Evento, LegendaCor, Chamado, Tarefa,
  TarefaMensagem, Marcador, Pasta+6, Feriado, JornadaTenant, RegistroPonto, JustificativaPonto). O filtro
  cobre `findAll/findBy/find()` (IDOR por id → 404).
- **Índices `tenant_id`:** **todas as 23 tabelas TenantAware** têm índice iniciando por `tenant_id`
  (verificado no catálogo pg). Só `audit_log` (não-TenantAware) falta — B4.
- **Entidades não-TenantAware de admin com guard explícito (verificado):** `TenantRoleController` edit/delete
  (`role.getTenant() === tenant` + URL `{tenantId}` == sessão); `editSede`/`deleteSede`
  (`sede.getTenant()?->getId() !== $tenantId` → 404 + vínculo + permissão).
- **Kanban (8 entidades fora do filtro):** todos os 6 controllers validam a cadeia até `board.getTenant()`
  (`findPorTenantEId`/`findPorBoardEId`/chain). **Sem IDOR cross-tenant.** `kanban_card_mover` fechado. Resíduo =
  membership intra-tenant (M3).
- **SQL nativo / bulk DQL escopados (verificado um a um):** AuditLog timelines (`tenant_id` em cada UNION),
  `FeriadoRepository::findByTenantOrdenado` (`WHERE tenant_id`), `RegistroPontoRepository::desvincularSede`
  (`r.sede AND r.tenant`), `ChamadoRepository` (param `tenantId`), `DemitirFuncionarioUseCase` (C1, escopado),
  `Notificacao` bulk por `usuario`. Único não-escopado = `removerAntigas` (B2, morto).
- **Sem envenenamento de cache cross-tenant:** zero uso de `CacheInterface`/cache pool na aplicação, zero
  memoização estática em services; o `result_cache_driver` do Doctrine está configurado mas **nenhuma query
  o habilita** (`enableResultCache`/`setResultCacheId` = 0 ocorrências).
- **Unique constraints por-tenant onde precisa** (`pasta(tenant,nup)`, `processo(tenant,numero)`, cpf/cnpj
  app-level); as globais (`user.email`, `permission.code`, `invitation.token`,
  `tenant_role_permission(role,permission)`) são corretas. **Sem** unique global em nome de
  board/role/sede/cargo/lotacao (verificado: zero).
- **Resolução de tenant:** `TenantContext.getCurrentTenant()` lê `current_tenant_id` da sessão;
  `setCurrentTenant` exige `UserTenant` ativo; `TenantFilterListener` (prio 5) liga o filtro só em main
  request com o tenant da sessão.

---

## Lacunas de cobertura (honestidade — NÃO verificado a fundo)

1. **N+1 / perfil de performance:** não medi N+1 nas listagens. Os dropdowns globais de usuário (A1) também
   são problema de **escala latente** (carregam todos os usuários da plataforma, sem limite) além do leak.
2. **Frestinha super-admin — mapa completo:** `FeriadoController`, `JornadaTenantController` e outras rotas sob
   `{tenantId}` não foram conferidas linha-a-linha quanto a `escoparFiltroNoTenant` (mesma classe de B5;
   só super-admin sem tenant alcança).
3. **Migrations além das 4 auditadas** (Version20260625183049/192651/200952/203433/212332/103111/120342/150000):
   não reauditei todas; o padrão de fallback de tenant único é fonte de fragilidade (M7/B9).
4. **Chaves de cache/sessão por-tenant:** confirmei ausência de cache de resultado na app; não fiz auditoria
   de eventuais chaves de cache HTTP/edge ou de sessão compartilhada além do `current_tenant_id`.
5. **Smoke logado real com 2+ tenants:** toda a análise é estática + queries no banco de DEV (1 tenant em
   prod). Os vetores cross-tenant (A1, M2, M4) deveriam ser confirmados por um teste/cenário com 2 tenants e
   um usuário multi-tenant antes do fix — recomendado escrever esses testes (hoje inexistentes).

---

## Apêndice — proveniência

Workflow `wf_b6c2c373-42b` (12 dimensões, 44 agentes, ~2,3M tokens): 28 achados brutos → 26 confirmados +
3 do crítico de completude − dedup. **Todos os achados ALTO/MÉDIO acima foram re-verificados pelo
orquestrador** lendo o código literal e/ou rodando query no banco (não confiando no resumo do subagente),
conforme exigido pelo histórico do projeto (ex.: enumeração Haiku perdeu `ponto_batida`; bloco "redundante"
derrubou 9 rotas). Achados de subagente que não passaram na reverificação foram rebaixados ou movidos para
"Refutados".
