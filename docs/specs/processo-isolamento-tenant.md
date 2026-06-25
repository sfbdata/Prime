# Spec — Isolamento de tenant do domínio Processo (S3 / P0)

> 3º P0 catastrófico da `docs/specs/auditoria-multitenant.md`, seguindo o mecanismo central de
> `docs/specs/isolamento-tenant-sistemico.md` e o padrão já entregue em
> `docs/specs/cliente-isolamento-tenant.md` (S2). **Risco ALTO** (modelo de dados + migration
> em produção). Status: **AGUARDA APROVAÇÃO** (spec + migration) antes de codar; 2ª aprovação
> antes de aplicar no banco.

## Problema

`Processo` e suas 3 filhas (`DocumentoProcesso`, `MovimentacaoProcesso`, `ParteProcesso`) **não
têm coluna `tenant`** e o `ProcessoRepository` não filtra nada → qualquer usuário com
`modules.processos.view` enxerga a **carteira de processos de todos os escritórios**: listagem
(`findByFilters`), metadados em dropdowns (números de processo, tribunais, classes, assuntos) e
acesso/edição/exclusão por id (IDOR via ParamConverter em show/edit/delete). `canAccessResource`
valida `usuário↔tenant`, não `recurso↔tenant` (Falha F1) — o fix tem de ser no dado/query.

## Objetivo

Tornar `Processo` **e as 3 filhas** `TenantAware` para que o `TenantFilter` global escope
automaticamente listagens, dropdowns e carga por id (incluindo o ParamConverter), fechando
vazamento de listagem **e** IDOR. Filhas com coluna própria fecham também IDOR direto futuro.

## Achados da investigação

### Entidades (todas em `app/src/Processo/Entity/`, sem herança, PK int, **sem tenant** hoje)
- **`Processo`** (tabela `processo`) — raiz. Autor: `criadoPor` = `ManyToOne(User)`
  **`nullable: true`** (`criado_por_id`). `numeroProcesso` **`unique: true` (GLOBAL)**. Auto-FK
  `processo_pai_id` (`onDelete: SET NULL`). OneToMany p/ as 3 filhas (`cascade: persist`,
  `orphanRemoval: true`).
- **`DocumentoProcesso`** (tabela `documento_processo`) — `ManyToOne(Processo)` `processo_id`
  **NOT NULL** (`onDelete: CASCADE`). Sem autor próprio (só `criadoEm`). Tem `caminhoArquivo`.
- **`MovimentacaoProcesso`** (tabela `movimentacao_processo`) — `processo_id` **NOT NULL** (sem
  onDelete). Sem autor.
- **`ParteProcesso`** (tabela `parte_processo`) — `processo_id` **NOT NULL** (sem onDelete). Sem
  autor. Tem `documento` (CPF/CNPJ — LGPD).

> **Por que as 3 filhas recebem coluna própria** (decisão já firmada na spec sistêmica e no S2):
> o filtro Doctrine só escopa a entidade que é `TenantAware`; tornar só o `Processo` não isola
> uma carga por id direta de filha. Coluna própria + backfill via o `processo` pai (FK NOT NULL,
> 0 órfãs) fecha a classe inteira. Sem herança → cada filha é entidade simples, filtro aplica
> direto no seu `tenant_id`.

### Repositórios — cobertura do filtro
`ProcessoRepository`: `findByNumeroProcesso` (findOneBy), `findByFilters` (QueryBuilder), e **4
dropdowns** `findAllTribunais/Classes/Assuntos/NumerosProcesso` (`SELECT DISTINCT` parcial).
**Tudo DQL/QueryBuilder — zero SQL nativo.** Os 3 repos de filha só têm `__construct`/`save`/
`remove` (sem leitura própria). Logo, quando `Processo` for `TenantAware`, todos passam a
filtrar. ⚠️ **Os 4 dropdowns são `SELECT DISTINCT` de coluna parcial** — em tese cobertos pelo
filtro, mas é exatamente o tipo de query que o `CLAUDE.md` avisa que pode escapar → **smoke/teste
obrigatório** (especialmente `findAllNumerosProcesso`, que hoje vaza todos os números de processo).

### Sites de escrita (precisam `setTenant`)
| # | Local | Persiste? | Origem do tenant |
|---|---|---|---|
| 1 | `ProcessoController::new` `:95` (`new Processo`) | sim `:117` | `TenantContext` (`:88`) |
| 2 | `ProcessoController` sync `:387` (`new ParteProcesso`) | cascade | `$processo->getTenant()` |
| 3 | `ProcessoController` sync `:441` (`new MovimentacaoProcesso`) | cascade | `$processo->getTenant()` |
| 4 | `PastaController::vincularProcesso` `:726` (`new Processo`) | sim `:729` | `TenantContext` (`:692`) |
| 5 | `PastaController::fillProcessoFromData` `:1966/:1979` (Parte/Movimentacao) | cascade | `$processo->getTenant()` |
| 6 | `DatajudProcessoMapper::replacePartes/replaceMovimentacoes` `:75/:95` | cascade | `$processo->getTenant()` **se não-null** (guard p/ API efêmera) |
| 7 | `AtualizarProcessoDatajudCommand` `:80` (`new Processo`) | sim `:89` | **`$processoBase->getTenant()`** (CLI, sem request) |
| 8 | `AppFixtures` `:481/513/524/535` (Processo + 3 filhas) | sim | tenant das fixtures |

- `ProcessoController::datajudSearch` `:276` cria `Processo` **efêmero** (nunca persiste) → o
  mapper não seta tenant ali (parent sem tenant) — por isso o **guard de null** no mapper (#6).
- **DocumentoProcesso** só é criado nas fixtures (`:535`) — não há rota de upload implementada.

### Dados do banco dev (`saas`)
- Tabelas: `processo` (base) + `documento_processo`/`movimentacao_processo`/`parte_processo`.
- **3 processos, todos `criado_por_id` NULL** → backfill natural resolve 0 → **fallback de
  tenant único obrigatório** (1 tenant, id 4). Filhas: 13 + 13 + 6 linhas, **0 órfãs estruturais**.

## Mudanças de código (após aprovação)

### Entidades
- `Processo`, `DocumentoProcesso`, `MovimentacaoProcesso`, `ParteProcesso`: `implements
  TenantAware` + `#[ORM\ManyToOne(Tenant)] #[ORM\JoinColumn(nullable:false)] private ?Tenant
  $tenant` + `getTenant()/setTenant()`. Atributo **tem** de se chamar `tenant`.
- (Decisão a confirmar) `Processo`: trocar `numeroProcesso unique:true` global por
  **`#[ORM\UniqueConstraint(columns:['tenant_id','numero_processo'])]`** (unique por escritório).

### Escrita
- Web: setar `tenant` nos sites 1–5 (controllers); o `$tenant` já está em escopo nos dois.
- `DatajudProcessoMapper`: setar `tenant` nas filhas a partir de `$processo->getTenant()`
  **somente se não-null** (não quebra a busca efêmera da API).
- CLI command: `setTenant($processoBase->getTenant())` no `new Processo()` do loop.
- `AppFixtures::loadProcessos`: setar `tenant` no Processo e nas 3 filhas (tenant das fixtures).

### Guard por-id
O filtro fecha show/edit/delete (ParamConverter) e `pasta_vincular_processo` (que usa
`processoRepository->find()`). Guard explícito fica como defesa-em-profundidade opcional (não
nesta entrega), igual ao Cliente.

## Migration (proposta — **NÃO aplicar sem 2ª aprovação**)

Mesma estratégia de 5 passos do Cliente, agora em 4 tabelas. Nomes de FK/índice via
`doctrine:migrations:diff` (leitura, não altera o banco) → `FK_<gerado>`/`IDX_<gerado>`.

```php
public function up(Schema $schema): void
{
    // ---- processo (raiz) ----
    $this->addSql('ALTER TABLE processo ADD tenant_id INT DEFAULT NULL');
    // 1) backfill natural via autor (em dev resolve 0 — todos criado_por_id NULL)
    $this->addSql(<<<'SQL'
        UPDATE processo SET tenant_id = (
            SELECT ut.tenant_id FROM user_tenant ut
            WHERE ut.user_id = processo.criado_por_id AND ut.is_active = true
            ORDER BY ut.id ASC LIMIT 1
        ) WHERE tenant_id IS NULL
    SQL);
    // 2) fallback determinístico p/ órfãos quando há um único tenant
    $this->addSql(<<<'SQL'
        UPDATE processo SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
        WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
    SQL);
    // 3) trava: órfão remanescente (multi-tenant) -> NOT NULL aborta (rollback)
    $this->addSql('ALTER TABLE processo ALTER COLUMN tenant_id SET NOT NULL');
    $this->addSql('ALTER TABLE processo ADD CONSTRAINT FK_<g> FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
    $this->addSql('CREATE INDEX IDX_<g> ON processo (tenant_id)');

    // ---- 3 filhas: herdam o tenant do processo pai (FK NOT NULL, 0 órfãs) ----
    foreach (['documento_processo','movimentacao_processo','parte_processo'] as $t) {
        $this->addSql("ALTER TABLE $t ADD tenant_id INT DEFAULT NULL");
        $this->addSql("UPDATE $t f SET tenant_id = p.tenant_id FROM processo p WHERE p.id = f.processo_id AND f.tenant_id IS NULL");
        $this->addSql("ALTER TABLE $t ALTER COLUMN tenant_id SET NOT NULL");
        $this->addSql("ALTER TABLE $t ADD CONSTRAINT FK_<g> FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE");
        $this->addSql("CREATE INDEX IDX_<g> ON $t (tenant_id)");
    }
    // (escrito linha-a-linha no arquivo final; o foreach é só ilustrativo aqui)

    // ---- (SE aprovado) numero_processo: unique global -> composto por tenant ----
    // $this->addSql('DROP INDEX <uniq_global_numero_processo>');
    // $this->addSql('CREATE UNIQUE INDEX UNIQ_<g> ON processo (tenant_id, numero_processo)');
}
```
`down()` simétrico (drop FK/índice/coluna nas 4 tabelas; restaura unique global se trocado).

**Produção:** com 1 tenant, o passo 2 resolve os órfãos; com >1 tenant + órfão, o passo 3 aborta
(rollback, nada alterado) → corrigir dados antes. Conferir antes do deploy:
`SELECT COUNT(*) FROM tenant` e órfãos pós-backfill natural.

## Testes (cross-tenant)
- **Repo/integração** (`tests/Processo/Functional/`): com 2 tenants e filtro ligado —
  `findByFilters` só do tenant ativo; **os 4 dropdowns** (`findAll*`) só do tenant ativo
  (fecha o vazamento de metadados — o ponto de maior risco); `find()` por id de Processo de
  outro tenant → null; `find()` por id de **cada filha** de outro tenant → null (prova coluna
  própria); `findByNumeroProcesso` escopado por tenant.
- **HTTP** (`tests/Processo/Functional/`): gestor de outro tenant em `processo_show/edit/delete`
  → 404; index não lista processo de outro tenant. (gestores isSystem, como no Cliente.)
- Suíte completa (**731**) verde.

## Decisões a confirmar
1. **Filhas com coluna `tenant` própria** — recomendado (defesa completa; já firmado na spec
   sistêmica). [decidido, confirmando]
2. **`numero_processo`: unique GLOBAL → composto `(tenant_id, numero_processo)`** nesta migration?
   **Recomendado**: dois escritórios podem litigar no mesmo processo (partes adversas) e ambos
   precisam cadastrá-lo; com unique global, o 2º recebe erro/500 e a checagem de duplicidade vira
   um oráculo cross-tenant. Alternativa: deferir (fica um 500 latente em colisão, como no cpf do
   Cliente). 

## Status de implementação (jun/2026) — ✅ ENTREGUE

- 4 entidades `TenantAware` + 8 write-sites + migration `Version20260625192651` aplicada no dev:
  3 processos órfãos → tenant 4; filhas (13/13/6) herdaram; **0 órfãos, 0 incoerência filha↔pai**
  (verificado no banco pela revisão). `schema:validate` OK dev+teste. Suíte **752/752**.
- `numero_processo`: unique global → composto `(tenant_id, numero_processo)` (provado por teste:
  o mesmo número convive em dois escritórios). `PastaType.processo` (EntityType sem query_builder)
  passa a listar só do tenant atual **automaticamente** (a query do EntityType passa pelo filtro,
  mesmo mecanismo já válido para o campo `clientes`).
- Revisão adversarial (2 agentes): **aprovada com ressalvas**. Os "4 bloqueadores" levantados por
  um scanner foram refutados (PastaType já filtra pelo mecanismo; Tarefa é P2 de outro domínio;
  CLI/queries sem filtro explícito é o design central, não defeito).

## Follow-ups / fora de escopo
- **Rollback (`down`) após colisão de número:** o `down()` recria o unique GLOBAL; se em prod dois
  escritórios já tiverem cadastrado o mesmo número (o caso de uso habilitado aqui), o rollback
  **aborta** — exige limpeza manual dos dados antes. Não é defeito (os dados violam mesmo o
  constraint antigo), mas o `down()` não é um rollback garantido pós-colisão.
- **CLI `AtualizarProcessoDatajudCommand`** (`:54` `find`, `:77` `findOneBy`) roda **sem filtro**
  (CLI não popula `TenantContext`): pode atualizar/recriar um processo de outro tenant a partir do
  número. **Pré-existente** (busca por número global), não introduzido aqui; o `setTenant` novo
  está correto para processos novos. Fechar exige escopar a busca por tenant na CLI (arg/derivação).
- **`api_datajud_search`** (`/api/search`) **sem checagem de permissão** hoje (qualquer autenticado
  consulta o Datajud) — gap de autorização, não de tenant.
- **Tarefa (P2)** — `TarefaRepository::findByProcesso` não é tenant-aware do lado Tarefa; é o próximo
  item de menor severidade do `auditoria-multitenant.md`, não regressão desta frente.
- `cpf/cnpj` do Cliente (unique global) — follow-up já registrado no S2.
- Guard por-id de defesa-em-profundidade (deferido, igual S2).
