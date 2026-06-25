# Spec — Isolamento de tenant do domínio Cliente (S2 / P0)

> Aplicação por domínio do mecanismo central de `docs/specs/isolamento-tenant-sistemico.md`
> (infra S1 entregue no commit `ab735b9`). **Risco ALTO** (modelo de dados + migration que
> roda em produção). Cliente é o 1º P0 catastrófico da `docs/specs/auditoria-multitenant.md`.
> Status: **AGUARDA APROVAÇÃO** (spec + migration) antes de codar; e 2ª aprovação antes de
> aplicar a migration no banco.

## Problema (recapitulação)

`Cliente` (e `ClienteDocumento`) **não têm coluna `tenant`** e o `ClienteRepository` não filtra
nada → qualquer usuário com `modules.clientes.view` enxerga a base de clientes de **todos** os
escritórios (nome, CPF/CNPJ, endereço, telefone, documentos) e abre/edita/exclui por id (IDOR).
`canAccessResource` valida `usuário↔tenant`, **não** `recurso↔tenant` — por isso o fix tem de
ser no dado/query, não em permissão (Falha F1 do `AUTORIZACAO.md`).

## Objetivo

Tornar `Cliente` e `ClienteDocumento` `TenantAware` para que o `TenantFilter` global (já
ativo por request) passe a escopar **automaticamente** todas as listagens, autocompletes e
cargas por id, fechando vazamento de listagem **e** IDOR de uma vez.

## Achados da investigação (fundam a solução)

### Hierarquia / tabelas (confirmado em código + banco dev)
- `Cliente` — **abstrata**, herança `JOINED`, tabela base **`cliente`**, discriminador `tipo`
  (`pf`/`pj`). PK `id` INT (não-UUID). Autor: `criadoPor` = `ManyToOne(User)`,
  **`JoinColumn(nullable: true)`** → coluna `criado_por_id` INT NULL. **Não** tem `tenant`.
- `ClientePF` (tabela `cliente_pf`) e `ClientePJ` (tabela `cliente_pj`) — subclasses JOINED;
  herdam `criadoPor`; **não precisam de coluna `tenant` própria** (ver "Por que só na base").
- `ClienteDocumento` (tabela `cliente_documento`, entidade simples `INHERITANCE_TYPE_NONE`) —
  `ManyToOne(Cliente)` `cliente_id` NOT NULL; **sem autor próprio e sem tenant**. Único caminho
  de origem de tenant é via o `Cliente` pai. Coleção `Cliente.documentos` (`OneToMany`,
  `orphanRemoval: true`). Arquivos físicos já fora do `public/` (`var/uploads/clientes`).

### Por que a coluna `tenant` fica **só na tabela base `cliente`** (verificado na fonte)
Doctrine ORM **3.6.2**, `SqlWalker::generateFilterConditionSQL` (`SqlWalker.php:465-470`): em
herança `JOINED` **só o nó raiz é filtrado** (subclasse retorna `''`). Ao consultar uma
subclasse, a tabela raiz entra por `INNER JOIN` e o filtro é aplicado **no alias da tabela
raiz** (`SqlWalker.php:331`). Logo, `tenant_id` em `cliente` (raiz) gera `cliente.tenant_id = :t`
para **toda** consulta — `Cliente`, `ClientePF`, `ClientePJ`, `find($id)` ou `INSTANCE OF`.
`ClienteDocumento` é entidade simples → o filtro aplica direto no seu próprio `tenant_id`.

### Repositório (cobertura do filtro)
`ClienteRepository` (`app/src/Cliente/Repository/ClienteRepository.php`) tem só DQL/QueryBuilder:
`findByFilters` (`createQueryBuilder('c')` + `INSTANCE OF`), `findAllNomes`/`findAllDocumentos`/
`findAllCelulares` (`findAll()` = `findBy([])`). **Nenhum SQL nativo/`Connection`** → todos
passam pelo filtro assim que `Cliente` for `TenantAware`. Nada a reescrever no repo.

### Pontos de **escrita** (onde o tenant precisa ser SETADO — obrigatório p/ não violar NOT NULL)
1. `ClienteController::newPF` — `app/src/Cliente/Controller/ClienteController.php:144/149`
   (`$tenant` já em escopo na linha 138).
2. `ClienteController::newPJ` — `:170/175` (`$tenant` em escopo na 164).
3. **Upload de documento** — `cliente_documento_upload`: ao criar o `ClienteDocumento`, setar
   `tenant` = tenant do `Cliente` pai (defesa: nunca confiar no request).
4. `PastaController` (legado) — `app/src/Controller/PastaController.php:260/305` cria
   `ClientePF`/`ClientePJ` inline **sem `criadoPor` e sem tenant**. Precisa setar `tenant` (e,
   por correção, `criadoPor`). Ponto mais fácil de esquecer (fora do domínio Cliente).
5. `AppFixtures::loadClientesPF/PJ` — `app/src/DataFixtures/AppFixtures.php:152/274` criam
   clientes de seed sem tenant/autor → precisam setar `tenant` (mesmo padrão já usado p/ os
   Chamados de seed no H1).
> Não há `CriarClienteUseCase` (o domínio Cliente não tem camada UseCase — dívida pré-existente).
> **Fora de escopo** desta correção de segurança: mantemos a escrita inline (espelhando o
> `setCriadoPor` atual), sem introduzir UseCase agora, para manter o fix focado e de baixo risco.

### Dados do banco **dev** (`saas`) — fundam o backfill
- Tabelas reais: `cliente` (base), `cliente_pf`, `cliente_pj`, `cliente_documento`.
- `cliente.criado_por_id` INT **NULL**; **não** existe `tenant_id` ainda.
- **8 clientes** (ids 4–11; 5 PF + 3 PJ), **todos com `criado_por_id` NULL** (100% órfãos).
- **0** `cliente_documento`.
- `user_tenant`: `user_id`, `tenant_id`, `is_active BOOL`, unique `(user_id, tenant_id)` — um
  user **pode** ter vários tenants; **não há** coluna "principal". Desambiguação por
  `is_active = true` + `ORDER BY id ASC LIMIT 1` (mesmo critério do H1/ServiceDesk).
- **1 único tenant** no dev (`id = 4`).
- Consequência: o backfill natural (via `criado_por_id`) resolve **0 de 8** → o `SET NOT NULL`
  falharia. Precisa de tratamento dos órfãos (ver migration).

## Mudanças de código (após aprovação)

### Entidades
- `Cliente` (`app/src/Cliente/Entity/Cliente.php`): `implements TenantAware`; importar
  `App\Entity\Tenant\Tenant` e `App\Shared\Contract\TenantAware`; adicionar
  ```php
  #[ORM\ManyToOne(targetEntity: Tenant::class)]
  #[ORM\JoinColumn(nullable: false)]
  private ?Tenant $tenant = null;
  ```
  + `getTenant(): ?Tenant` e `setTenant(Tenant $tenant): self`. Atributo **tem** de se chamar
  `tenant` (o filtro resolve a coluna por `getSingleAssociationJoinColumnName('tenant')`).
- `ClienteDocumento` (`app/src/Cliente/Entity/ClienteDocumento.php`): mesmo bloco
  (`implements TenantAware` + relação `tenant` not-null + getter/setter). Justificativa: torna
  as 4 rotas `/documento/{id}/...` fechadas pelo filtro (sem guard manual).
- `ClientePF`/`ClientePJ`: **sem alteração** (herdam tenant da base).

### Controllers / fixtures
- `ClienteController` newPF/newPJ: `+ $cliente->setTenant($tenant);` junto ao `setCriadoPor`.
- `ClienteController` upload de documento: `$documento->setTenant($cliente->getTenant());`.
- `PastaController` 260/305: setar `tenant` (e `criadoPor`) nos clientes criados inline.
- `AppFixtures` loadClientesPF/PJ: setar `tenant` (e `criadoPor`) — espelhando os Chamados.

### Guard por-id (defesa em profundidade) — **opcional, não nesta entrega**
O filtro já fecha show/edit/delete/upload (carga por `find`/ParamConverter de `Cliente`) e as 4
rotas de documento (ParamConverter de `ClienteDocumento`, agora TenantAware). Guard explícito de
posse fica como defesa-em-profundidade futura (só relevante se o filtro for desligado em algum
contexto), fora do escopo desta entrega.

## Migration (proposta — **NÃO aplicar sem 2ª aprovação**)

Mesma sequência de 5 passos do H1 (`Version20260625120342`), por tabela. Os nomes de FK/índice
serão **gerados via `doctrine:migrations:diff`** (leitura do schema, sem alterar o banco) para
casar com `schema:validate`; abaixo marcados como `FK_<gerado>` / `IDX_<gerado>`.

```php
public function up(Schema $schema): void
{
    // ---- Cliente (tabela base da herança JOINED) ----
    $this->addSql('ALTER TABLE cliente ADD tenant_id INT DEFAULT NULL');

    // 1) backfill natural: autor -> user_tenant ativo (vínculo mais antigo desempata)
    $this->addSql(<<<'SQL'
        UPDATE cliente SET tenant_id = (
            SELECT ut.tenant_id FROM user_tenant ut
            WHERE ut.user_id = cliente.criado_por_id AND ut.is_active = true
            ORDER BY ut.id ASC LIMIT 1
        ) WHERE tenant_id IS NULL
    SQL);

    // 2) fallback determinístico para órfãos SÓ quando existe um único tenant
    //    (cobre o dev/single-firm; em multi-tenant NÃO dispara e o passo 3 aborta)
    $this->addSql(<<<'SQL'
        UPDATE cliente SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
        WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
    SQL);

    // 3) trava de segurança: se sobrar órfão (multi-tenant), o NOT NULL ABORTA a migration
    //    (transação inteira faz rollback) -> corrigir os dados antes de re-aplicar.
    $this->addSql('ALTER TABLE cliente ALTER COLUMN tenant_id SET NOT NULL');
    $this->addSql('ALTER TABLE cliente ADD CONSTRAINT FK_<gerado> FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    $this->addSql('CREATE INDEX IDX_<gerado> ON cliente (tenant_id)');

    // ---- ClienteDocumento (herda do cliente pai) ----
    $this->addSql('ALTER TABLE cliente_documento ADD tenant_id INT DEFAULT NULL');
    $this->addSql(<<<'SQL'
        UPDATE cliente_documento d SET tenant_id = c.tenant_id
        FROM cliente c WHERE c.id = d.cliente_id AND d.tenant_id IS NULL
    SQL);
    $this->addSql('ALTER TABLE cliente_documento ALTER COLUMN tenant_id SET NOT NULL');
    $this->addSql('ALTER TABLE cliente_documento ADD CONSTRAINT FK_<gerado2> FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    $this->addSql('CREATE INDEX IDX_<gerado2> ON cliente_documento (tenant_id)');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE cliente_documento DROP tenant_id');
    $this->addSql('ALTER TABLE cliente DROP tenant_id'); // PG remove FK+índice com a coluna
}
```

**Comportamento em produção (bluejus.com.br):**
- Se prod tem **1 tenant**: passo 2 atribui todos os órfãos a esse tenant (correto — há um só
  escritório). Migration conclui.
- Se prod tem **>1 tenant** e há clientes órfãos (sem `criado_por_id` resolvível): passo 2 não
  dispara, passo 3 **aborta** com erro do Postgres (`column "tenant_id" contains null values`),
  rollback total, **nenhum dado alterado**. Aí decidimos manualmente o tenant dos órfãos antes
  de re-aplicar. Decisão consciente: **nunca** chutar tenant em ambiente multi-tenant.
- **Antes do deploy**, conferir em prod: `SELECT COUNT(*) FROM tenant;` e quantos clientes
  ficariam órfãos após o backfill natural. Anexar resultado ao checklist de deploy.

## Testes (cross-tenant) — padrão do H1

Não existe `ClienteFactory` nem testes de Cliente hoje. Criar:
- `tests/Factory/Cliente/ClientePFFactory.php` e `ClientePJFactory.php` (Foundry v2), com
  `tenant` e `criadoPor` setáveis (espelhar `tests/Factory/.../*Factory.php`).
- **Functional** (`tests/Cliente/Functional/`): com 2 tenants —
  - `cliente_index` lista só os clientes do tenant ativo;
  - autocompletes (`findAllNomes/Documentos/Celulares`) idem;
  - `show`/`edit`/`delete` de cliente de **outro** tenant → **404**;
  - `documento/{id}/visualizar|download|editar|deletar` de outro tenant → **404**;
  - happy-path do tenant dono continua 200.
- **Repository/integração** (`tests/Cliente/...`): com o filtro ligado, `findByFilters`/
  `findAll*` só retornam o tenant ativo (espelha `ChamadoRepositoryTest`).
- **Criação grava tenant**: novo PF/PJ persistido fica com o tenant ativo; documento herda o
  tenant do cliente.
- Toda a suíte (**722**) verde — o filtro afeta queries existentes; risco de regressão baixo.

## Sequência de aplicação (após 2ª aprovação — toca o banco)
1. `doctrine:migrations:diff` → capturar nomes reais de FK/índice; fundir o backfill na migration.
2. **Mostrar a migration final** (este passo é o gate da 2ª aprovação).
3. Aplicar no **dev** (`saas`): `doctrine:migrations:migrate` (passo 2 do backfill atribui os 8
   órfãos ao tenant 4).
4. Sincronizar **teste** (`saas_test`): `doctrine:schema:update --force` (tabela vazia → add
   NOT NULL ok) ou migrate; rodar `php bin/phpunit` (suíte completa).
5. `schema:validate` (dev + teste) e `fixtures:load` OK.

## Decisões a confirmar com o dono (antes de codar)
1. **`ClienteDocumento` com coluna `tenant` própria** (vs. guard via cliente pai) — recomendado;
   fecha as 4 rotas de documento pelo filtro. (Alinhado ao prompt: "ambos precisam de tenant".)
2. **Fallback de tenant único na migration** para os 8 órfãos do dev — recomendado (seguro: só
   dispara com 1 tenant; multi-tenant aborta). Alternativa: corrigir os 8 `criado_por_id` à mão
   antes de migrar.
3. **Setar `criadoPor` no `PastaController`** (hoje ausente) junto do tenant — recomendado
   (corrige geração de órfãos na origem).
4. **Sem `CriarClienteUseCase` agora** (escrita inline) — manter o fix focado; UseCase fica como
   dívida registrada. OK?

## Riscos
- Backfill em tabela grande de prod (Cliente) — passos de UPDATE antes do NOT NULL; janela de
  deploy. Trava do passo 3 evita estado inconsistente.
- Regressão em telas que (erradamente) dependam de ver cross-tenant — improvável; a suíte cobre.
- `ClienteDocumentoRepository` (legado) — confirmado vazio (só herança), sem SQL nativo.

## Status de implementação (jun/2026) — ✅ ENTREGUE

- Entidades, controllers, fixtures e migration aplicados. Migration `Version20260625183049`
  rodada no dev (`saas`): os 8 clientes órfãos viraram tenant 4 (fallback de tenant único),
  0 órfãos. `schema:validate` OK em dev e teste. Suíte **730 → 731** verde.
- **Correção desta spec vs. realidade do código:** o `PastaController` **já tinha**
  `setCriadoPor($currentUser)` antes desta frente (linha ~417); o diff só adicionou `setTenant`.
  A FK foi criada `NOT DEFERRABLE` (sem `INITIALLY IMMEDIATE`) — é o que o `migrations:diff`
  gerou e o que `schema:validate` espera; funcionalmente idêntico no Postgres.
- **Provado empiricamente** (além dos testes do repo): `findOneBy(['cpf'])` é escopado pelo
  filtro — sob o tenant B não acha CPF do tenant A. Logo `UniqueEntity`/checagens de duplicidade
  rodam por-tenant, **não** vazam existência cross-tenant (na verdade fecham um oráculo que
  existia antes, quando a checagem era global).

## Follow-ups / limitações conhecidas (NÃO nesta entrega)

1. **`cpf`/`cnpj` com unique GLOBAL no banco × isolamento por tenant (decisão do dono).**
   As colunas `cliente_pf.cpf` e `cliente_pj.cnpj` têm `unique: true` global. Agora que a
   checagem de duplicidade é por-tenant (via filtro), dois escritórios cadastrarem o **mesmo
   cliente (mesmo CPF/CNPJ)** passa na validação da aplicação mas **viola a unique global no
   flush → erro 500** (antes dava "CPF já cadastrado" graciosamente, mas vazando que o CPF
   existia em algum lugar). O correto para multi-tenant é unique **composto `(cpf, tenant)`**
   (a mesma pessoa pode ser cliente de dois escritórios). Exige nova migration (trocar o índice
   unique) — **risco ALTO, decisão + aprovação do dono**. Até lá: colisão cross-tenant de
   CPF/CNPJ é um 500, não um vazamento.
2. **Guard por-id de defesa-em-profundidade (deferido).** O filtro fecha o IDOR enquanto a rota
   tem tenant ativo. Em contexto de tenant nulo (super-admin de plataforma) o filtro fica
   inerte; nenhuma rota de cliente roda sem tenant hoje, mas um `garantirClienteDoTenant`
   explícito fecharia a classe de vez. Opcional.
3. **`PastaRepository` LEFT JOIN em Cliente** (`buildQbByFilters`/`buildQbPorMarcador`): com
   `Cliente` TenantAware o filtro injeta `c_cli.tenant_id = :t` na WHERE, degradando o LEFT
   JOIN a INNER quando há filtro de cliente. Inócuo hoje (a Pasta já isola por `p.tenant` e o
   filtro de cliente exige match), mas registrar caso outra query passe a depender de linhas
   sem cliente.
