# BlueJus Voto: as redes de "dado do escritório" passam a olhar o schema `voto`

Spec da frente `voto-redes` (09/11/2026). Cumpre a "Condição antes da M3" de `docs/specs/bluejus-voto.md`.
**Risco ALTO**: mexe na purga do escritório (identidade de Tenant). Revisão read-only, correção e re-revisão
antes do PR. Sem migration.

## 1. Problema

Quatro redes conferem "todo dado do escritório" e só olham o schema `public`. Uma tabela `voto.*` com
`tenant_id` passaria por elas sem aviso. Hoje nada escapa: a única tabela, `voto.liberacao`, sai com o
escritório pelo `ON DELETE CASCADE` da FK para `tenant`. Da M3 em diante as tabelas do Voto **não** têm FK
para `tenant` (D1-B): o banco nunca vai barrar a purga, e a conferência `garantirTenantVazio` passa a ser a
única rede de conferência. Ela tem que enxergar o `voto` antes da primeira tabela da votação. Ela não pega
escrita concorrente; isso fica como condição da M3 (seção 5).

## 2. Regra comum

- **Descoberta pelo catálogo** (`pg_class`, `pg_namespace`, `pg_attribute`), não pelo `information_schema`.
  O `information_schema` esconde a tabela em que o usuário da conexão não tem privilégio; o catálogo mostra
  todas.
- **Schemas da aplicação**: todos menos os do sistema:
  `n.nspname <> 'information_schema' AND n.nspname NOT LIKE 'pg\_%'` (tira `pg_catalog`, `pg_toast`,
  `pg_temp_*`). Precedente: `scripts/restore.sh`. Tabelas comuns e particionadas: `relkind IN ('r','p')`.
- **Nome**: no `public`, sem prefixo (`tenant_role`), para não mexer em `ORDEM_DELECAO`, `TABELAS_RETIDAS`
  e `COBERTAS_POR_CASCATA`. Fora dele, qualificado (`voto.liberacao`).
- Nome que vem do catálogo e entra num SQL montado passa por `quoteIdentifier` (o Doctrine cita cada parte
  do nome qualificado).

## 3. Mudanças

| # | Onde | O que muda |
|---|---|---|
| R1 | `app/src/Tenant/UseCase/PurgarEscritorioUseCase.php` | `tabelasComTenantId` usa a regra comum, numa constante (`SQL_TABELAS_COM_TENANT_ID`) que os testes também usam. **No mesmo commit**, `['voto.liberacao', 'tenant_id = :tenant']` entra na Fase 3 da `ORDEM_DELECAO`: sem isso, a conferência roda antes do `DELETE FROM tenant`, acha a linha que só sairia pelo CASCADE e trava a purga de todo escritório liberado. O `COUNT` da conferência cita o nome com `quoteIdentifier`. Docblock atualizado (contagem de FKs, "via information_schema"). O dry-run passa a contar `voto.liberacao`. |
| R2 | `app/tests/Tenant/Functional/PurgaCoberturaSchemaTest.php` | A descoberta usa a constante da R1. O teste das pontes (filha sem `tenant_id` que aponta para pai com `tenant_id`) compara schema e nome, nos dois lados. |
| R3 | `app/tests/Tenant/Functional/PurgarEscritorioUseCaseTest.php` | `tabelasTenant()` usa a constante da R1 (a asserção "nada sobra" deixa de ser cega ao `voto`). `semear()` grava `voto.liberacao` para os escritórios A e B. |
| R4 | `scripts/testar-paralelo.sh` | A impressão digital do banco (colunas e índices) cobre todos os schemas da aplicação, com o schema no texto e `ORDER BY schemaname, indexname` (nome de índice pode repetir entre schemas). Efeito esperado: os bancos `_P*` são reclonados uma vez. |
| R5 | `app/src/Mcp/Tool/DescreverEsquemaTool.php` | Lista e descreve pelo catálogo, com a regra comum. `descrever('voto.x')`: separa no ponto (sem ponto, schema `public`) e valida contra a lista. Colunas por `pg_attribute`, `format_type` e `pg_get_expr`; chaves e índices filtrados por schema e nome. A mensagem de erro deixa de citar "schema public". **Continua só leitura**: nenhuma permissão nova para a role do MCP (`jusprime_leitura`); dar leitura do `voto` a ela é decisão do dono, fora desta frente. |
| R6 | `app/src/Mcp/Service/ConexaoLeitura.php` | A checagem "a role pode escrever?" (`SQL_PODE_ESCREVER`) olha todos os schemas da aplicação, não só o `public` (um `GRANT voto_acesso TO jusprime_leitura` daria escrita no `voto` sem alarme). O docblock deixa de listar "qualquer schema além de public" como fora da cobertura. A cópia em `ConexaoLeituraTest` acompanha. |

Já corretas, sem mudança: `TenantAwareCoberturaTest` e `AuditavelCoberturaTest` (leem o mapeamento do
Doctrine), `backup.sh` e `backup-db.sh` (`pg_dump` do banco inteiro), `restore.sh` (já olha todos os schemas),
`MigracaoDoPapelDoVotoTest` (o `public` ali é de propósito).

## 4. Provas por teste

As provas 1 a 4 rodam dentro da transação do DAMA, na mesma conexão do teste. A prova 5 roda fora dela,
porque a conexão nova da role de teste precisa ver os GRANT confirmados; ela segue o precedente da
`jusprime_leitura_teste` (DSN pelo `BancoDeLeituraDeTeste`, role criada só se não existir) e revoga no
`finally` o que deu no `voto`; o SELECT no `public` e o CONNECT da role ficam, como no precedente. Os GRANT são por banco, então duas frentes ou dois processos do ParaTest não colidem.

1. **Purga apaga o `voto`**: o teste completo da purga prova que `voto.liberacao` do escritório A sai; o de
   isolamento prova que a do B fica.
2. **Conferência acusa o que sobrou no `voto`**: clone do `testGuardAntiDriftAborta` com
   `CREATE TABLE voto._drift_teste (id serial PRIMARY KEY, tenant_id int NOT NULL)` e uma linha do escritório.
   Espera `RuntimeException` citando `voto._drift_teste`, com o escritório preservado (rollback). Sem a R1 este
   teste fica vermelho: sem FK, a purga passaria.
3. **Cobertura**: `CREATE TABLE voto._cobertura_teste (tenant_id int)` aparece como "não coberta";
   `voto._ponte (pai_id int REFERENCES voto.liberacao(id))` sem `tenant_id` aparece como ponte.
4. **MCP**: o teste do `DescreverEsquemaTool` usa conexão separada, sem permissão no `voto` (igual à produção).
   Usa a `voto.liberacao` real: aparece na lista, e `descrever('voto.liberacao')` traz a coluna `tenant_id` e a
   FK para `tenant` com `ON DELETE CASCADE`. Uma tabela inexistente (`voto.nao_existe`) continua recusada.
5. **ConexaoLeitura**: role com escrita só numa tabela do `voto` é acusada.
6. **Impressão digital** (sem teste automático): no banco da frente, `BEGIN`; digital; `CREATE INDEX` em
   `voto.liberacao`; digital; `ROLLBACK`. As duas digitais diferem. O resultado vai no PR.

## 5. Cuidados para as próximas migrations do Voto

- Cada tabela da M3 em diante entra na `ORDEM_DELECAO` (filha antes do pai) ou em `TABELAS_RETIDAS`.
  Reter ata, boletim e trilha da eleição é decisão de produto, na M3.
- Todo `DELETE` do `voto` leva `tenant_id = :tenant` explícito.
- **Escrita concorrente com a purga** (revisão de 09/11/2026). A purga trava a linha do escritório e conta
  com a FK para que um INSERT concorrente espere o COMMIT e falhe. Tabela do `voto` sem FK para `tenant` não
  espera nem falha: uma linha confirmada depois do `garantirTenantVazio` e antes do COMMIT fica órfã. Hoje
  não acontece (a `voto.liberacao` tem FK com CASCADE). Condição da M3: toda escrita no `voto` se serializa
  com a purga. Caminho sugerido: toda tabela da votação liga por FK, direta ou em cadeia, a uma raiz que a
  purga apaga (evento ou eleição), e a raiz é criada no BlueJus com `SELECT ... FOR KEY SHARE` na linha do
  escritório (ativo) na mesma transação. Em READ COMMITTED, quem espera a purga e acha a linha apagada ou
  inativa recebe 0 linhas, sem erro: com 0 linhas a criação da raiz desiste, senão a raiz nasce órfã. O portal (`voto_acesso`) não lê `tenant`: a serialização dele vem
  da FK para a raiz, que a purga trava ao apagar.
- M7 (`voto.foto_do_eleitor`): os arquivos não são descobertos pelo catálogo. Precisam de entrada explícita em
  `ARQUIVOS_POR_REGISTRO`.

## 6. Aceite

- R1 a R6 aplicadas; provas 1 a 5 verdes; prova 6 descrita no PR.
- `tests/Tenant`, `tests/Mcp`, `tests/Voto` e `tests/Arquitetura` verdes; `scripts/qa.sh` ok.
- Revisão read-only sem achado aberto, e re-revisão depois das correções.
