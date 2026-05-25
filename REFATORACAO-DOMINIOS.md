# Refatoração de Domínios — Mapa Vivo

Documento de contexto para sessões do Claude Code. Atualizado a cada commit
que conclui uma frente. Para regras gerais do projeto, ver `CLAUDE.md` raiz
e `app/src/CLAUDE.md`.

## Decisões arquiteturais firmadas

Padrões observados no domínio `src/Tenant/` (referência de implementação real):

- **UseCase**: método público `executar()` (não `__invoke()`).
- **DTO**: classe `final readonly` com property promotion no construtor; sem métodos.
- **Dependências no UseCase**: `EntityManagerInterface` injetado diretamente; Repositories também injetados quando necessário.
- **Flush**: único, no final do `executar()`.
- **Queries de junção sem entidade mapeada**: `$conn->executeStatement(...)` é aceitável.

Padrões herdados de `app/src/CLAUDE.md`:

- Fluxo: `Request → Controller → Form/DTO → UseCase → Entity → Repository → flush()`.
- Multi-tenancy: toda entidade tem `tenant`; toda query filtra por tenant; UseCase valida posse.
- Permissões: `PermissionChecker` ou `#[IsGranted('modules.<modulo>.view')]`. Nunca `in_array` de role.

## Fila de migração

Ordem definida por critério: domínio destino existe + arquivo pequeno + tem teste + risco BAIXO.

| # | Arquivo legado | Linhas | Destino | Tem teste? | Status |
|---|----------------|-------:|---------|:----------:|--------|
| - | (próximo a definir após análise da fila restante) | | | | |

## Concluído

| Data | Frente |
|------|--------|
| 2026-05-19 | Remoção do módulo PreCadastro (código, templates, fixtures, permissão) |
| 2026-05-19 | DROP TABLE `pre_cadastro` (migration irreversível) |
| 2026-05-19 | Migrar `TarefaRepository` para `src/Tarefa/Repository/` |

## Trilha separada (não tocar na fila de migrações pequenas)

Reescritas grandes — projeto próprio cada uma:

- **PastaController** — frente ATIVA em andamento, ver seção 'Refatoração da Pasta (frente ativa)' acima.
- **TenantController** (1.670 linhas) — mesmo tratamento; cuidado redobrado (componente MÉDIO na hierarquia de risco).
- **PontoController** (1.224 linhas) — componente ALTO. Smoke manual obrigatório, dump de banco antes de qualquer schema change.

## Refatoração da Pasta (frente ativa)

Branch: `refactor/pasta-dominio`. Objetivo: deixar Pasta (núcleo do produto) arquiteturalmente coerente ANTES de importar ~1200 pastas reais do Google Drive. Origem: auditoria de arquitetura de 2026-05-22 (16 inconsistências, baldes: estrutural / consistência / cosmético).

DECISÕES FIXADAS (não revisitar sem motivo):
- Só User e ecossistema de ponto eletrônico contêm dado REAL. Pasta/Cliente/Processo/Documento/Tarefa/Financeiro = fictício/descartável. (Confirmado enfaticamente pelo dono 2026-05-22.)
- I12: ObservacaoFinanceira e ObservacaoDetalhes NÃO serão unificadas. A financeira é embrião do financeiro real (vai ganhar valor/parcela/pagamento) e vai divergir. Ficam separadas.
- Modelo de domínio alvo: Pasta é o centro (criada a partir de contrato). Cliente vive separado, N:N com Pasta. Processo idem, N:N. Tarefa é do escritório, pode ou não ligar a Pasta (zelador recebe tarefa sem pasta). Financeiro tem 2 níveis (da pasta + geral do escritório) ainda não desenhados.
- Modelo de acesso a pastas alvo (estilo Google Drive corporativo): pasta é do tenant, não da pessoa. Todos LISTAM todas as pastas; ABRIR exige acesso (por perfil amplo OU por solicitação AccessRequest→ResourceAccess). Criador/responsável definem edição, não visibilidade. Acesso granular por pasta é futuro próximo, mas o mecanismo deve funcionar.
- Estratégia (X): arrumar a arquitetura ANTES de importar as 1200. Tabela limpa é mais barata de refatorar.
- Limpeza prod: ao fazer deploy desta frente, LIMPAR as pastas de teste no prod ANTES da migration de tenant_id NOT NULL rodar (senão quebra). Passo manual no SSH.

FATIAS (uma por commit):
- [x] Fatia 0 — limpar pastas de teste do dev (feito 2026-05-22, banco zerado, backup em ~/backup_saas_pre_limpeza_pasta.sql.gz)
- [x] Fatia 1 — tenant_id em Pasta + PastaDocumento (commit 25df99d). Coluna + FK + índice + 7 pontos de setTenant. Smoke OK.
- [x] Fatia 2 — filtro de tenant no PastaRepository (resolve o vazamento cross-tenant ALTA). 4 métodos (findByFilters, findAllNups, findPorMarcador, findByFiltrosEMarcador) ganham Tenant $tenant + andWhere('p.tenant = :tenant'); findAll() em acervoGeral() substituído por findByFilters([], $tenant); 2 testes de isolamento cross-tenant (PastaRepositoryIsolamentoTest). Suíte verde exceto os 13 Expediente conhecidos. Feito 2026-05-22.
- [x] Fatia 3 — extrair CRUD de Pasta para UseCases (fatiada em 3a/3b/3c). Resolve I14.
  - [x] 3a — criar() → CriarPastaUseCase + CriarPastaDTO + teste de caracterização. canAccessModule mantido no controller; unicidade global do NUP preservada. Feito 2026-05-22.
  - [x] 3b — editar() → EditarPastaUseCase + EditarPastaDTO + testes (unit 5 + functional 3).
       denyResourceAccessUnlessGranted e CSRF preservados no controller; unicidade global do NUP
       com auto-exclusão; whitelist de situação. Feito 2026-05-24.
  - [x] 3c — delete() → ExcluirPastaUseCase + teste unit (4 casos). denyResourceAccessUnlessGranted
       (ACTION_DELETE) e CSRF preservados no controller; tenant-check e existe()-antes-de-excluir
       adicionados no UseCase (defesa em profundidade, padrão ExcluirPastaSecaoUseCase). Feito 2026-05-25.
- [⏸] Fatia 4 — PAUSADA (mover entidades de Pasta para o domínio). BLOQUEADA por acoplamento
       da auditoria a namespace: o AuditLogSubscriber audita via str_starts_with('App\\Entity\\'),
       o audit_log de produção grava o FQCN antigo como string, e há DQL/SQL hardcoded
       (DemitirFuncionarioUseCase, AuditLogRepository) + denylist do desfazer. Mover o namespace
       PARARIA a auditoria de Pasta silenciosamente e quebraria o desfazer histórico. Exige primeiro
       refatorar o acoplamento (ex: interface Auditavel) + migration de dados no audit_log de prod.
       Investigação de impacto feita 2026-05-25 (5 riscos mapeados). Retomar só depois dessa frente.
- [x] Fatia 5 — limpeza de consistência (5A feita; 5B vira pendência, 5C descartada).
  - [x] 5A — consistência sem schema (I5,I8,I9,I6/I7,I10,I13). Feito 2026-05-25, commit b071226.
  - [–] 5B — onDelete CASCADE na FK PastaDocumento→Pasta: NÃO feito. cascade+orphanRemoval do
    ORM já apaga documentos no único caminho real (ExcluirPastaUseCase via Doctrine); onDelete
    só importaria em DELETE SQL direto, que o sistema não faz. Custo (ALTER TABLE somando à pilha
    de migrations não-deployadas) sem ganho prático. Vira pendência cosmética abaixo.
  - [–] 5C — timestamp em PastaChecklistItem: DESCARTADO (feature, não consistência; sem demanda).

PENDÊNCIAS DO SUBSISTEMA DE SEÇÕES (achadas no smoke da fatia 1, ver seção de pendências): exibição dupla, peticionar sem escolha de seção, visualização de peça travando. Atacar na fatia que tocar seções/documentos.

Próximo passo: refatoração da Pasta CONCLUÍDA (0/1/2/3/5A). Falta levar a branch para produção (deploy com schema change da Fatia 1 — backup, verificar pastas órfãs em prod, smoke reforçado). Fatia 4 pausada.

## Pendências não-migração

- **Inconsistência cosmética de FK em PastaDocumento**: a FK pasta_id NÃO tem onDelete: CASCADE,
  enquanto a FK secao_id tem. Sem impacto funcional (ORM cascade+orphanRemoval já cobre o caminho
  real). Alinhar quando houver outra migration de Pasta a caminho. Detectado 2026-05-25.
- **Auditoria acoplada a namespace (destrava a Fatia 4)**: AuditLogSubscriber.shouldAudit() e AuditLogController.getEntityOptions() filtram por str_starts_with('App\\Entity\\'); audit_log grava entity_class como string FQCN; DesfazerAlteracaoAuditLogUseCase compara por ::class; DemitirFuncionarioUseCase (DQL) e AuditLogRepository (SQL) têm FQCN de Pasta hardcoded. Mover qualquer entidade de App\\Entity\\ para outro namespace quebra auditoria + desfazer + queries, silenciosamente. Refatorar para marcação robusta (interface Auditavel) e planejar migration de dados do audit_log. Pré-requisito da Fatia 4. Detectado 2026-05-25.
- **Ordem storage→flush no ExcluirPastaUseCase**: arquivos são apagados do disco ANTES do
  em->flush(). Se o flush falhar, os arquivos já se foram mas a Pasta permanece no banco
  (inconsistência). Replicado fiel do delete() legado na Fatia 3c. Avaliar inverter a ordem
  (flush primeiro, storage depois) ou usar transação. Risco BAIXO hoje. Detectado 2026-05-25.
- **delete() de Pasta cascateia Tarefas silenciosamente**: a FK Tarefa.pasta_id tem
  ON DELETE CASCADE no banco — deletar uma Pasta apaga as Tarefas vinculadas sem aviso. O modal
  de confirmação não alerta sobre isso. Tarefa e risco BAIXO hoje, mas reavaliar o aviso quando
  Tarefa virar dado real. Detectado 2026-05-25.
- **13 testes quebrados** por `App\Expediente\UseCase\RemoverMarcadorDaPastaUseCase` inexistente. Detectado em 2026-05-19. Não bloqueia migrações da fila. Investigar quando atacar o domínio Expediente.
- **Entidade `Tarefa`** permanece em `src/Entity/Tarefa/` (legado). Migração futura — quando feita, atualizar `use` em 11 arquivos.
- **`TarefaMensagemRepository`** permanece em `src/Repository/` (legado). Migração futura para `src/Tarefa/Repository/`.
- **Skill `criar-repository`** define métodos `salvar()`/`remover()` (português) divergindo do padrão real `save()`/`remove()` (inglês) usado em Cliente, Processo e Tarefa. Corrigir em frente separada.
- **Bug B — redirect pós-login cego**: `UserAuthenticator::onAuthenticationSuccess()` redireciona fixo para `/expediente` sem checar permissão. Qualquer perfil sem acesso a Expediente toma 403 ao logar. Detectado em 2026-05-20. Correção desejada: redirecionar para a primeira rota de módulo que o usuário tem permissão (varrendo os módulos em ordem), com landing neutra de fallback se não tiver nenhum. Frente própria — risco MÉDIO (toca auth). Investigação de 2026-05-20 mapeou 3 achados a tratar:
  1. **São 3 pontos de redirect fixo, não 1**: `UserAuthenticator::onAuthenticationSuccess()` branch 0 (não-User) e branch 1 (1 tenant), MAIS `TenantSelecaoController` (linhas ~44 e ~57, pós-seleção de tenant). Corrigir só o authenticator deixa quem tem múltiplos tenants ainda caindo no 403.
  2. **CONTRADIÇÃO a esclarecer ANTES de desenhar** (1º passo de amanhã): a sidebar (`_sidebar.html.twig`) trata `/expediente` e `/demandas` como SEMPRE visíveis (sem `can_access_module`), mas `ExpedienteController::assertAccess()` EXIGE `canAccessModule('expediente')`. Sidebar (livre) e controller (restrito) discordam. Resolver qual está certo decide todo o resto do desenho.
  3. **Não existe lista canônica de módulos em PHP**: a ordem dos módulos só vive hardcoded no Twig; `PermissionChecker` não tem `getAccessibleModules()` (só `canAccessModule()` por módulo). O desenho precisa decidir onde criar a lista ordenada (módulo → rota index → permissão) sem duplicar o Twig.
  Roteiro amanhã: (1) esclarecer contradição do achado 2; (2) decidir onde vive a lista canônica de módulos; (3) desenhar UseCase/serviço "primeira rota permitida" (recebendo Tenant já resolvido, pois `TenantContext::setCurrentTenant` usa `Security::getUser`); (4) aplicar nos 2 arquivos; (5) landing de fallback (candidata: `app_profile` /perfil, sem permissão de módulo); (6) testes (hoje ZERO cobertura de auth; existe helper `logarComTenant` em `JusPrimeWebTestCase`).
- **Doc de troubleshooting de certificado ausente**: `DEPLOY.md`/`SETUP.md` cobrem emissão e renovação de certs, mas não há seção sobre o cenário "deploy abortou porque o cert de um domínio sumiu/venceu" nem menção a `bluejus.com.br`. A checagem do `deploy-prod-tls.sh` testa só existência do arquivo, não validade/expiração. Documentar o procedimento de recuperação e considerar checagem de expiração. Detectado em 2026-05-20.
- **Migrations com pré-check que nunca registram (`Version20260401000000` e `Version20260408180237`)**: aparecem permanentemente como "not migrated" / "New" no `doctrine:migrations:status` porque o pré-check (tabelas já existem) impede o registro, mas o schema que criariam já está no banco. Inofensivas (sempre puladas), porém poluem o status. Pendência antiga, anterior a esta sessão. Investigar se devem virar stub de registro histórico ou ser marcadas como executadas. Detectado em 2026-05-20.
- **[ALTA] F1 — falha de segurança: itens acessíveis sem o módulo**: `show`/`edit`/`delete` de Cliente e Pasta checam só `canAccessResource`, não `canAccessModule`. Um usuário com `ResourceAccess` a um item específico, mas sem `modules.{clientes,pastas}.view`, acessa `/clientes/{id}` ou `/pasta/{id}` direto pela URL (não vê a listagem, mas abre o item). Pasta tem 50+ chamadas — superfície ampla. Detalhe em `docs/AUTORIZACAO.md` F1. Pode ser corrigida isolada (adicionar checagem de módulo nos show/edit/delete) OU dentro da refatoração da fonte única (módulo como pré-requisito do recurso). Prioridade ALTA — é exposição de dado em produção. Detectado em 2026-05-21.
- **Fonte única de módulos (refatoração estrutural)**: hoje a lista/ordem de módulos só vive hardcoded no Twig da sidebar; não há registro canônico em PHP. Criar uma fonte única (enum/registro) com key, label, rota index, permissão, ordem, ícone, grupo (geral/admin) e visível, consumida pela sidebar, pelo redirect pós-login (resolve Bug B), pela checagem de permissão e futuramente pelo catálogo. Resolve de quebra: Bug B, F7 (label "Financeiro" com chave clientes), F5 (admin e módulo são tecnicamente idênticos — unificar régua), e prepara terreno para planos comerciais futuros (subset de módulos por plano). Frente grande, fatiar em: (1) desenhar, (2) criar fonte, (3) sidebar consome, (4) redirect consome, (5) catálogo derivado. Risco MÉDIO (auth + sidebar visível). Ver seção 7 de `docs/AUTORIZACAO.md`. Detectado em 2026-05-21.
- **Limpeza de permissões/código mortos (F2 + F4)**: `canActOnResource` no `PermissionChecker` é código morto (nunca chamado); `admin.tarefas.manage` é permissão fantasma (no catálogo e na sidebar, nenhum controller a usa). Remover o método morto e a permissão fantasma. Risco BAIXO. Ver F2 e F4 em `docs/AUTORIZACAO.md`. Detectado em 2026-05-21.
- **F6 — divergência fixture vs migration do catálogo**: `PermissionFixture.php` e a migration de catálogo (`Version20260401130000`) têm conjuntos diferentes de permissões. Ligado à aposentadoria já planejada da `PermissionFixture` (seed de dev obsoleto). Resolver ao decidir a fonte de verdade do catálogo. Ver F6 em `docs/AUTORIZACAO.md`. Detectado em 2026-05-21.
- **[DECISÃO] F3 — granularidade de recurso em Processo**: `resources.processo.{view,edit,delete}` existem no catálogo e no painel de Perfis, mas nenhum código as checa (zero chamadas de `canAccessResource` para `'processo'`). Decidir: implementar a granularidade de recurso para Processo (como Cliente/Pasta têm) OU remover as permissões fantasma do catálogo. É decisão de produto, não bug. Ver F3 em `docs/AUTORIZACAO.md`. Detectado em 2026-05-21.
- **[ALTA] Vazamento cross-tenant no PastaRepository (Expediente)**: `findByFilters()` e `findAll()` retornam pastas SEM filtrar por tenant; `findPorMarcador` provavelmente idem (a confirmar). O controller já tem o tenant via `assertAccess()` mas não o repassa ao repository. Mesma classe da falha F1 (consultas/acessos que ignoram o isolamento). Hoje inócuo (1 só tenant em produção), mas vaza pastas entre escritórios assim que houver o 2º cliente. Correção: receber `Tenant` nos métodos e adicionar `andWhere('p.tenant = :tenant')`. Commit próprio de segurança, separado das melhorias de UX do filtro. Prioridade ALTA. Detectado em 2026-05-21.
- **Painel AJAX do filtro de pastas quebrado (Expediente)**: em `_acervo_geral.html.twig` e `_painel_marcador.html.twig`, `carregarComFiltros` usa `painel.innerHTML = html` — `<script>` injetado via innerHTML não executa (padrão HTML5), então a 2ª busca e o botão "Limpar" perdem o handler XHR, caem em navegação normal, o controller redireciona para `expediente_index` e o filtro some. Correção: mover os handlers de submit/clique para `index.html.twig` via delegação, reusar `injetarHtmlComScripts`, e remover os blocos `<script>` dos partials. Frente de frontend. Detectado em 2026-05-21.
- **Filtro de cliente e NUP no PastaRepository (Expediente)**: busca de cliente só consulta o campo legado/denormalizado `p.nomeCliente`, ignorando o relacionamento `pasta.clientes` (ClientePF.nomeCompleto, ClientePJ.razaoSocial/nomeFantasia) — clientes vinculados por relacionamento não são encontrados. NUP usa `LIKE` num campo que é select de valores exatos (deveria ser igualdade). ATENÇÃO: a correção do filtro de cliente depende de INVESTIGAR ANTES o mapeamento Cliente↔Pasta (existe associação inversa `pastas` em ClientePF/ClientePJ?) — não implementar a DQL sobre suposição. Detectado em 2026-05-21.
- **Limpeza automática de cache Docker na VPS**: o deploy diário (`docker compose up --build` via `deploy-prod-tls.sh`) acumula build cache que nunca é limpo — em 2026-05-22 o build cache havia chegado a ~15 GB (disco em 38%), limpo manualmente com `docker builder prune` (liberou 14 GB, disco voltou a 11%). Sem limpeza periódica, volta a acumular e pode encher o disco — risco agravado durante a futura migração de documentos (dezenas de GB). Correção: adicionar um passo de `docker builder prune -f` ao fim do `deploy-prod-tls.sh` OU um cron semanal na VPS. Detectado em 2026-05-22.
- **Exibição dupla de documento em seção (pasta_show)**: ao subir arquivo numa seção nova, o documento aparece DUPLICADO na tela — uma vez na seção, outra na lista principal. Confirmado que NÃO é duplicação de dados (há 1 só registro em pasta_documento; secao_id correto). É bug de exibição/agrupamento no pasta_show: documento com seção está sendo renderizado em dois lugares. Frontend. Detectado em 2026-05-22.
- **Peticionar não permite escolher a seção do anexo (falta funcionalidade)**: ao enviar um documento pelo Peticionar, ele é salvo com secao_id NULL — o fluxo NÃO pergunta a qual seção o anexo deve ir. Comportamento desejado: o peticionar deve perguntar/permitir escolher a seção antes de salvar. Hoje o documento fica "solto" e não aparece em nenhuma seção no pasta_show. É gap de feature, não bug de exibição. Detectado em 2026-05-22.
- **Visualização de peça de texto trava o painel de documentos**: ao visualizar um documento de texto criado pelo editor interno, a visualização "prende" — clicar em outro documento não troca o conteúdo; o editor permanece aberto sobre a visualização. Mesma família do bug de painel AJAX já registrado (innerHTML/script reinjetado não executa). Frontend/JS do pasta_show. Detectado em 2026-05-22.
- **[ALTA] Vazamento cross-tenant via findByCliente (ClienteController:200)**: raiz é o find($id) do Cliente em ClienteController::show() sem validar posse por tenant — Cliente de outro tenant retorna as pastas dele via findByCliente. Vazamento NOVO (não mapeado na pendência ALTA original do PastaRepository), domínio Cliente, fora da Fatia 2. Detectado 2026-05-22.
- **Divergência ORM↔banco em PastaDocumento.tenant**: atributo PHP mapeado `?Tenant = null` enquanto a coluna é NOT NULL (migration Fatia 1). Latente — nenhum caller real aciona hoje (os 4 pontos de persist de PastaDocumento em src/ setam tenant). Alinhar (`nullable: false` no atributo) numa fatia que toque a entidade (candidata: Fatia 4). Detectado 2026-05-22.
- **Banco de teste sem setup automático de schema**: `doctrine:migrations:migrate --env=test` é manual; cada migration nova exige rodar à mão ou a suíte quebra silenciosamente (foi o caso da Fatia 1, que deixou ~38 testes vermelhos despercebidos até a Fatia 2). Criar script de setup de test (candidato: scripts/setup-test-db.sh ou passo no phpunit bootstrap). Detectado 2026-05-22.
- **Bug latente de normalização do NUP no criar de Pasta**: `CriarPastaUseCase` (e o controller legado de origem) checa unicidade via `findOneBy(['nup' => trim($dto->nup)])` com o valor CRU, mas `Pasta::setNup()` armazena `mb_strtoupper(trim())`. NUP com minúsculas (`"abc-001"`) não encontra a duplicata `"ABC-001"` já existente → fura a validação e estoura `UniqueConstraintViolationException` não tratada (HTTP 500). Bug pré-existente, replicado fielmente na 3a (não corrigido). Corrigir normalizando o NUP antes do `findOneBy`. Detectado 2026-05-22.
- **Form de criar Pasta sem CSRF token**: `templates/_partials/modal_nova_pasta.html.twig` faz POST `/pasta/nova` sem `_token`; o controller nunca validou CSRF. Diverge do guia de templates (`CLAUDE.md` exige `_token` em forms manuais). Pré-existente, fora do escopo da 3a. Detectado 2026-05-22.

## Hierarquia de risco (resumo — ver project instructions para detalhe)

- **ALTO**: Ponto eletrônico (tabelas `registro_ponto`, `justificativa_ponto`, `jornada_colaborador`); `PontoController`; `Entity/Ponto/*`. + `User`, `UserTenant`, `Tenant`. **Nunca dropar.**
- **MÉDIO**: `TenantRole`, `Permission`, `Profile`. Drop só com aviso.
- **BAIXO**: resto. Liberdade para refatorar.

## Convenções de uso deste documento

1. Sessão nova no Claude Code: começa colando este arquivo + tarefa do dia.
2. Após cada commit que conclui frente: mover item de "Fila" para "Concluído".
3. Decisões arquiteturais novas: registrar em "Decisões firmadas" (com referência ao domínio onde foi estabelecido).
4. Este arquivo NÃO duplica `CLAUDE.md` nem `app/src/CLAUDE.md`. Se algo virar regra geral, migra pra um daqueles.
