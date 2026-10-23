# Restaurar backup (banco e uploads)

Como voltar o banco de produção, e opcionalmente os uploads, a partir de um backup. O script é
`scripts/restore.sh`; o teste dele é `bash scripts/testar-restore.sh` (roda no host, só em
container e volume descartáveis).

Nos exemplos, o banco de produção é `prime` e o container é `jusprime_db_prod`. Confira o
`POSTGRES_DB` do `.env.prod` se tiver dúvida.

## 1. Quando usar

- Dado perdido ou corrompido (exclusão em massa, migration que estragou dado, importação errada).
- Voltar o banco para antes de um deploy: o deploy automático guarda um dump antes de publicar.

Não é para "desfazer um deploy" sozinho: a volta do **código** é o rollback por imagem
(`docs/versionamento.md`, seção 7). Se o banco volta para antes da vX.Y.Z, o código no ar também
precisa ser o de antes, senão a aplicação roda contra um esquema que não conhece.

## 2. Quais backups existem

| Arquivo | Quem gera | Conteúdo |
|---|---|---|
| `/var/backups/jusprime/jusprime_<AAAAMMDD_HHMMSS>.tar.gz` | `scripts/backup.sh` (cron diário, guarda 7) | `database.sql.gz` (pg_dump texto) + `backup_info.txt` |
| `/var/backups/jusprime/uploads_<AAAAMMDD_HHMMSS>.tar` | `scripts/backup.sh` só com `BACKUP_UPLOADS=1` (desligado por padrão) | conteúdo do volume `jusprime_uploads_prod` |
| `/opt/backups/deploy/jusprime-pre-vX.Y.Z-<data>.dump` | deploy automático (guarda 5) | pg_dump formato custom (`-Fc`), só banco |
| `.tar.gz` antigo com pasta `uploads/` dentro | `backup.sh` até a ed05def3 | banco + uploads no mesmo arquivo |

Em produção o backup local de uploads vem **desligado**: os arquivos estão no espelho do Google
Drive. Então o caso comum é restaurar **só o banco** (`--database-only` ou um `.dump`).

## 3. Passo a passo

**1. Pausar o deploy automático** (o dono faz, no GitHub), para nenhuma release publicar no meio:

```bash
gh variable delete DEPLOY_AUTOMATICO --repo sfbdata/Prime
```

O script também se recusa a rodar se um deploy estiver em andamento (usa a mesma trava do
deploy, `/var/lock/jusprime-deploy.lock`).

**2. Parar os serviços** na VPS. O script recusa se o worker estiver rodando (em qualquer modo) e
se o php estiver rodando quando há uploads para trocar:

```bash
docker stop jusprime_worker_prod
docker stop jusprime_php_prod        # obrigatório só se for restaurar uploads
```

**3. Rodar o restore**, na pasta do projeto (`/opt/jusprime`):

```bash
# Só o banco, a partir do backup diário (caso comum)
./scripts/restore.sh --database-only /var/backups/jusprime/jusprime_20261018_020000.tar.gz

# Só o banco, a partir do dump do deploy
./scripts/restore.sh /opt/backups/deploy/jusprime-pre-v2.2.1-20261018T120000Z.dump

# Banco + uploads (procura uploads_<mesmo TS>.tar ao lado; ou informe o caminho)
./scripts/restore.sh /var/backups/jusprime/jusprime_20261018_020000.tar.gz
./scripts/restore.sh /var/backups/jusprime/jusprime_20261018_020000.tar.gz /outro/lugar/uploads.tar
```

O script confere tudo e mostra uma tabela com o banco no ar ao lado do backup (tabelas, linhas
aproximadas, usuários e escritórios). **Olhe a tabela**: um backup com muito menos usuários ou
escritórios que o esperado é o sinal de arquivo errado. Só então digite `CONFIRMO`.

**4. Religar tudo** no fim (o script imprime o comando):

```bash
docker start jusprime_php_prod jusprime_worker_prod
gh variable set DEPLOY_AUTOMATICO --repo sfbdata/Prime --body ligado    # o dono, no GitHub
```

**Espaço em disco.** O script estima e recusa antes de começar se não couber: no disco do docker,
o pico da operação (banco descompactado + uploads novos no volume preparado + uploads atuais na
cópia de volta), com 10% de margem e mais 10MB; no diretório temporário, o `database.sql.gz`. Se o temporário não couber, rode com
`TMPDIR=/outro/disco ./scripts/restore.sh ...`.

## 4. O que o script confere antes de mexer em qualquer coisa

1. Trava do deploy livre; worker parado; php parado se houver uploads.
2. O TAR não tem caminho absoluto, `..` nem link (simbólico ou físico); o formato histórico só
   pode ter `database.sql.gz`, `backup_info.txt` e `uploads/`. Qualquer outra coisa: recusado.
3. `database.sql.gz` existe e o gzip está íntegro; num `.dump`, `pg_restore --list` lista tabelas.
4. Se uploads são esperados e o arquivo não existe, para (não restaura "só metade" sem você pedir).
5. `.env.prod` e o container `jusprime_db_prod` concordam sobre `POSTGRES_DB` e `POSTGRES_USER`.
6. Espaço em disco (seção 3).
7. O dump é restaurado **por inteiro** num banco descartável (`prime_restore_<pid>`); qualquer
   erro de SQL para tudo, e um backup com 0 tabelas é recusado.
8. As permissões no nível do banco, que o `pg_dump` não leva, são copiadas do banco no ar para o
   novo e conferidas: dono, `GRANT ... ON DATABASE` (por exemplo o `CONNECT` do
   `jusprime_leitura`) e `ALTER DATABASE/ROLE ... SET`.
9. Os uploads são extraídos dentro de um container (preserva os donos dos arquivos) num volume
   descartável e contados contra o TAR.

Se qualquer item falhar, o banco e os uploads no ar ficam como estavam, e os descartáveis são
apagados.

Depois do `CONFIRMO`:

1. Uploads: o atual é copiado para um volume de volta (o nome aparece na tela), conferido por
   checksum; depois o preparado entra no lugar e é conferido por checksum.
2. Banco: conexões novas são bloqueadas, as abertas são derrubadas, e os nomes trocam numa
   transação só: `prime` vira `prime_before_restore_<pid>` e o descartável vira `prime`.
3. Se algo falhar nesse trecho e a troca do banco **não** foi gravada, os uploads anteriores são
   devolvidos (e conferidos) e o banco continua o anterior. Se a troca já foi gravada, os uploads
   novos ficam (banco e arquivos continuam do mesmo backup) e o volume de volta é preservado.

## 5. Desfazer (voltar ao banco que estava no ar)

O banco anterior **não é apagado**: fica como `prime_before_restore_<pid>`, e o script o deixa
aceitando conexões (para consulta). No fim da execução o script imprime o comando de desfazer
**já preenchido** com os nomes reais, entre as linhas `# --- desfazer: início ---` e
`# --- desfazer: fim ---`. Com php e worker parados, cole o bloco inteiro no terminal. Ele:

1. bloqueia conexões novas nos **dois** bancos (`prime` e `prime_before_restore_<pid>`);
2. derruba as conexões abertas nos dois e espera esvaziar (até 30s);
3. troca os nomes numa transação: `prime` vira `prime_restaurado_<pid>`, o anterior volta a ser
   `prime`;
4. reabre o `prime`.

No fim, o bloco consulta os bancos e diz o resultado real: `DESFAZER OK`, ou `DESFAZER FALHOU`
(se a troca não aconteceu, o próprio bloco já reabriu os dois bancos). Ele não aborta o terminal
mesmo com `set -e`. Se mesmo assim algum ficar recusando conexão, o script também
imprime o comando de reabrir; ele tem esta forma (pid `12345` de exemplo):

```bash
docker exec -i jusprime_db_prod sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -X -q -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1' <<<'ALTER DATABASE "prime" ALLOW_CONNECTIONS true; ALTER DATABASE "prime_before_restore_12345" ALLOW_CONNECTIONS true;'
```

Depois do desfazer, `prime_restaurado_<pid>` fica com conexões bloqueadas; apague-o com
`dropdb` quando quiser (seção 6).

Uploads não têm desfazer depois de um restore **bem-sucedido**: o volume de volta é apagado no
fim para não ocupar disco. Se precisar dele, faça antes um backup com
`BACKUP_UPLOADS=1 ./scripts/backup.sh`.

## 6. Apagar o banco anterior depois

Quando o sistema estiver conferido (dias depois, se quiser), o script já imprime o comando:

```bash
docker exec jusprime_db_prod sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" dropdb -U "$POSTGRES_USER" prime_before_restore_12345'
```

Para listar os que sobraram de restaurações anteriores:

```bash
docker exec jusprime_db_prod sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d postgres -Atc "SELECT datname FROM pg_database WHERE datname LIKE '\''prime\_%'\''"'
```

## 7. Se algo der errado

- `ROLLBACK DOS UPLOADS FALHOU`: **não apague** o volume `bluejus_restore_<pid>_<ts>_rollback`
  citado na mensagem; ele tem os uploads de antes. Devolva à mão (limpa o destino e copia):

  ```bash
  docker run --rm -v bluejus_restore_<pid>_<ts>_rollback:/source:ro -v jusprime_uploads_prod:/dest alpine \
      sh -ec 'rm -rf /dest/* /dest/.[!.]* /dest/..?* 2>/dev/null || true; cp -a /source/. /dest/'
  ```

- `A troca do banco FOI gravada`: o restore na prática concluiu (banco e uploads novos no ar);
  o volume de volta citado pode ser apagado com `docker volume rm` depois de conferir.
- `Não consegui conferir se a troca do banco foi gravada`: os uploads não foram devolvidos.
  Confira quais bancos existem (`prime`, `prime_before_restore_<pid>`, `prime_restore_<pid>`) e
  decida à mão; os uploads anteriores estão no volume de volta citado.
- `URGENTE: 'prime' não existe`: o banco anterior está no nome `_before_restore_` citado;
  renomeie-o de volta (`ALTER DATABASE "prime_before_restore_<pid>" RENAME TO "prime";`).
- Sobrou banco `prime_restore_<pid>` (execução morta com `kill -9`): é descartável, apague com
  `dropdb`.

O que o teste cobre: formato atual com uploads separados, formato histórico com uploads
embutidos (e dono dos arquivos), `--database-only`, `.dump` do deploy, conexão aberta durante a
troca, permissões de banco replicadas (inclusive `search_path` com vários schemas), o comando
de desfazer impresso (com conexão aberta no banco anterior, e a falha que reabre os dois), cancelamento, uploads
ausentes, artefatos corrompidos, erro de SQL, backup com 0 tabelas, TAR com `..`, caminho
absoluto, link ou conteúdo fora de `uploads/`, falta de disco (host e docker), worker/php
rodando, trava do deploy ocupada, `POSTGRES_USER` divergente, falha depois da troca dos uploads,
falha no meio da troca do banco, falha depois do COMMIT e o gancho de teste desligado fora do
teste.
