# P2.3 — Isolamento de tenant do Ponto (+ E6 migração estrutural)

> Risco **ALTO** (ponto eletrônico). Último item da remediação multi-tenant.
> Retomada: `docs/specs/PROGRESSO-PENDENCIAS.md`. Auditoria-mãe: `docs/specs/auditoria-multitenant.md`.
> Design do mecanismo: `docs/specs/isolamento-tenant-sistemico.md`.

## Decisões travadas (com o dono)

1. **Modelo de dados = POR VÍNCULO (coluna `tenant`).** O `RegistroPonto`/`JustificativaPonto`
   pertence ao escritório sob o qual foi registrado. Cada escritório vê só o ponto batido no
   contexto dele. Empregado de N vínculos bate ponto N vezes (uma por tenant). Folha/jornada por
   vínculo = correto trabalhista. **Rejeitado** o modelo "por pessoa" (ponto do usuário visível a
   todas as firmas dele) — quebraria a folha por vínculo e exigiria guard manual em dezenas de rotas
   sem rede de testes.
2. **Escopo = isolamento + migração estrutural (E6).** **Ordem: isolamento PRIMEIRO** (cria a rede
   de testes hoje inexistente), **migração estrutural pro `src/Ponto/` DEPOIS** (refator de
   comportamento preservado, protegido pelos testes da Fase 1). Mover e reescrever nunca no mesmo
   commit (regra `src/CLAUDE.md`).
3. **Backfill ambíguo:** registro cujo `user` tem **>1 tenant ativo** ou **0** (órfão) → a migration
   **aborta de propósito** e exige resolução manual antes do deploy (mesmo padrão das migrations
   anteriores). No dev (1 tenant, tabelas de ponto vazias) nunca dispara.

## Estado atual (investigado)

- Entidades em `app/src/Entity/Ponto/` (LEGADO; destino `src/Ponto/`): `RegistroPonto`,
  `JustificativaPonto`, `JornadaColaborador`, `JornadaTenant`, `Feriado`, `BlocoJornada`,
  `BlocoJornadaColaborador`. Enums já em `src/Ponto/Enum/`.
- Controllers (todos em `src/Controller/` legado): `PontoController` (1229), `TenantController`
  (1670, ~20% é ponto), `JornadaTenantController` (237), `JornadaColaboradorController` (290),
  `FeriadoController` (149).
- **0 testes funcionais.** Unit: Calculadora/IntervaloRepouso/FolhaPontoBuilder/Alerta/JornadaResolver.
- Banco dev: 1 tenant; `registro_ponto`/`justificativa_ponto`/`jornada_colaborador`/`jornada_tenant`/
  blocos = **0 linhas**; `feriado` = 9. **0 usuários multi-tenant.**
- **Ledger:** `Version20260401000000` e `Version20260408180237` NÃO estão no ledger → a migration do
  Ponto deve ser aplicada **isolada** via `migrations:execute --up`, nunca `migrate`.
- Mecanismo (S1, validado): `TenantFilter` injeta `tenant_id = :tenant` em toda entidade
  `TenantAware`, ligado por request (`TenantFilterListener`, prio 5) com o tenant ativo. Cobre
  `find()` por id E `findBy`. **O filtro usa o tenant da SESSÃO** (`getCurrentTenant`), não o
  `{tenantId}` da URL. Para admin comum a sessão sempre tem tenant ativo (o `TenantContextValidatorListener`
  obriga a selecionar) → `find($id)` de outro tenant vira `null` → 404. **Ressalva (revisão):** um
  `ROLE_SUPER_ADMIN` SEM tenant na sessão roda com o filtro DESLIGADO (bypass intencional e sistêmico,
  igual aos 6 domínios anteriores) → o 404 não é garantido nesse caso. Ver follow-up "frestinha super-admin".

## Estratégia por entidade

| Entidade | Tem tenant hoje? | Ação Fase 1 |
|---|---|---|
| **RegistroPonto** | ❌ | + coluna `tenant` NOT NULL + `TenantAware` + migration + `setTenant` nos write-sites. Carregado por id em `ponto add/edit/delete` (admin) → coluna própria obrigatória. |
| **JustificativaPonto** | ❌ | + coluna `tenant` NOT NULL + `TenantAware` + migration + `setTenant`. Carregado por id em editar/anexo/aprovar/rejeitar/reverter → coluna própria obrigatória. |
| **JornadaTenant** | ✅ OneToOne Tenant | marcar `TenantAware` (zero migration). |
| **Feriado** | ✅ ManyToOne Tenant | marcar `TenantAware` (zero migration). Já tem guard explícito + `setTenant`. |
| **JornadaColaborador** | ❌ | **Sem mudança na Fase 1.** É `OneToOne(User)`, carregado via `user->getJornadaColaborador()` e guardado por `existeVinculoAtivo(user, tenant)` → **não vaza**. Tornar a jornada per-vínculo (OneToOne→ManyToOne + tenant) toca FolhaPontoBuilder/JornadaResolver e só importa para usuário multi-tenant (0 hoje) → **follow-up** (ver abaixo). |
| **BlocoJornada** | herda de JornadaTenant | **Sem mudança.** Nunca carregado por id próprio (o `save` reconstrói todos os blocos). Isolamento herdado do pai filtrado. |
| **BlocoJornadaColaborador** | herda de JornadaColaborador | **Sem mudança.** Idem. |

## Fase 1 — Isolamento (no local legado)

### 1.1 Rede de testes do comportamento atual (ANTES de mexer)
Functional cobrindo happy path + os vetores de IDOR como existem hoje, para travar comportamento
antes de fechar os buracos. (Construída em `tests/Ponto/Functional/`.)

### 1.2 Entidades
- `RegistroPonto`, `JustificativaPonto`: `implements TenantAware` + `#[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private ?Tenant $tenant`.
- `JornadaTenant`, `Feriado`: `implements TenantAware` (coluna já existe; conferir nome `tenant`).

### 1.3 Migration (uma só, `VersionYYYYMMDDHHMMSS`)
`registro_ponto` e `justificativa_ponto`:
1. add `tenant_id` nullable.
2. backfill: `UPDATE ... SET tenant_id = (SELECT ut.tenant_id FROM user_tenant ut WHERE ut.user_id = t.user_id AND ut.is_active)` — **abortar** (RAISE/guard) se existir registro cujo user tenha ≠1 tenant ativo. Em tenant único, fallback direto.
3. `NOT NULL` + FK (`ON DELETE` conforme convenção) + índice `idx_*_tenant_id`.

Aplicar no dev: `migrations:execute 'DoctrineMigrations\VersionXXX' --up`. Depois `schema:validate` (dev+teste) e `schema:update` no teste se preciso.

### 1.4 Write-sites — `setTenant(tenantAtivo)`
- `PontoController::batida()` → `new RegistroPonto` (~596).
- `PontoController::novaJustificativa()` → `new JustificativaPonto` (~244).
- `TenantController::novaJustificativaAdmin()` → `new JustificativaPonto` (~1323).
- `TenantController` ponto add (`app_tenant_user_ponto_add`) → `new RegistroPonto`.
- Conferir `AppFixtures`/factories de ponto (se houver) → `setTenant`.

### 1.5 IDOR — fechado pelo filtro (verificar 404), sem guard manual onde o filtro cobre
- `PontoController`: `editarJustificativa`, `downloadAnexo` (JustificativaPonto por id) → 404 cross-tenant.
- `TenantController`: `aprovar`/`rejeitar`/`aprovarTodos`/`reverter`/`downloadAnexoJustificativa`
  (find/findBy JustificativaPonto), ponto `edit`/`delete` (RegistroPonto por id) → 404 cross-tenant.
- `FeriadoController` edit/delete: já guardado; filtro = defesa em profundidade.

### 1.6 IDOR que o filtro NÃO cobre (guard manual obrigatório)
- **Exportar folha** (`exportarFolhaPdf`/`exportarFolhaXlsx`): carregam `User` por id. `User` NÃO é
  `TenantAware` (estrutural/compartilhado) → filtro não fecha. As batidas/justificativas da folha já
  vêm filtradas (folha de user estranho sairia vazia), mas é preciso **guard explícito**
  `existeVinculoAtivo(targetUser, tenantAtivo)` → 404. (Conferir: o P2.2 Profile já guardou a via
  `exportarFolha→getProfile`; confirmar se cobre as duas exportações.)
- **SQL nativo** `RegistroPontoRepository::findCompetenciasComRegistroPorUsuario` → adicionar
  `AND tenant_id = :tenant` (raw SQL não passa pelo filtro).
- **Bulk DQL/UPDATE** `RegistroPontoRepository::desvincularSede` → escopar por tenant (UPDATE/DELETE
  DQL não aplica SQL filter).
- Repos de justificativa/registro com `findBy(['user'=>...])`: passam a filtrar tenant automático ao
  virar `TenantAware`; conferir que nenhum método monta SQL nativo.

### 1.7 Rede de testes final
- **Functional (segurança):** 404 cross-tenant em editar/anexo justificativa (self); aprovar/
  rejeitar/aprovarTodos/reverter/anexo justificativa (admin); ponto add/edit/delete (admin);
  exportar folha pdf/xlsx (user de outro tenant); feriado edit/delete; jornada_colaborador
  get/save/delete (user de outro tenant). `batida` carimba o tenant certo.
- **Repo:** queries de RegistroPonto/JustificativaPonto isoladas; `findCompetencias...` (SQL nativo)
  isolado por tenant; `desvincularSede` não cruza tenant.
- **find() IDOR:** `RegistroPonto`/`JustificativaPonto` `find()` cross-tenant → `null`.

### 1.8 Revisão (risco ALTO → dupla)
`/review` (feature-review-agent) contra esta spec → corrigir → **re-revisar** antes de seguir p/ Fase 2.

## Fase 2 — Migração estrutural E6 (escopo 2a "move puro", decidido pelo dono)

> **✅ Fase 2a ENTREGUE (não commitada):** 30 arquivos movidos para `src/Ponto/{Entity,Repository,Service,Form,Controller}`,
> 0 referências remanescentes ao namespace antigo, bloco `AppPonto` no `doctrine.yaml`, bloco
> `ponto_controllers` no `routes/attributes.yaml` (o loader `routing.controllers` só pegava o PontoController;
> os outros 3 controllers precisaram do scan por diretório). Verificação: `schema:validate` OK, `lint:container`
> OK, `lint:twig` OK, `debug:router` 20 rotas ponto/feriado/jornada, **suíte 784/784**. Templates NÃO moveram.
> Pendente: smoke manual (TenantBootstrapService cria tenant com Feriado/JornadaTenant; forms de ponto). Detalhes ↓

**Natureza:** MOVE puro, comportamento preservado (regra `src/CLAUDE.md` #6 — não misturar com reescrita).
Rede de segurança: testes da Fase 1 + suíte 784. `strict_types` e extração de UseCase ficam de FORA
(viram follow-up). Mapeamento completo via workflow read-only `mapear-move-ponto`.

**O que move para `src/Ponto/`:**
- 7 entidades `App\Entity\Ponto\*` → `App\Ponto\Entity\*`.
- 7 repositories `App\Repository\Ponto\*` → `App\Ponto\Repository\*`.
- 6 services `App\Service\Ponto\*` → `App\Ponto\Service\*`.
- 4 forms `App\Form\{RegistroPontoManual,JustificativaPonto,Feriado,JornadaColaborador}Type` → `App\Ponto\Form\*`.
- 4 controllers próprios `App\Controller\{Ponto,Feriado,JornadaColaborador,JornadaTenant}Controller` → `App\Ponto\Controller\*`.

**Config obrigatória no mesmo passo:**
- `doctrine.yaml`: adicionar bloco `AppPonto` (dir `src/Ponto/Entity`, prefix `App\Ponto\Entity`) — sem ele o ORM perde as entidades.
- `repositoryClass` das 6 entidades que o declaram → novo namespace.
- Relações `User→JornadaColaborador` e `Tenant→JornadaTenant` (corrigir `use`/targetEntity).
- `cache:clear` (dev+test) + `composer dump-autoload` (proxies e container guardam FQCN antigo).
- Rotas: provavelmente nada (Cliente/Pasta carregam de `src/<Dominio>/Controller` via `routing.controllers`);
  conferir `debug:router`; bloco em `config/routes/attributes.yaml` é fallback.

**Decisões travadas:**
- `editUserRole` FICA no `TenantController` (tela de gestão do usuário; passa a importar de `App\Ponto\`).
- `Sede` (entidade + ações de sede no TenantController) FICA no domínio Tenant.
- Ações de ponto/justificativa dentro do `TenantController` (pontoAdd/Edit/Delete, aprovar/rejeitar/
  reverter/aprovarTodos/novaJustificativaAdmin/anexo) FICAM lá nesta fase (só atualizam `use`) — extração
  é follow-up (2b, refactor separado). `calcularCargaDiaria` (morto) pode ser removido depois, não agora.

**Templates NÃO movem** (Twig resolve por diretório; todas as rotas têm `name` explícito → `path()` intacto).

**Follow-ups pós-2a:** 2b (extrair ponto do TenantController), 2c (UseCases), `strict_types` nas classes
movidas, remover `GeofencingService` (morto) e `calcularCargaDiaria`.

## Revisão adversarial (jun/2026) — Fase 1
`feature-review-agent` (risco ALTO). Aprovou o núcleo: 6 write-sites com `setTenant` corretos,
backfill Postgres correto, SQL nativo/bulk escopados, `down()` simétrico, suíte 784/784. Único
achado ALTO = "frestinha super-admin" (abaixo), **decidido pelo dono como follow-up** atrelado à
definição de poderes do super-admin. Achados BAIXO/INFO (strict_types legado, ordem de null-check
da `batida`) ficam como dívida — `strict_types` entra na reescrita da Fase 2.

## Follow-ups registrados
- **🔴 Frestinha super-admin (decisão adiada p/ o fim das pendências):** o `TenantFilter` é ligado
  pelo tenant da SESSÃO; um `ROLE_SUPER_ADMIN` sem tenant na sessão roda com o filtro desligado e as
  rotas admin (`pontoEdit/Delete`, `aprovar/rejeitar/reverter/anexo` justificativa) só validam posse
  por `user`, não por tenant → IDOR cross-tenant residual SÓ para essa conta de plataforma. Admin
  comum não alcança (sempre tem tenant na sessão). **Não é regressão** (antes o vazamento era total).
  Fechamento proposto quando os poderes do super-admin forem definidos: guard por-id
  `$entidade->getTenant()?->getId() === $tenant->getId()` nas rotas admin (+ `'tenant' => $tenant` no
  `findBy` do `aprovarTodos`) + teste do vetor super-admin-sem-tenant (cobre o Achado 2 da revisão).
- **Jornada per-vínculo:** `JornadaColaborador` `OneToOne(User)` → per (user, tenant) quando surgir
  empregado multi-tenant real. Toca `FolhaPontoBuilder`/`JornadaResolver`. Não é vazamento hoje.
- **strict_types legado:** entidades do Ponto e `RegistroPontoRepository` sem `declare(strict_types=1)`
  → normalizar na reescrita estrutural (Fase 2).
- Uploads de justificativa ainda em `public/uploads/justificativas` → mover p/ fora do public + rota
  controlada (follow-up app-wide de uploads, já aberto).
- `DemitirFuncionarioUseCase`: SQL nativo cross-tenant (frente própria, já registrada).

## Deploy prod (Fase 1)
1. Conferir cadeia anterior aplicada e `SELECT COUNT(*) FROM tenant`.
2. Conferir que nenhum `registro_ponto`/`justificativa_ponto` tem user com ≠1 tenant ativo (senão a
   migration aborta — resolver manualmente).
3. Rodar a migration ISOLADA (`execute --up`).
