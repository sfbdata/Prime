# Spec — C3: cpf/cnpj de Cliente únicos por escritório

> Frente **C3** da segurança residual (`followups-seguranca-residual.md`). Risco **MÉDIO**
> (identidade de cliente + **TEM migration** → freio: aplicar só com aprovação do dono; prod =
> bluejus.com.br). Alvo da revisão adversarial (`/review`).

## Problema

`cpf` (`cliente_pf`) e `cnpj` (`cliente_pj`) têm índice unique **global** (`uniq_ab7d86653e3e11f0`
/ `uniq_a2cbca4ec8c6906b`). Como o JusPrime é multi-escritório, o **primeiro** escritório que
cadastra um documento o "tranca" no sistema inteiro: outro escritório que tente cadastrar o mesmo
cliente leva erro no INSERT (SQLSTATE 23505 → 500). A validação de formulário (`UniqueEntity`) já é
escopada por tenant via `TenantFilter` (não vaza "já cadastrado" cross-tenant), mas a trava do
banco é global. Cenário real: a mesma pessoa/empresa é cliente de 2 escritórios.

## Bloqueador estrutural (por que não é igual a Processo/NUP)

`Cliente` usa **herança JOINED**: `tenant_id` mora na base `cliente`; `cpf`/`cnpj` moram em
`cliente_pf`/`cliente_pj` — tabelas distintas. Um unique composto `(tenant_id, cpf)` exige as duas
colunas na **mesma** tabela; JOINED não permite sem denormalizar `tenant_id` nas subclasses (o que
brigaria com o Doctrine — coluna não-mapeável duas vezes, exigiria trigger). Por isso o padrão
limpo de S3 (`numero_processo`) e P1 (`nup`) **não transfere**.

## Decisão (Opção B, escolhida pelo dono)

Unicidade **por escritório na aplicação**, sem trava no banco:

1. **Remover o unique global do banco** (migration `Version20260626150000`: `DROP INDEX` dos dois).
2. **Validação escopada por tenant:** `ClientePF` → `#[UniqueEntity(fields: ['cpf', 'tenant'])]`;
   `ClientePJ` → `#[UniqueEntity(fields: ['cnpj', 'tenant'])]`. Remover `unique: true` da `#[ORM\Column]`
   de cpf/cnpj (o mapeamento passa a refletir o banco sem índice).
3. **`setTenant` antes da validação** em `ClienteController::newPF/newPJ` — sem isso o `UniqueEntity`
   validaria contra `tenant = null` (tenant ainda não setado) e seria inócuo. Com o tenant presente,
   o escopo funciona inclusive com o `TenantFilter` desligado (super-admin/CLI).
4. **Segundo caminho de criação — `PastaController::novoCliente`** (rota `pasta_cliente_novo`, AJAX):
   não usa `UniqueEntity`, faz checagem manual via `findOneBy`. Antes do C3, a trava de banco
   protegia esse caminho; ao removê-la, a checagem passou a depender só do `TenantFilter` de sessão.
   Fix (mesmo princípio do projeto — não confiar no filtro): `setTenant` antes da checagem +
   `findOneBy(['cpf'|'cnpj', 'tenant' => $tenant])` escopado explicitamente.

**Comportamento resultante:** mesmo cpf/cnpj **proibido** dentro do mesmo escritório (validação);
**permitido** entre escritórios diferentes (cadastros independentes). **Custo aceito:** sem garantia
no nível do banco — uma corrida ou INSERT direto poderia duplicar dentro do mesmo tenant (improvável,
recuperável; nunca cross-tenant).

## Migration (FREIO — apresentar antes de aplicar)

`Version20260626150000` — só estrutural, sem backfill, sem dados tocados:
- `up()`: `DROP INDEX uniq_ab7d86653e3e11f0` (cpf) + `DROP INDEX uniq_a2cbca4ec8c6906b` (cnpj).
- `down()`: recria os dois unique GLOBAIS (falha de propósito se já houver o mesmo cpf/cnpj em
  tenants diferentes — rollback após cadastros cross-tenant não é seguro).

**Aplicação no dev (após aprovação):** `migrations:execute 'DoctrineMigrations\Version20260626150000' --up`
ISOLADA (NUNCA `migrate` puro — 2 migrations antigas de Ponto fora do ledger) → `doctrine:schema:update
--force --env=test` → suíte completa. **Dev:** 1 tenant, 5 PF + 3 PJ, 0 duplicados → seguro.

**Pré-deploy prod:** conferir que NÃO há cpf/cnpj duplicado dentro do MESMO tenant (a remoção do
unique não cria trava nova que pegue isso). Duplicados cross-tenant passam a ser permitidos (objetivo).

## Testes (cross-tenant)

`tests/Cliente/Functional/ClienteUnicidadePorTenantTest.php`:
1. **Mesmo cpf em 2 tenants → permitido** (antes dava erro de DB): cadastrar via fluxo a mesma PF em
   A e em B → ambos persistem, isolados.
2. **Mesmo cpf no mesmo tenant → rejeitado** pela validação (`UniqueEntity`).
3. Idem cnpj (PJ).

Confirmar que o teste (1) **falharia** com o unique global ainda presente (prova que a migration é
necessária) e que (2) falha se o `UniqueEntity` não estiver escopado.

`tests/Cliente/Functional/ClienteUnicidadeViaPastaControllerTest.php` cobre o **segundo caminho**
(`pasta_cliente_novo`, via HTTP): mesmo cpf/cnpj permitido em pastas de tenants diferentes,
bloqueado no mesmo tenant. **Nota honesta:** como o request HTTP roda com o `TenantFilter` ligado,
esse teste prova a **necessidade da migration** (a trava unique do banco NÃO passa pelo filtro — sem
a migration o 2º insert cross-tenant estoura no banco mesmo com o filtro ligado), e o
**comportamento** do caminho. O escopo explícito por tenant no `findOneBy` é defesa-em-profundidade
(robusto a filtro desligado) que o teste HTTP não isola.

**Mutação confirmada (✓):** recriando os 2 unique globais no `saas_test`, os 4 testes cross-tenant
(ambas as classes) quebram (violação de PK/unique); removidos, voltam a verde.

## Fora de escopo

- Opção A (denormalizar tenant_id + composto no banco) — descartada pelo dono.
- Guard por-id de Cliente (já coberto pelo `TenantFilter`/S2).
