# Publicação da Entrega Intermediária 2 — migrations, dry-run e prova de rollback

Data: 07/10/2026. Escopo: `3f199dfd` (produção) → `07b8eb65` (master local), 116 commits.
Investigação somente leitura: produção consultada só por `SELECT` (MCP `jusprime-prod`). Toda escrita
de banco foi feita em bancos descartáveis do dev, apagados no fim. Nenhum código versionado foi
alterado.

**Veredito.**
- As 4 migrations são **somente aditivas**. Nenhuma tem DELETE, UPDATE ou backfill, e tudo nasce NULL
  ou em tabela vazia.
- No volume de produção o `migrate` leva menos de 1 s, com locks de milissegundos.
- Voltar a **imagem** para `3f199dfd` **sem `down()`** é tecnicamente seguro: o código antigo sobe e
  opera com o schema novo.
- **A ressalva semântica é a lixeira.** Tudo que estiver na lixeira no momento do rollback **volta a
  aparecer como vivo**. Por isso o rollback exige uma contagem prévia (§3.3).

---

## 1. As 4 migrations (Tarefa 1)

### 1.1 Estado de produção (MCP, 07/10)
- `doctrine_migration_versions` tem 202 linhas e foi executada pela última vez em 06/10 às 10:47:35.
  As 5 mais recentes são `Version20261006171500`, `161500`, `160000`, `150000` e `134500`.
- Repositório (208) menos produção (202) dá exatamente 6 versões:
  - as **2 do Ponto, conhecidas e pré-existentes**: `Version20260401000000` e
    `Version20260408180237`. Nenhuma está registrada em produção, e as duas têm `skipIf`.
    - Em produção `sede` existe, então a 1ª é pulada.
    - `checklist_item_cliente` não existe, então a 2ª é pulada.
    - Ambas são puladas de novo, sem SQL (provado no dry-run, §2).
  - as **4 desta entrega**: `Version20261006230000`, `Version20261007000000`,
    `Version20261007010000` e `Version20261007020000`.
- **Nenhuma** coluna ou tabela nova existe em produção. A consulta a `information_schema.columns`
  devolveu 0 linhas para `enviado_por_id`, `modificado_em`, `paginas`, `excluido_em` e
  `excluido_por_id` (em `pasta_documento`/`pasta_secao`), para `pasta.checklist_*` e para a tabela
  `pasta_documento_favorito`.
- Volume em produção (`count(*)`):

  | Tabela | Linhas |
  |---|---|
  | `pasta_documento` | 22.565 (9,3 MB com índices; heap 5,9 MB) |
  | `pasta` | 1.202 |
  | `pasta_secao` | 646 |
  | `user` | 15 |
  | `tenant` | 2 |

### 1.2 Tabela

| Versão | Finalidade | O que cria | Aditiva? | Nulidade e default | Tabela afetada (prod) | Lock / custo no PG 15 | DML | `down()` |
|---|---|---|---|---|---|---|---|---|
| `Version20261006230000` (D1) | Quem enviou, quando editou e quantas páginas tem | `pasta_documento.enviado_por_id INT` com FK `FK_69D2B38166B4CF03` → `user` ON DELETE SET NULL e índice `IDX_69D2B38166B4CF03`; `modificado_em TIMESTAMP(0)`; `paginas INT` | Sim | As 3 NULL, sem default | `pasta_documento` (22.565) | 3 `ADD COLUMN` só de metadado (ACCESS EXCLUSIVE instantâneo). A FK valida a tabela (SHARE ROW EXCLUSIVE em `pasta_documento` e `user`) varrendo 22,5 mil linhas, todas NULL: ms. `CREATE INDEX` sem CONCURRENTLY trava escrita em `pasta_documento` por ms. | Nenhuma | Sim (DROP FK/índice/colunas) |
| `Version20261007000000` (D8) | Estado do checklist de documentação | `pasta.checklist_desativado_em TIMESTAMP(0)`; `checklist_desativado_por_id INT` com FK `FK_9B3BBC81D7D34AB0` → `user` SET NULL e índice `IDX_9B3BBC81D7D34AB0`; `checklist_motivo VARCHAR(40)` | Sim | NULL = checklist ativo (o estado de hoje) | `pasta` (1.202) | Idem, em 1.202 linhas: desprezível | Nenhuma | Sim |
| `Version20261007010000` (D2) | Favorito (estrela) de arquivo ou subpasta, por usuário | Tabela nova `pasta_documento_favorito` (`id` identity; `criado_em`, `tenant_id`, `user_id` NOT NULL; `documento_id`/`secao_id` NULL); CHECK `num_nonnulls(documento_id, secao_id) = 1`; 3 índices; 2 UNIQUE `(user_id, documento_id)` e `(user_id, secao_id)`; FKs: `tenant` NO ACTION, `user` CASCADE, `pasta_documento` CASCADE, `pasta_secao` CASCADE | Sim (tabela vazia) | NOT NULL só em tabela nova | 0 (nova) | Índices em tabela vazia. As FKs pegam SHARE ROW EXCLUSIVE breve nas tabelas referenciadas e não validam nada, porque a tabela está vazia. | Nenhuma | Sim (DROP TABLE) |
| `Version20261007020000` (D7) | Lixeira de documentos e subpastas | `excluido_em TIMESTAMP(0)` e `excluido_por_id INT`, com FK → `user` SET NULL e índice, em `pasta_documento` (`FK_69D2B381D6157167`, `IDX_69D2B381D6157167`) e em `pasta_secao` (`FK_C35DDF20D6157167`, `IDX_C35DDF20D6157167`); índices parciais `idx_pasta_documento_lixeira` e `idx_pasta_secao_lixeira` `WHERE (excluido_em IS NOT NULL)` | Sim | NULL = vivo | `pasta_documento` (22.565) e `pasta_secao` (646) | Igual à D1. Os índices parciais nascem vazios (nenhuma linha satisfaz o WHERE), mas a construção ainda varre a tabela: ms. | Nenhuma | Sim |

**Notas de lock.**
- Doctrine Migrations roda cada migration na sua própria transação (sem `all_or_nothing`). Os locks
  ficam presos até o COMMIT de cada uma: entre 34 e 71 ms no volume real (§2.3).
- **Risco residual: fila de lock.** O ACCESS EXCLUSIVE do `ALTER TABLE` espera atrás de qualquer
  consulta longa em `pasta_documento`/`pasta` (o cron do Sync a cada 15 min, ou o worker do Messenger
  ligado durante o deploy), e enquanto espera enfileira todas as outras.
  - Não há `lock_timeout` configurado.
  - O deploy roda em modo manutenção, o que reduz o risco.
  - Recomendação operacional: publicar fora do minuto múltiplo de 15 do cron do Sync.
  - Se o entrypoint parecer parado, conferir `pg_stat_activity` (`wait_event_type = 'Lock'`).
- Não há operação longa: nada reescreve tabela (nenhum `ADD COLUMN ... DEFAULT` volátil, nenhum
  `ALTER TYPE`) e não há backfill.
- Todos os nomes de FK e índice seguem o hash do DBAL. `doctrine:schema:update --dump-sql` no banco
  migrado propõe só `DROP INDEX uniq_cobranca_obrigacao_ref_competencia`, o índice funcional
  pré-existente. A mesma linha aparece no `saas_test`, então não é desta entrega.

---

## 2. Dry-run e migrate num clone com o estado de produção (Tarefa 2)

### 2.1 Como o clone foi montado
1. `CREATE DATABASE saas_testrbprod TEMPLATE saas_test`, o mesmo método de `scripts/frente-abrir.sh`.
2. `doctrine:migrations:execute --down` das 4 versões, em ordem reversa, **só nesse banco**:
   25 queries em 23,9 ms, OK.
3. `doctrine_migration_versions` reescrita para conter **exatamente as 202 versões de produção**,
   com a lista copiada pelo MCP.
4. Conferência:
   - `information_schema` sem nenhuma das colunas ou da tabela;
   - `migrations:status` com 202 executadas, 0 executadas indisponíveis, 6 novas (as 2 do Ponto e
     as 4).

O `saas_test` não tem dado (0 pastas). Para cronometrar com volume, montei um segundo clone,
`saas_testrbvol`, a partir de `saas_ux`: 1.061 pastas, 20.954 documentos e 593 seções, o dataset
real do dev e ~93% do volume de produção. Nele também apliquei os 4 `down()` e depois o `up`.

### 2.2 Saída do `--dry-run` (`saas_testrbprod`, `-vv`), em ordem

```
Migrating (dry-run) up to DoctrineMigrations\Version20261007020000
Version20260401000000 skipped during Pre-Checks. Reason: "Tabelas do módulo ponto eletrônico já existem — migration ignorada."
Version20260408180237 skipped during Pre-Checks. Reason: "Tabelas de checklist/documento não existem neste banco — migration de reestruturação ignorada."
++ Version20261006230000
  ALTER TABLE pasta_documento ADD enviado_por_id INT DEFAULT NULL
  ALTER TABLE pasta_documento ADD modificado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
  ALTER TABLE pasta_documento ADD paginas INT DEFAULT NULL
  ALTER TABLE pasta_documento ADD CONSTRAINT FK_69D2B38166B4CF03 FOREIGN KEY (enviado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE
  CREATE INDEX IDX_69D2B38166B4CF03 ON pasta_documento (enviado_por_id)
++ Version20261007000000
  ALTER TABLE pasta ADD checklist_desativado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
  ALTER TABLE pasta ADD checklist_desativado_por_id INT DEFAULT NULL
  ALTER TABLE pasta ADD checklist_motivo VARCHAR(40) DEFAULT NULL
  ALTER TABLE pasta ADD CONSTRAINT FK_9B3BBC81D7D34AB0 FOREIGN KEY (checklist_desativado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE
  CREATE INDEX IDX_9B3BBC81D7D34AB0 ON pasta (checklist_desativado_por_id)
++ Version20261007010000
  CREATE TABLE pasta_documento_favorito (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id INT NOT NULL, user_id INT NOT NULL, documento_id INT DEFAULT NULL, secao_id INT DEFAULT NULL, PRIMARY KEY (id))
  CREATE INDEX idx_pasta_documento_favorito_tenant_user ON pasta_documento_favorito (tenant_id, user_id)
  CREATE INDEX idx_pasta_documento_favorito_documento ON pasta_documento_favorito (documento_id)
  CREATE INDEX idx_pasta_documento_favorito_secao ON pasta_documento_favorito (secao_id)
  CREATE UNIQUE INDEX uniq_pasta_documento_favorito_user_documento ON pasta_documento_favorito (user_id, documento_id)
  CREATE UNIQUE INDEX uniq_pasta_documento_favorito_user_secao ON pasta_documento_favorito (user_id, secao_id)
  ALTER TABLE pasta_documento_favorito ADD CONSTRAINT chk_pasta_documento_favorito_um_alvo CHECK (num_nonnulls(documento_id, secao_id) = 1)
  ALTER TABLE pasta_documento_favorito ADD CONSTRAINT FK_9C20AB119033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE
  ALTER TABLE pasta_documento_favorito ADD CONSTRAINT FK_9C20AB11A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE
  ALTER TABLE pasta_documento_favorito ADD CONSTRAINT FK_9C20AB1145C0CF75 FOREIGN KEY (documento_id) REFERENCES pasta_documento (id) ON DELETE CASCADE NOT DEFERRABLE
  ALTER TABLE pasta_documento_favorito ADD CONSTRAINT FK_9C20AB114E04F226 FOREIGN KEY (secao_id) REFERENCES pasta_secao (id) ON DELETE CASCADE NOT DEFERRABLE
++ Version20261007020000
  ALTER TABLE pasta_documento ADD excluido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
  ALTER TABLE pasta_documento ADD excluido_por_id INT DEFAULT NULL
  ALTER TABLE pasta_documento ADD CONSTRAINT FK_69D2B381D6157167 FOREIGN KEY (excluido_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE
  CREATE INDEX IDX_69D2B381D6157167 ON pasta_documento (excluido_por_id)
  CREATE INDEX idx_pasta_documento_lixeira ON pasta_documento (excluido_em) WHERE (excluido_em IS NOT NULL)
  ALTER TABLE pasta_secao ADD excluido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
  ALTER TABLE pasta_secao ADD excluido_por_id INT DEFAULT NULL
  ALTER TABLE pasta_secao ADD CONSTRAINT FK_C35DDF20D6157167 FOREIGN KEY (excluido_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE
  CREATE INDEX IDX_C35DDF20D6157167 ON pasta_secao (excluido_por_id)
  CREATE INDEX idx_pasta_secao_lixeira ON pasta_secao (excluido_em) WHERE (excluido_em IS NOT NULL)
finished in 234.7ms, 6 migrations executed, 39 sql queries
[OK] Successfully migrated to version: DoctrineMigrations\Version20261007020000
```

São 31 SQLs desta entrega (5 + 5 + 11 + 10), sem nenhum DML. "6 migrations executed" conta as 2 do
Ponto, que foram puladas e não geraram SQL.

### 2.3 `migrate` de verdade, cronometrado

| Banco | Volume | Por migration | Total do comando | Wall-clock (`time`, com boot do console) |
|---|---|---|---|---|
| `saas_testrbprod` | 0 linhas | — | 265,5 ms | 0,79 s |
| `saas_testrbvol` | 20.954 documentos e 1.061 pastas (~93% de prod) | D1 34,1 ms · D8 70,5 ms · D2 36,2 ms · D7 48,6 ms | 557 ms | 1,12 s |

Nos dois as 2 do Ponto foram puladas de novo. Em produção a estimativa é **< 1 s** para as 4, contra
os 180 s de folga do healthcheck do deploy. O deploy já roda em modo manutenção.

---

## 3. Prova de rollback: `3f199dfd` operando sobre o schema novo (Tarefa 3)

Método do §8 de `docs/specs/trilha-b-designer.md`: voltar só a **imagem**, sem `down()`.

### 3.1 (a) Análise estática do código `3f199dfd`
- **ORM.** O ORM lista só as colunas mapeadas em SELECT, INSERT e UPDATE. As colunas novas aceitam
  NULL e não têm default, então o INSERT antigo grava NULL nelas. Não há coluna nova NOT NULL em
  tabela antiga (as NOT NULL estão só na tabela nova `pasta_documento_favorito`).
- **`SELECT *`.** Não há, em `src/` de `3f199dfd`.
- **SQL cru que toca `pasta_documento`/`pasta_secao`.** Todo ele lista colunas explícitas:
  - `AuditLogRepository` (subselect de ids);
  - `CopiarArquivosAcervoCommand`;
  - `ReconciliadorDePasta` (SELECT de `drive_file_id`, `id`, `caminho_arquivo`, `tenant_id`, `nome`);
  - `PurgarEscritorioUseCase`;
  - `CalcularHashDosDocumentosCommand`.

  Nenhum quebra com coluna a mais. A tabela de favoritos não existe para o código antigo e não há SQL
  cru em `pasta`.

### 3.2 (b) Prova executável
Condições do teste:
- **Worktree descartável:** `.claude/worktrees/rollback-3f199dfd` em `3f199dfd --detach`. O container
  não enxerga o scratchpad (o bind-mount é só o repositório).
- **vendor:** cópia do vendor atual, que é compatível (`composer.json`/`composer.lock` idênticos entre
  `3f199dfd` e `07b8eb65`), seguida de `composer dump-autoload` na worktree.
- **Banco:** `saas_testrbprod` já migrado com as 4, via `TEST_TOKEN=rbprod`.

Resultados:
- **Entrypoint antigo**, `doctrine:migrations:migrate --no-interaction --allow-no-migration` do
  código antigo com as 4 versões registradas e sem arquivo:
  - imprime `[WARNING] You have 4 previously executed migrations in the database that are not
    registered migrations`;
  - pula as 2 do Ponto e termina com `Successfully migrated to version: Version20261006171500`;
  - sai com **exit 0** (e o entrypoint ainda tem `|| true`). Ou seja, **sobe**.
  - `migrations:status` mostra "Executed Unavailable 4" e "New 2".
- **Suíte completa de `3f199dfd` sobre o banco migrado:** **6682 testes, 26523 asserções, 1 falha**,
  em 7m17s.
  - A falha é `App\Tests\Tenant\Functional\PurgaCoberturaSchemaTest::testTodaTabelaTenantScopedEstaCoberta`,
    com a mensagem "Tabela(s) com tenant_id fora do tratamento da purga: pasta_documento_favorito".
  - É uma **guarda de schema** (o teste lista as tabelas com `tenant_id` que conhece), **não erro de
    execução**. É o mesmo padrão do §8.
  - **Controle:** a mesma pasta `tests/Tenant` do código antigo, num banco SEM as 4
    (`saas_testrbctrl`), dá **OK (137 testes, 548 asserções)**.
  - Em execução, a purga antiga completa: os favoritos caem por CASCADE (§3.3).

### 3.3 (c) Semântica no rollback: o que degrada ou reaparece

Hoje, em produção, nada disto existe (colunas e tabela ausentes; 0 menções no formato novo;
0 notificações dos tipos novos). O impacto real depende do que for criado **entre o deploy e um
eventual rollback**.

| Recurso novo | No código antigo | Gravidade |
|---|---|---|
| **Lixeira** (`excluido_em IS NOT NULL`) | O antigo não tem o `LixeiraFilter` (o filtro `lixeira` está em `doctrine.yaml` só no código novo). **Documentos e subpastas na lixeira VOLTAM a aparecer como vivos**, com o arquivo físico intacto, porque a lápide mantém linha e arquivo até a purga. Excluir no antigo é **físico** (linha e arquivo). Na subpasta excluída a subárvore inteira foi carimbada, então ela reaparece inteira. O Sync antigo (`ReconciliadorDePasta`) não tem `excluido_em IS NULL`: **subiria ao Drive** documento da lixeira sem `drive_file_id` (hoje inócuo, porque o Drive está parado com 403) e pode reaproveitar, por nome, uma seção que está na lixeira. Se depois do rollback o usuário criar seção com o mesmo nome de uma da lixeira, não há UNIQUE em `pasta_secao`, e o antigo mostra duas seções homônimas. | **ALTA**: dado que o usuário "apagou" reaparece |
| Checklist desativado (`pasta.checklist_*`) | Ignorado: o checklist volta a aparecer em todas as pastas, e os dados ficam | Baixa |
| Favoritos de documento | A tabela fica, mas some da tela | Baixa |
| `enviado_por`, `paginas`, `modificado_em` | Ignorados. Uploads feitos no antigo ficam com essas colunas NULL, e só `modificado_em` deixa de ser preenchido. | Nenhuma |
| Parcelas do parcelamento | São linhas comuns de `pasta_pagamento` (`PastaPagamento` não mudou) e aparecem como pagamentos normais | Nenhuma |
| Menções `@[Nome](user:ID)` | Gravadas cruas em `pasta_mensagem.conteudo`. O antigo passa o texto pelo filtro `texto_rico` e mostra o token **literal**, sem XSS. | Cosmética |
| Notificações `pasta_resposta_registro` / `pasta_mencao_registro` | `getIcone()` antigo tem `default` (sino), e `getCategoria()` cai em "pessoal". O sino renderiza e a `url` funciona. | Cosmética |
| Auditoria com enum por `->value` | O histórico mostra a string normalmente. O **"desfazer" antigo** passa a string crua ao setter tipado de enum, o que dá **TypeError 500** nessa ação (antes, o formato `{class, id: null}` era pulado em silêncio). Os diffs de `excluidoEm` também viram "sucesso" sem efeito, por falta de setter. | Baixa (ação rara) |
| Purga de escritório antiga com favoritos | `PurgarEscritorioUseCase` antigo apaga `pasta_documento` e `pasta` (que leva `pasta_secao` por CASCADE). Os favoritos caem pelas FKs CASCADE (`documento_id`/`secao_id`), e o CHECK garante que todo favorito tem um dos dois. Depois `garantirTenantVazio` (lista dinâmica por `information_schema`) encontra `pasta_documento_favorito` vazia. **A purga antiga COMPLETA (apaga), não aborta.** É o comportamento correto para uma purga. Os arquivos da lixeira entram em `coletarArquivos`, porque são linhas de `pasta_documento`. | Nenhuma |
| `app:documentos:purgar-lixeira` | Não existe na imagem antiga. Como não há cron (§4), nada quebra. | Nenhuma |

**Mitigação operacional da lixeira (antes de qualquer rollback):**

1. Contar o que está na lixeira (MCP ou `psql` na VPS):

   ```sql
   SELECT 'documento' t, count(*) FROM pasta_documento WHERE excluido_em IS NOT NULL
   UNION ALL SELECT 'secao', count(*) FROM pasta_secao WHERE excluido_em IS NOT NULL;
   ```

2. **Se der 0 nas duas:** o rollback segue sem ressalva semântica.
3. **Se der > 0:** o rollback **espera uma decisão do dono**, com duas saídas aceitáveis.
   - (i) Aceitar que os itens voltem como vivos. Isso equivale a "restaurar tudo" e não perde dado.
   - (ii) Antes de trocar a imagem, rodar no código **novo** `app:documentos:purgar-lixeira` com
     `--dry-run` e depois `--dias 1` para apagar de vez o que está na lixeira.
     - O comando só aceita `--dias` inteiro positivo, então o excluído há menos de 1 dia continua.
     - Isso é destrutivo e irreversível. Só com autorização explícita.
   - A saída (i) é a segura. Nunca resolver com `down()`, que apagaria `excluido_em` e teria o mesmo
     efeito de (i), só que perdendo o registro.
4. Registrar a contagem e a decisão no runbook do deploy.

---

## 4. Nada roda sozinho no deploy (Tarefa 4)

- **Infra.** O diff `3f199dfd..07b8eb65` não toca `Dockerfile`, `docker-compose*.yml`,
  `app/bin/entrypoint*.sh` nem `scripts/`. A única config alterada é `doctrine.yaml`, que registra o
  filtro `lixeira`.
- **Entrypoint de prod** (`app/bin/entrypoint.prod.sh`): faz `cache:clear`, `cache:warmup`, cópia de
  assets e `doctrine:migrations:migrate || true`. Mais nada.
- **`deploy-prod-tls.sh`:** build, modo manutenção, `up -d`, nginx reload, espera do healthcheck e
  `migrate` de confirmação. Mais nada.
- **Comandos de console novos ou alterados:**
  - `app:documentos:purgar-lixeira` (novo). O próprio UseCase diz "sem cron nesta frente".
  - `app:documentos:calcular-hash` ganhou a opção `--paginas`. Não é chamado por nenhum script nem
    config, e produção tem 22.565 documentos com `sha256` NULL: nunca rodou.
- **Agendamento.** Nenhum `AsCronTask`, `AsPeriodicTask` ou `Schedule` em `app/src`. Não há
  `scheduler.yaml`. `messenger.yaml` não mudou e não há Message ou Handler novos.
- **Listeners.** O diff só altera `PastaSomenteLeituraListener` (guarda de escrita por request) e
  `AuditLogSubscriber` (normaliza o enum). Nenhum dos dois roda no boot.
- **Drive e IA.**
  - A "timeline inteligente" é por regras (`RegrasDaTimelineInteligente`), sem provedor de IA nem
    HTTP.
  - O Sync só ganhou o filtro `excluido_em IS NULL`.
  - Não há reparo de 0 byte, hash em massa ou backfill.
- **Fora do alcance desta investigação:** o crontab do host da VPS, que não está no repositório.
  Como nenhum comando novo existe ali antes deste deploy, nada novo pode estar agendado. Mesmo assim,
  convém o dono conferir `crontab -l` na VPS.

---

## 5. Limpeza
Ao final foram removidos com `DROP DATABASE` os bancos `saas_testrbprod`, `saas_testrbctrl` e
`saas_testrbvol`, e a worktree saiu com `git worktree remove --force` (só arquivos não versionados:
vendor e var). Os bancos `saas_test` e `saas_ux` não foram alterados: só serviram de TEMPLATE.
