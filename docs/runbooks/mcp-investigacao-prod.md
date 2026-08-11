# Runbook: subir o MCP de investigação (somente leitura) em produção

Para quem não acompanhou a implementação: isto sobe um servidor MCP (`php bin/console
mcp:server`) que expõe o banco do JusPrime ao Claude Code **só para leitura** — duas
ferramentas, `consultar_sql` (roda um `SELECT`, até 500 linhas, com timeout de 15s) e
`descrever_esquema` (lista tabelas/colunas). O servidor fala STDIO: o dono chama o comando via
SSH + `docker exec`, e o Claude Code local conversa com ele por stdin/stdout.

A tranca de "somente leitura" **não é o código** — é o usuário do PostgreSQL. O
`ConexaoLeitura` roda `SET default_transaction_read_only = on` como cinto e suspensório, mas
quem impede escrita de verdade é a role do banco, criada no Passo 1. Por isso o Passo 1.1
abaixo não é opcional.

Execute os passos **nessa ordem**. Os passos 1, 2 e 5 rodam na VPS ou na sua máquina — nenhum
deles passa pelo Claude Code (o SSH para a VPS está bloqueado para o agente por classificador
de segurança desde uma sessão anterior).

---

## 1. Criar a role de leitura no PostgreSQL de produção

Na VPS, dentro do container do banco:

```bash
docker exec jusprime_db_prod psql -U jusprime -d prime -c "
CREATE ROLE jusprime_leitura LOGIN PASSWORD 'TROQUE_ESTA_SENHA';
GRANT CONNECT ON DATABASE prime TO jusprime_leitura;
GRANT USAGE ON SCHEMA public TO jusprime_leitura;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO jusprime_leitura;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO jusprime_leitura;
"
```

Troque `TROQUE_ESTA_SENHA` por uma senha de verdade antes de rodar — e guarde-a, ela volta a
aparecer no Passo 2.

### 1.1. Conferir que ninguém além do dono consegue criar tabela no schema `public`

Este passo **não estava no plano original** e existe por um motivo concreto: se o schema
`public` conceder `CREATE` a `PUBLIC` (o pseudo-papel que representa "todo mundo"), a role
`jusprime_leitura` — apesar de só ter `SELECT` nas tabelas existentes — consegue criar uma
tabela **própria** dentro de `public` e gravar nela à vontade. Isso não fura o `SELECT`
existente, mas fura a promessa de "somente leitura" da conexão como um todo.

No cluster de **dev** isso foi medido e está seguro (`nspacl` = `{pg_database_owner=UC/
pg_database_owner,=U/pg_database_owner}` — `PUBLIC` só tem `USAGE`, não `CREATE`). Produção
**não foi conferida** por este agente — o SSH para a VPS está bloqueado. Rode:

```bash
docker exec jusprime_db_prod psql -U jusprime -d prime -tAc "SELECT nspacl FROM pg_namespace WHERE nspname='public';"
```

**Resposta seguro:** `PUBLIC` aparece só com `U` (`USAGE`), como no exemplo de dev acima, ou
nem aparece no ACL (nesse caso o Postgres já nega `CREATE` a `PUBLIC` por padrão nesse cluster).

**Resposta insegura:** `PUBLIC` aparece com `C` no meio (ex.: `=UC/pg_database_owner`). Se vier
assim, rode antes de seguir para o Passo 2:

```bash
docker exec jusprime_db_prod psql -U jusprime -d prime -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC;"
```

E rode a consulta de novo para confirmar que o `C` sumiu.

---

## 2. Definir a variável no `.env.prod` da VPS

O build de produção faz `rm .env` (é imagem *baked*, não lê o `.env` versionado do repo) — por
isso esta variável só pode morar no `.env.prod` que já vive na VPS, nunca no repositório:

```
DATABASE_URL_LEITURA="pgsql://jusprime_leitura:TROQUE_ESTA_SENHA@db:5432/prime"
```

Use a mesma senha do Passo 1. Sem esta variável, `ConexaoLeitura` recusa subir com uma
mensagem clara (ver seção "se der errado" abaixo) — o servidor MCP não cai silenciosamente em
nenhuma conexão administrativa por acidente.

---

## 3. Criar o alias SSH (na sua máquina local)

Em `~/.ssh/config`:

```
Host bluejus
    HostName 72.60.146.89
    User root
    IdentityFile ~/.ssh/id_ed25519
    ServerAliveInterval 30
```

`ServerAliveInterval 30` evita que a sessão caia por inatividade enquanto o MCP fica esperando
a próxima pergunta.

---

## 4. Deploy

O código do comando `mcp:server` e a dependência `mcp/sdk` só existem em produção depois de um
deploy — a imagem de produção é *baked* (o `git pull` sozinho no container antigo não aplica
nada, é preciso reconstruir a imagem):

```bash
# Execute manualmente no terminal externo, dentro da VPS
./scripts/deploy-prod-tls.sh
```

---

## 5. Registrar o servidor no Claude Code (na sua máquina local)

```bash
claude mcp add jusprime-prod -- ssh bluejus "docker exec -i -w /var/www/app jusprime_php_prod php bin/console mcp:server"
```

`-i` sem `-t`: o MCP não precisa de pseudo-terminal, só de stdin/stdout ligados. `-w /var/www/app`
é obrigatório: a imagem de prod fixa `WORKDIR /var/www` (`Dockerfile`, estágio `prod`), e o
`cd /var/www/app` do `entrypoint.prod.sh` só vale para o processo do `ENTRYPOINT` — uma sessão
nova de `docker exec` não herda esse `cd`. Sem `-w`, o comando roda em `/var/www`, onde não existe
`bin/console` (ele mora em `/var/www/app/bin/console`).

---

## Conferir que funcionou

Numa conversa com o Claude Code, com o servidor `jusprime-prod` registrado:

### 0. Conferir COM QUEM a conexão está falando (faça este primeiro)

```
Peça ao Claude Code para chamar consultar_sql com:
  SELECT current_user, has_table_privilege(current_user,'tenant','INSERT') AS pode_escrever;
Esperado: jusprime_leitura | false
```

Esta conferência existe porque "a leitura funciona" **não distingue a role certa da errada** —
os dois passos abaixo respondem exatamente igual com o DSN administrativo, e é com ele que a
promessa de somente-leitura deixa de existir.

**Se vier qualquer outra coisa** (outro `current_user`, ou `pode_escrever = true`): pare. O
`DATABASE_URL_LEITURA` do **Passo 2** está apontando para o usuário errado — corrija lá, refaça
o deploy do Passo 4 (a variável só é lida na inicialização do container) e repita esta
conferência.

Na prática o servidor já não deixa passar: desde a última correção, `ConexaoLeitura` faz essa
mesma verificação ao abrir a conexão e **recusa subir** com um usuário que tenha escrita
(mensagem: "DATABASE_URL_LEITURA aponta para um usuário com permissão de ESCRITA…"). Se você
receber esse erro em vez de um resultado, o diagnóstico é o mesmo: Passo 2.

### 1 e 2. Conferir que a leitura responde

1. Peça para chamar `descrever_esquema` **sem argumento**. Esperado: lista de tabelas do
   schema `public` de produção (com colunas e tipos).
2. Peça para chamar `consultar_sql` com `SELECT count(*) FROM tenant`. Esperado: um número
   plausível de escritórios cadastrados, sem erro.

Se os dois passos acima responderem, a conexão de leitura está funcionando ponta a ponta.

### Onde olhar o log das consultas

Cada chamada a `consultar_sql` grava uma linha (sucesso ou erro) no canal Monolog `mcp`, que
em produção vai para arquivo, dentro do container:

```bash
docker exec -w /var/www/app jusprime_php_prod tail -f var/log/mcp.log
```

(mesmo motivo do `-w` no Passo 5: `var/log/mcp.log` é um caminho relativo, e sem `-w /var/www/app`
o `docker exec` cai em `/var/www`, onde esse caminho não existe.)

**Este arquivo mora dentro do container e um redeploy o apaga.** Não é registro permanente —
é trilha de investigação, útil enquanto a sessão de investigação está em andamento ou logo
depois dela. Se precisar de histórico que sobreviva a um redeploy, copie o arquivo para fora
do container antes de rodar o deploy seguinte.

---

## Se der errado

### "Could not open input file: bin/console"

Este é o erro mais provável na primeira tentativa, antes de qualquer coisa relacionada ao MCP
em si. A imagem de produção fixa `WORKDIR /var/www`, mas o código do Symfony mora em
`/var/www/app` — o `cd /var/www/app` do `entrypoint.prod.sh` só vale para o processo iniciado
pelo `ENTRYPOINT` do container, uma sessão nova de `docker exec` não herda esse diretório. Se
qualquer comando `docker exec ... php bin/console ...` deste runbook devolver esse erro,
faltou `-w /var/www/app` no `docker exec` (todos os comandos acima já incluem; se você copiou
de outro lugar ou digitou à mão, confira essa flag primeiro).

### "DATABASE_URL_LEITURA não está configurada"

Essa é a mensagem de `ConexaoLeitura` quando a variável do Passo 2 não chegou ao processo —
falta no `.env.prod` da VPS, ou o deploy do Passo 4 rodou antes da variável ser definida (nesse
caso, defina a variável e refaça o deploy; ela não é lida em tempo real, só na inicialização do
container). Confira com:

```bash
docker exec jusprime_php_prod bash -c 'echo $DATABASE_URL_LEITURA'
```

Se vier vazio, o problema é este.

### "o servidor falhou" (sem mais detalhe nenhum)

Essa é a mensagem genérica que o cliente MCP mostra quando o `stdout` do servidor tem qualquer
byte que não seja JSON-RPC — o handshake não consegue ser decodificado e o cliente desiste sem
dizer o motivo. Para diagnosticar, rode o comando à mão, fora do Claude Code, e alimente o
handshake pelo stdin:

```bash
ssh bluejus 'docker exec -i -w /var/www/app jusprime_php_prod php bin/console mcp:server' <<'EOF'
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"diagnostico","version":"1.0.0"}}}
{"jsonrpc":"2.0","method":"notifications/initialized"}
{"jsonrpc":"2.0","id":2,"method":"tools/list"}
EOF
```

Olhe a saída linha a linha: toda linha deve começar com `{"jsonrpc":"2.0"`. Qualquer outra
coisa — um aviso do PHP, uma linha de log, um `Warning: ...` — é a sujeira que está quebrando o
protocolo. Note que rodar assim, num terminal, mistura stdout e stderr na mesma tela: se a
linha estranha for de log, ela normalmente é formatada pelo Symfony (`[timestamp] mcp.INFO
...`) e é fácil de distinguir de uma linha JSON; se não conseguir distinguir visualmente, repita
redirecionando a saída de erro para fora (`2>/tmp/stderr.log`) e confira o `stdout` sozinho.

---

## Ordem resumida

1. Role de leitura no Postgres + conferir `CREATE` de `PUBLIC` no schema `public` (1 e 1.1)
2. `DATABASE_URL_LEITURA` no `.env.prod` da VPS (2)
3. Alias SSH local (3)
4. Deploy (4)
5. Registrar no Claude Code (5)
6. Conferir a identidade da conexão (`current_user` + `pode_escrever`) e só então
   `descrever_esquema` + `SELECT count(*) FROM tenant`
