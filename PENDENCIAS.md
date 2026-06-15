# PENDÊNCIAS

Catálogo das pendências e dívidas técnicas conhecidas do JusPrime. Atualizar
sempre que uma frente identificar item novo, e remover (com strike + data
de conclusão) quando resolvido.

Cada item tem: categoria → descrição curta + caminho do arquivo + commit/doc
de referência se houver.

---

## 🔴 Segurança / Validação

- **[ALTA] 3 endpoints sem validação de MIME/tamanho de upload** — risco amplificado pelo
  aumento do limite PHP para 65 MB (commit `fa8290c`). Antes implicitamente bloqueados pelo
  limite de 15 MB; agora aceitam qualquer tipo/tamanho até 65 MB.
  - Kanban anexos: `app/src/Kanban/UseCase/AdicionarAnexoUseCase.php:23`
  - Tarefa mensagens: `app/src/Controller/TarefaController.php:349`
  - ServiceDesk: `app/src/Controller/ServiceDeskController.php` (`processarAnexos`)
  - Ref: `IMPORTACAO-ACERVO.md` § "Dívida técnica amplificada"

- **[ALTA] F1 — show/edit/delete de Cliente e Pasta sem check de módulo** — `canAccessResource`
  presente, `canAccessModule` ausente. Usuário com `ResourceAccess` mas sem
  `modules.{clientes,pastas}.view` acessa o item diretamente pela URL. Pasta tem 50+ chamadas —
  superfície ampla. Exposição de dado em produção.
  - `app/src/Cliente/Controller/` · `app/src/Pasta/Controller/`
  - Ref: `REFATORACAO-DOMINIOS.md:126` · `docs/AUTORIZACAO.md` § F1

- **[ALTA] Migration Version20260522180222 sem backfill — bomba-relógio** — adiciona
  `tenant_id INT NOT NULL` em `pasta` e `pasta_documento` sem default nem backfill. Passou em dev
  (tabela vazia); quebra em qualquer banco com `pasta` populada (restore prod→dev, 2º tenant futuro).
  Reescrever: add nullable → backfill com tenant correto → ALTER set NOT NULL.
  - `app/migrations/Version20260522180222.php`
  - Ref: `REFATORACAO-DOMINIOS.md:169`

- **[MÉDIA] 9 UseCases-filha de Pasta sem tenant-check** — mutam/removem sem validar tenant no
  UseCase; protegidos APENAS pelo `canAccessResource` do controller. `ExcluirChecklistItem`,
  `EditarChecklistItem`, `ToggleChecklistItem`, `AlterarPrioridade`, `AlterarSituacaoContrato`,
  `EditarObservacaoDetalhes`, `EditarObservacaoFinanceira`, `EditarPecaTexto`, `ReordenarDocumentos`.
  - `app/src/Pasta/UseCase/`
  - Ref: `REFATORACAO-DOMINIOS.md:155`

- **[BAIXA] Bug latente de normalização do NUP no criar Pasta** — `CriarPastaUseCase` checa
  unicidade com o NUP cru (`trim()`), mas `Pasta::setNup()` armazena `mb_strtoupper(trim())`.
  NUP com minúsculas não encontra duplicata em maiúsculas → `UniqueConstraintViolationException`
  HTTP 500 não tratada.
  - `app/src/Pasta/UseCase/CriarPastaUseCase.php`
  - Ref: `REFATORACAO-DOMINIOS.md:142`

- **[BAIXA] Form de criar Pasta sem CSRF token** — `templates/_partials/modal_nova_pasta.html.twig`
  faz POST `/pasta/nova` sem `_token`; controller nunca validou CSRF. Diverge do guia de templates.
  - `app/templates/_partials/modal_nova_pasta.html.twig`
  - Ref: `REFATORACAO-DOMINIOS.md:143`

- **[BAIXA] PastaSecaoController / MoverDocumentoParaSecaoUseCase sem tenant check explícito** —
  proteção indireta via `canAccessResource` da pasta + seção destino; risco residual baixo.
  Adicionar check `$documento->getTenant()` ao tocar esses arquivos.
  - `app/src/Pasta/Controller/PastaSecaoController.php`
  - `app/src/Pasta/UseCase/MoverDocumentoParaSecaoUseCase.php`
  - Ref: `REFATORACAO-DOMINIOS.md:165`

- **[MÉDIA] getCurrentTenant() não valida vínculo do solicitante** — `TenantContext::getCurrentTenant()`
  lê o tenant da sessão via `em->find(Tenant)` sem confirmar que o usuário autenticado tem
  `UserTenant.isActive = true` nesse tenant. Combinado com o `UserChecker` (que só checa
  `User.isActive`, não `UserTenant.isActive`), um usuário demitido com sessão ainda viva pode
  acessar recursos filtrados por `getCurrentTenant()` — ex: ver fotos de ex-colegas via
  `servirFoto`. O isolamento cross-tenant das queries continua válido; a falha é que o vínculo
  do solicitante não é revalidado por request. Frente própria: revisar `UserChecker` para
  invalidar sessão quando `UserTenant.isActive` vira `false`. Afeta toda rota que usa
  `getCurrentTenant()`, não só a foto.
  - `app/src/Service/Tenant/TenantContext.php`
  - `app/src/Security/UserChecker.php` (frente de correção)

- **[INFO] TODO: notificação de novo chamado no ServiceDesk** — `notificarNovoChamado()` é um
  stub vazio (só log); nenhum alerta chega a admins/equipe TI.
  - `app/src/Controller/ServiceDeskController.php:398`

- **[BAIXA] Fallback onerror de avatar só no dashboard** — o dashboard trata foto quebrada
  (HTTP 404 na rota `app_profile_foto_serve`) com fallback de inicial via `onerror` inline.
  Os mesmos `<img>` em `base.html.twig` (sidebar/dropdown), `profile/index.html.twig` e
  `layout_peticionar.html.twig` não têm `onerror` — exibem ícone de imagem quebrada se a
  foto der 404. Risco baixo (esses contextos mostram a foto do próprio usuário logado, que
  normalmente existe), mas inconsistente. Melhoria: replicar o padrão `onerror` nesses templates.
  - `app/templates/base.html.twig`
  - `app/templates/profile/index.html.twig`
  - `app/templates/layout_peticionar.html.twig`

---

## 🟡 UX / Produto

- **Loop de redirect pós-deploy** — após cada deploy a sessão do usuário quebra (cookies
  estourados); precisa de `/logout` manual ou limpeza de cookies. Reportado por usuários reais.
  Em investigação — próxima frente.
  - Ref: observação de produção

- **Bug B — redirect pós-login cego** — `UserAuthenticator::onAuthenticationSuccess()` redireciona
  fixo para `/expediente` sem checar permissão. Perfil sem acesso a Expediente toma 403 ao logar.
  São 3 pontos de redirect fixo: `UserAuthenticator` (2 branches) + `TenantSelecaoController`
  (~l. 44 e ~57). Correção: redirecionar para a primeira rota de módulo permitida.
  - `app/src/Security/UserAuthenticator.php`
  - `app/src/Controller/TenantSelecaoController.php`
  - Ref: `REFATORACAO-DOMINIOS.md:119`

- **Paginação ausente na listagem de pastas (acervo geral)** — todas as 941 pastas carregam de
  uma vez. Frente própria.
  - `app/src/Expediente/Controller/ExpedienteController.php`
  - Ref: `IMPORTACAO-ACERVO.md:32`

- **[ALTA] Painel AJAX do filtro de pastas quebrado** — `carregarComFiltros` injeta HTML via `innerHTML`;
  `<script>` não executa (padrão HTML5). Na 2ª busca o handler XHR some, cai em navegação normal,
  controller redireciona para `expediente_index` e o filtro desaparece. Correção: mover handlers
  para `index.html.twig` via delegação; remover blocos `<script>` dos partials.
  **Dimensão PII (eleva a ALTA):** quando o handler XHR não religa e o form cai em GET nativo, a
  query string do filtro vai para a barra de endereços, histórico e logs do servidor — e o campo
  `cliente` é texto livre com NOME DE CLIENTE (PII). Logo a falha não é só de UX: expõe PII em
  URL/logs/histórico sempre que o JS falha.
  - `app/templates/expediente/_acervo_geral.html.twig`
  - `app/templates/expediente/_painel_marcador.html.twig`
  - Ref: `REFATORACAO-DOMINIOS.md:132`

- **Exibição dupla de documento em seção (pasta_show)** — ao subir arquivo numa seção nova, o
  documento aparece duplicado na tela (1 vez na seção + 1 na lista principal). Dado não duplicado;
  é bug de agrupamento/exibição no template.
  - `app/templates/pasta/pasta_show.html.twig`
  - Ref: `REFATORACAO-DOMINIOS.md:136`

- **Peticionar não permite escolher seção do anexo** — ao enviar documento pelo Peticionar,
  `secao_id` fica NULL; documento não aparece em nenhuma seção no `pasta_show`. Gap de feature.
  - `app/src/Pasta/` (fluxo Peticionar)
  - Ref: `REFATORACAO-DOMINIOS.md:137`

- **Visualização de peça de texto trava o painel de documentos** — ao visualizar documento de texto
  do editor interno, clicar em outro documento não troca o conteúdo; editor permanece aberto sobre
  a visualização. Mesma família do bug de `innerHTML` do filtro de pastas.
  - `app/templates/pasta/pasta_show.html.twig`
  - Ref: `REFATORACAO-DOMINIOS.md:138`

- **delete() de Pasta cascateia Tarefas silenciosamente** — FK `Tarefa.pasta_id` tem
  `ON DELETE CASCADE`; deletar Pasta apaga Tarefas vinculadas sem aviso. Modal de confirmação
  não alerta. Baixo risco hoje (Tarefa ainda não é dado real); reavaliar antes de popularizar
  o uso de Tarefas.
  - `app/src/Pasta/UseCase/ExcluirPastaUseCase.php`
  - Ref: `REFATORACAO-DOMINIOS.md:111`

- **Filtro de NUP usa LIKE (deveria ser igualdade exata ou dropdown)** — campo é select de valores
  exatos; LIKE permite matches parciais indesejados. Decidir junto com a frente de melhorias de
  filtro (busca livre).
  - `app/src/Pasta/Repository/PastaRepository.php`
  - Ref: `REFATORACAO-DOMINIOS.md:134`

- **F7 — label "Financeiro" com chave `clientes`** — sidebar exibe "Financeiro" mas a chave de
  permissão subjacente é `clientes`. Resolve de quebra com a refatoração da fonte única de módulos.
  - `app/templates/_sidebar.html.twig`
  - Ref: `REFATORACAO-DOMINIOS.md:127` · `docs/AUTORIZACAO.md` § F7

---

## 🟢 Importação do acervo (Fase 0/1/2)

- **[ALTA] Drive e sistema não estão sincronizados (dois sentidos)** — pastas existem
  no sistema sem par no Drive (ex: NUP 1172, criado normalmente pelo escritório em
  10/jun/2026) e vice-versa; nomes divergem por caracteres visualmente idênticos
  (ex: NUP 882 — en-dash `–` no Drive vs hífen `-` no sistema). A Fase 2 NÃO pode
  assumir paridade: precisa tratar pastas órfãs nos dois lados antes de copiar arquivos.
  - Ref: `FASE2-ARQUIVOS-DRIVE.md`

- **[MÉDIA] 170 "números ausentes" do relatório de 10/jun podem conter gaps falsos** —
  o relatório lista 170 NUPs ausentes no intervalo 1–1171, mas o NUP 882 aparecia como
  ausente apenas por en-dash no nome da pasta (parser não reconhecia o número; pasta
  existia no sistema desde 28/mai). Outros dos 170 podem ter o mesmo problema. Não
  tratar a lista como ausências definitivas sem cruzar com o banco de produção.
  - Ref: relatório gerencial 10/Jun/2026 · item "Drive e sistema não estão sincronizados" acima

- **[MÉDIA] Falta comando de reconciliação Drive↔sistema sob demanda** — o acervo é vivo:
  escritório cria e renomeia pastas todo dia em ambos os lados sem espelhamento. Auditoria
  manual (rclone + parse + script) não escala. Avaliar `app:acervo:reconciliar` que tire
  snapshot de ambos os lados no momento e produza um diff (órfãos, divergências de nome,
  arquivos novos).
  - Ref: `IMPORTACAO-ACERVO.md` · `FASE2-ARQUIVOS-DRIVE.md`

- **82 pastas em estado ambíguo (Fase 0)** — 9 em `revisao_manual.csv` + 73 em `pendencias.csv`.
  Tratamento manual necessário com Dr. Farlei.
  - CSVs não versionados (PII). Regenerar com:
    `docker exec jusprime_php_dev bash -c 'cd app && php bin/console app:acervo:parsear --entrada=/var/www/tmp/acervo/acervo_nomes.txt --saida=/var/www/tmp/acervo/'`
    (ajustar saída se necessário). Após regerar, atacar manualmente com Dr. Farlei.
  - Ref: `IMPORTACAO-ACERVO.md` § Pendências

- **11 NUPs sem match no sistema** — motivo `nup_nao_existe_no_sistema`: pastas criadas no Drive
  após a Fase 0. Lista em `tmp/acervo-fase2/output/sem_match_drive.csv` (PII, fora do git).
  Regenerar se sumir com:
  `docker exec jusprime_php_dev bash -c 'cd app && php bin/console app:acervo:mapear --json=/var/www/tmp/acervo-fase2/pastas-drive.json --tenant-id=1 --saida=/var/www/tmp/acervo-fase2/output'`.
  - Ref: `FASE2-ARQUIVOS-DRIVE.md`

- **19 arquivos > 65 MB pulados na carga da Fase 2** — lista em
  `/opt/jusprime-acervo-download/arquivos-grandes.csv` (VPS). Decidir com Dr. Farlei: dividir,
  comprimir ou descartar.
  - Ref: `FASE2-ARQUIVOS-DRIVE.md` § Pendências

- **[INFO] NUP 622 — pasta esvaziada e renomeada intencionalmente** — a pasta foi renomeada
  no Drive para `"622 - vazia/"` pela gerência (confirmado em 10/jun/2026). Sem documentos
  a copiar na Fase 2. Não é regressão nem erro; registrado para não reaparecer como
  achado-fantasma em auditorias futuras.

---

## 🔵 Multi-tenancy / Arquitetura

- **Unique constraint de NUP é GLOBAL** — `findOneBy(['nup'])` sem filtro de tenant; UNIQUE
  constraint não é `(nup, tenant_id)`. Inócuo com 1 tenant; bloqueia import do 2º escritório.
  **Resolvido? Verificar no schema atual.**
  - `app/src/Pasta/Entity/Pasta.php` · `app/src/Pasta/Repository/PastaRepository.php`
  - Ref: `IMPORTACAO-ACERVO.md` § Riscos conhecidos

- **Fonte única de módulos (refatoração estrutural)** — lista de módulos vive hardcoded no Twig;
  não há enum/registro canônico em PHP. Cria blockers: Bug B (redirect), F5 (admin = módulo),
  F7 (label Financeiro), planos comerciais futuros. Fatias: (1) desenhar, (2) criar enum,
  (3) sidebar consome, (4) redirect consome, (5) catálogo derivado. Risco MÉDIO (auth + sidebar).
  - `app/templates/_sidebar.html.twig` · `app/src/Security/UserAuthenticator.php`
  - Ref: `REFATORACAO-DOMINIOS.md:127` · `docs/AUTORIZACAO.md` § 7

- **[ALTA] Auditoria acoplada a namespace** — `AuditLogSubscriber.shouldAudit()` e
  `AuditLogController.getEntityOptions()` filtram por `str_starts_with('App\\Entity\\')`.
  Mover qualquer entidade para outro namespace quebra auditoria + desfazer + queries
  silenciosamente. Pré-requisito da Fatia 4. Refatorar para interface `Auditavel` + migration
  de dados do `audit_log`.
  - `app/src/Shared/EventSubscriber/AuditLogSubscriber.php`
  - `app/src/Shared/Controller/AuditLogController.php`
  - Ref: `REFATORACAO-DOMINIOS.md:106`

- **Limpeza de código/permissões mortos (F2 + F4)** — `PermissionChecker::canActOnResource()`
  nunca chamado (código morto); `admin.tarefas.manage` no catálogo e na sidebar mas nenhum
  controller a usa (permissão fantasma). Risco BAIXO.
  - `app/src/Shared/Security/PermissionChecker.php`
  - `app/src/Entity/Permission/`
  - Ref: `REFATORACAO-DOMINIOS.md:128` · `docs/AUTORIZACAO.md` § F2, F4

- **[DECISÃO] F3 — granularidade de recurso em Processo** — `resources.processo.{view,edit,delete}`
  no catálogo e no painel de Perfis; zero chamadas de `canAccessResource` para `'processo'`.
  Implementar (como Cliente/Pasta têm) ou remover as permissões fantasma. Decisão de produto.
  - `app/src/Processo/Controller/` · `app/src/Entity/Permission/`
  - Ref: `REFATORACAO-DOMINIOS.md:130` · `docs/AUTORIZACAO.md` § F3

- **F6 — Divergência fixture vs migration do catálogo de permissões** — `PermissionFixture.php`
  e a migration `Version20260401130000` têm conjuntos diferentes de permissões. Resolver ao
  aposentar a fixture e definir fonte de verdade do catálogo.
  - `app/src/DataFixtures/PermissionFixture.php`
  - `app/migrations/Version20260401130000.php`
  - Ref: `REFATORACAO-DOMINIOS.md:129` · `docs/AUTORIZACAO.md` § F6

- **13 testes quebrados por UseCase inexistente** — `App\Expediente\UseCase\RemoverMarcadorDaPastaUseCase`
  não existe. Não bloqueia migrações da fila atual; investigar ao atacar o domínio Expediente.
  - `tests/Expediente/`
  - Ref: `REFATORACAO-DOMINIOS.md:115`

- **Entidade Tarefa permanece em legado** — `src/Entity/Tarefa/`; migração futura para
  `src/Tarefa/Entity/`. Ao fazer, atualizar `use` em 11 arquivos.
  - `app/src/Entity/Tarefa/`
  - Ref: `REFATORACAO-DOMINIOS.md:116`

- **TarefaMensagemRepository permanece em legado** — `src/Repository/TarefaMensagemRepository.php`;
  migrar para `src/Tarefa/Repository/` em frente futura.
  - `app/src/Repository/TarefaMensagemRepository.php`
  - Ref: `REFATORACAO-DOMINIOS.md:117`

- **Banco de teste sem setup automático de schema** — `doctrine:migrations:migrate --env=test` é
  manual; migration nova exige rodar à mão ou a suíte quebra silenciosamente (ocorreu na Fatia 1:
  ~38 testes vermelhos despercebidos). Criar `scripts/setup-test-db.sh` ou passo no bootstrap
  do PHPUnit.
  - `app/phpunit.xml.dist`
  - Ref: `REFATORACAO-DOMINIOS.md:141`

- **[DECISÃO] ~18 entidades nunca auditadas** — Kanban, Processo, Cliente, Expediente (Marcador),
  Profile (UserProfile) estão fora de `App\Entity\` e nunca foram auditadas; explícitas em
  `NAO_AUDITAVEIS` no `AuditavelCoberturaTest`. Ampliar auditoria nesses domínios é decisão do
  dono do produto.
  - `app/tests/.../AuditavelCoberturaTest.php`
  - Ref: `REFATORACAO-DOMINIOS.md:144`

- **[BAIXA] Ordem storage→flush no ExcluirPastaUseCase** — arquivos apagados do disco ANTES do
  `em->flush()`. Se flush falhar, arquivos sumidos mas Pasta permanece no banco. Avaliar inverter
  a ordem (flush → storage) ou usar transação.
  - `app/src/Pasta/UseCase/ExcluirPastaUseCase.php`
  - Ref: `REFATORACAO-DOMINIOS.md:107`

- **[BAIXA] 6 entidades de Pasta com `?Tenant` nullable no PHP vs NOT NULL no banco** — `Pasta`,
  `PastaSecao`, `PastaMensagem`, `PastaChecklistItem`, `PastaObservacaoDetalhes`,
  `PastaObservacaoFinanceira` (+ `PastaDocumento` abaixo). Type system mente; sem impacto prático.
  Alinhar `?Tenant`→`Tenant` ao tocar cada entidade.
  - `app/src/Pasta/Entity/`
  - Ref: `REFATORACAO-DOMINIOS.md:161`

- **[BAIXA] Divergência ORM↔banco em PastaDocumento.tenant** — atributo PHP mapeado
  `?Tenant = null`; coluna é NOT NULL. Todos os pontos de persist setam tenant; sem impacto
  prático hoje. Alinhar (`nullable: false`) ao tocar a entidade.
  - `app/src/Pasta/Entity/PastaDocumento.php`
  - Ref: `REFATORACAO-DOMINIOS.md:140`

- **[BAIXA] Inconsistência de FK em PastaDocumento** — `pasta_id` não tem `onDelete: CASCADE`;
  `secao_id` tem. Sem impacto funcional (ORM cascade+orphanRemoval cobre). Alinhar quando
  houver outra migration de Pasta.
  - `app/src/Pasta/Entity/PastaDocumento.php`
  - Ref: `REFATORACAO-DOMINIOS.md:103`

---

## 🟣 Infra / Ops

- **Pasta `/opt/jusprime-acervo-download/pastas/` na VPS (14 GB) pode ser apagada** — conteúdo
  já importado para o sistema. Manter por algumas semanas como segurança; apagar quando confirmado
  com Dr. Farlei que não é mais necessária.
  - VPS — `/opt/jusprime-acervo-download/pastas/`
  - Ref: decisão pós-Fase 2

- **Limpeza automática de cache Docker na VPS** — `deploy-prod-tls.sh` acumula build cache
  indefinidamente; chegou a ~15 GB (38% de disco) em 2026-05-22, limpo manualmente. Risco
  agravado durante migração futura de documentos. Correção: adicionar `docker builder prune -f`
  ao fim do script OU cron semanal na VPS. Nota (01/Jun/2026): no incidente de disco cheio,
  o cache Docker NÃO foi o vilão (~1 GB); o volume de uploads vivo (~24 GB) foi a causa.
  Cache prune deu retorno marginal.
  - VPS — `deploy-prod-tls.sh`
  - Ref: `REFATORACAO-DOMINIOS.md:135`

- **Migrations com pré-check que nunca registram** — `Version20260401000000` e
  `Version20260408180237` aparecem permanentemente como "not migrated" no
  `doctrine:migrations:status` (pré-check impede o registro; schema já existe). Inofensivas
  (sempre puladas), mas poluem o status. Converter em stubs de registro histórico ou marcar
  como executadas manualmente.
  - `app/migrations/Version20260401000000.php`
  - `app/migrations/Version20260408180237.php`
  - Ref: `REFATORACAO-DOMINIOS.md:125`

- **Doc de troubleshooting de certificado TLS ausente** — `DEPLOY.md`/`SETUP.md` cobrem emissão
  e renovação, mas não o cenário "cert sumiu/venceu e deploy abortou". `deploy-prod-tls.sh`
  checa só existência do arquivo, não validade/expiração. Documentar o procedimento de
  recuperação; considerar checagem de expiração no script.
  - `DEPLOY.md` · VPS — `deploy-prod-tls.sh`
  - Ref: `REFATORACAO-DOMINIOS.md:124`

- **[CRÍTICA] Disco de produção subdimensionado para o acervo** — uploads vivos
  (~24 GB no volume Docker `jusprime_uploads_prod`) num disco de 48 GB. Após o
  incidente de 01/Jun e limpeza, disco em ~93%. Cresce com o uso. Requer
  armazenamento dedicado / disco maior. Bloqueia a reversão do item "backup de
  uploads desabilitado" abaixo.
  - VPS — volume `jusprime_uploads_prod`
  - Ref: incidente 01/Jun/2026

- **[CRÍTICA] Backups não ficam fora da VPS** — todos os `.tar.gz` em
  `/var/backups/jusprime` na mesma máquina de produção. Falha de disco ou da VPS =
  perda total do backup. Mover para storage externo (S3 ou outro host).
  - VPS — `/var/backups/jusprime`
  - Ref: incidente 01/Jun/2026

- **[CRÍTICA — paliativo ativo] Backup de uploads desabilitado temporariamente** —
  commit `86564f0` comentou o passo de cópia de uploads em `scripts/backup.sh` para
  parar de estourar o disco. A partir de 01/Jun, os backups contêm APENAS o banco;
  nenhum upload é backupeado. O backup `jusprime_20260531_020001.tar.gz` (último com
  uploads) foi removido pela rotação automática em 10/Jun/2026 (janela de 7 backups).
  Hoje NÃO existe nenhum backup com uploads — falha de disco = perda total sem
  recuperação. REVERTER assim que o disco/storage do item "Disco de produção
  subdimensionado para o acervo" for resolvido.
  - `scripts/backup.sh`
  - Ref: commit `86564f0` · incidente 01/Jun/2026

- **[MÉDIA] `deploy-prod-tls.sh` executa migrations automaticamente** — todo deploy
  roda `doctrine:migrations:migrate` em produção sem confirmação manual. Risco de
  alteração de schema não intencional num deploy de rotina. Avaliar gate de
  confirmação ou separar o migrate do deploy.
  - VPS — `deploy-prod-tls.sh`
  - Ref: incidente 01/Jun/2026

## Expediente — melhorias de navegação (sugestões dos colaboradores)

- [ ] **Ir para página N**: campo para digitar o número da página e saltar direto.
  Complemento dos botões primeira/última já entregues. total_pages já disponível
  no controller; paginação é LIMIT/OFFSET manual com data-page + AJAX.
- [ ] **Persistir página ao voltar/atualizar**: ao recarregar o navegador ou entrar
  numa pasta e voltar, a listagem reseta para a página 1. A página vem de ?page=N
  na query string, mas a navegação AJAX não persiste no histórico/URL ao sair e
  voltar. Provável raiz comum com o item acima. Desmembrado em dois cenários:
  - [x] ~~**Voltar de uma pasta**: ao entrar numa pasta (`pasta_show`) e voltar, a
    listagem restaura página + filtros ativos em vez de resetar~~ — Resolvido
    2026-06-15, commit `d697cad`. Estado persistido em `sessionStorage` (não na URL,
    pois o filtro `cliente` é PII); restaura só quando `document.referrer` aponta
    para `pasta_show`.
  - [ ] **Persistir em refresh/F5**: recarregar o navegador (F5) puro NÃO restaura —
    sem referrer de `pasta_show` o estado salvo é ignorado de propósito e a listagem
    volta à página 1. Continua pendente; exigiria gatilho de restauração independente
    do referrer (ex.: sempre restaurar no bootstrap, ou marcar o estado com timestamp).
- [ ] **Navegação entre pastas (anterior/próxima)**: botões dentro de uma pasta para
  ir à pasta anterior/seguinte sem voltar à listagem. DECISÃO DE UX PENDENTE:
  "próxima" em relação a quê? Deve respeitar filtro e ordenação ativos na listagem,
  não a ordem natural do banco.

## Expediente — ordenação por NUP (dívidas do review)

- [ ] **Performance da ordenação por CAST_INT(nup)**: EXPLAIN mostra Seq Scan +
  Sort; o índice unique em `nup` não serve à expressão de cast. Irrelevante hoje
  (~942 linhas/tenant), mas com tenants de dezenas de milhares de pastas o sort
  do conjunto filtrado roda a cada página. Mitigável com índice funcional parcial
  sobre a expressão de cast. Não feito.
- [ ] **Cache de metadata Doctrine no deploy**: a função DQL CAST_INT depende da
  config nova em doctrine.yaml. O deploy-prod faz up --build (reconstrói imagem,
  deve aquecer o cache), mas confirmar que o build aquece — um cache prod anterior
  à config lançaria "Unknown DQL function CAST_INT". Validar no primeiro deploy
  desta frente.

- **[MÉDIA] Histórico de migrations não-linear em produção — 2 migrations presas como `not migrated`** — `Version20260401000000` (Ponto) e `Version20260408180237` (checklist) constam `not migrated` em prod, mas são posteriores à `Current`. Foram puladas em deploys reais via `skipIf` e o `migrate` do deploy passa sem dano. **Seguro APENAS enquanto:** a tabela `sede` EXISTIR em prod (desarma a 20260401) E a tabela `checklist_item_cliente` NÃO existir em prod (desarma a 20260408 — esta tem `DROP TABLE` perigoso). Verificar antes de assumir: `SELECT to_regclass('sede'), to_regclass('checklist_item_cliente');`. Resolver de vez registrando as 2 como executadas (`doctrine:migrations:version --add`) ou consolidando o histórico, para não depender de `skipIf` indefinidamente.
  - `app/migrations/Version20260401000000.php` · `app/migrations/Version20260408180237.php`
  - Confirmado em prod (banco `prime`) em 2026-06-15: `sede` existe, `checklist_item_cliente` ausente → ambas pulam.
