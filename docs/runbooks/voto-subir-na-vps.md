# Runbook: subir o BlueJus Voto na VPS (voto.bluejus.com.br)

Para quem não acompanhou a implementação: o Voto roda com o mesmo código do BlueJus, mas num container PHP
próprio (`jusprime_voto_prod`), com endereço próprio (`voto.bluejus.com.br`) e usuário de banco próprio
(`voto_app`), para uma eleição não deixar o BlueJus lento (spec `docs/specs/bluejus-voto.md`, D1 e D2, fase A2).

Tudo já está no repositório e **nada liga sozinho**: o serviço `voto` do `docker-compose.prod.yml` só existe com o
profile `voto`, e o bloco do nginx está guardado como `scripts/vps/vhosts/voto.conf.https-pendente`. Ligar é este
passo a passo, feito pelo Samuel, na ordem.

Nenhuma senha aparece neste documento. A senha do `voto_app` só existe no `.env.prod` da VPS.

Todos os comandos rodam na VPS, na pasta do projeto (`/opt/jusprime`), salvo quando o passo diz outra coisa.

---

## 0. Antes de começar

A migration `Version20261109160000` precisa já estar em produção (ela cria o papel `voto_acesso`, que agrupa as
permissões do Voto). Confira:

```bash
docker exec jusprime_db_prod sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "SELECT rolname FROM pg_roles WHERE rolname = '\''voto_acesso'\''"'
```

**Esperado:** `voto_acesso`. Se vier vazio, a versão com a fase A2 ainda não foi publicada: pare aqui.

---

## 1. Criar o endereço no DNS (Hostinger)

1. Descubra o IP da VPS, na própria VPS:

   ```bash
   getent hosts bluejus.com.br
   ```

   O número da esquerda é o IP. O Voto usa o mesmo.

2. Abra <https://hpanel.hostinger.com/domains>.
3. Clique em **bluejus.com.br**.
4. No menu da esquerda, clique em **DNS / Nameservers**.
5. Na aba **Registros DNS**, preencha o formulário **Gerenciar registros DNS**:
   - **Tipo:** `A`
   - **Nome:** `voto`
   - **Aponta para:** o IP do item 1
   - **TTL:** deixe o padrão
6. Clique em **Adicionar registro**.

Se a tela da Hostinger tiver mudado de nome, procure por "Registros DNS" no domínio bluejus.com.br.

## 2. Conferir o DNS

Espere alguns minutos e rode na VPS:

```bash
getent hosts voto.bluejus.com.br
```

**Esperado:** o mesmo IP do bluejus.com.br. Enquanto não vier, não siga: o certificado do passo 3 falharia e
gastaria tentativa no limite do Let's Encrypt.

## 3. Emitir o certificado

Como root (o mesmo usuário do cron das 3h que renova os outros certificados):

```bash
certbot certonly --webroot -w /opt/jusprime/certbot/www -d voto.bluejus.com.br --key-type ecdsa
```

O desafio do Let's Encrypt passa pelo bloco da porta 80 que já está no ar (o mesmo caminho usado no sign).
Confira:

```bash
ls /etc/letsencrypt/live/voto.bluejus.com.br/
```

**Esperado:** aparecem `fullchain.pem` e `privkey.pem`. O cron das 3h passa a renovar este certificado junto com
os outros, sem configuração nova.

## 4. Criar o usuário do banco do Voto (`voto_app`)

1. Gere uma senha só de letras e números (assim ela não precisa de escape dentro da URL do banco):

   ```bash
   openssl rand -hex 24
   ```

   Copie o resultado para o seu gerenciador de senhas. Ele não fica gravado em lugar nenhum da VPS.

2. Entre no banco:

   ```bash
   docker exec -it jusprime_db_prod sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
   ```

3. Dentro do `psql`, rode as três linhas abaixo, uma de cada vez:

   ```sql
   CREATE ROLE voto_app LOGIN CONNECTION LIMIT 20 IN ROLE voto_acesso;
   GRANT CONNECT ON DATABASE :"DBNAME" TO voto_app;
   \password voto_app
   ```

   O `\password` pede a senha duas vezes: cole a senha do item 1 nas duas. Ela não aparece na tela nem fica no
   histórico do terminal.

4. Confira e saia:

   ```sql
   SELECT rolname, rolcanlogin, rolconnlimit FROM pg_roles WHERE rolname = 'voto_app';
   \q
   ```

   **Esperado:** `voto_app | t | 20`.

O que o `voto_app` pode: ler e gravar só nas tabelas do schema `voto` que as próximas fases criarem, e ler a
`recurso_estado` (a chave **Voto** do Painel do Master, para o endereço mostrar "em preparação" enquanto ela estiver
desligada). Não lê mais nenhuma tabela do BlueJus e não mexe na `voto.liberacao` (que é do Master). O `CONNECTION LIMIT 20` impede uma
eleição de tomar as conexões do banco do BlueJus (D2).

## 5. Acertar o `.env.prod`

O container do Voto **não** recebe o `.env.prod` inteiro. Ele só vê o que está escrito no serviço `voto` do
`docker-compose.prod.yml`: o banco vem da `VOTO_DATABASE_URL`, a chave secreta da `VOTO_APP_SECRET` e o resto
(endereço, hosts aceitos, e-mail desligado) já vem fixo no compose. Senha do banco do BlueJus, chaves do R2, da
Anthropic e do Google nunca chegam ao Voto. Por isso o `SYMFONY_TRUSTED_HOSTS` do `.env.prod` não muda.

Abra o arquivo:

```bash
nano .env.prod
```

1. **`VOTO_DATABASE_URL`:** copie a linha `DATABASE_URL=...` que já existe, cole logo abaixo e, na cópia:
   - troque o nome `DATABASE_URL` por `VOTO_DATABASE_URL`;
   - troque o usuário (o que vem depois de `://`) por `voto_app`;
   - troque a senha (o que vem depois de `voto_app:` até o `@`) pela senha do passo 4.

   O resto (servidor `db`, porta, nome do banco e parâmetros) fica igual.

2. **`COMPOSE_PROFILES`:** acrescente uma linha nova:

   ```
   COMPOSE_PROFILES=voto
   ```

3. **NUNCA** ponha `APP_PAPEL` no `.env.prod`. O papel `voto` é dado só ao container do Voto, dentro do
   `docker-compose.prod.yml`. No `.env.prod` ele valeria também para o container do BlueJus, que passaria a
   responder 404 em todas as telas no próximo deploy.

Salve (`Ctrl+O`, `Enter`) e saia (`Ctrl+X`).

4. **`VOTO_APP_SECRET`:** é a chave secreta só do Voto (não reaproveite a `APP_SECRET` do BlueJus). Gere e grave
   direto no arquivo, sem que ela apareça na tela:

   ```bash
   printf '\nVOTO_APP_SECRET=%s\n' "$(openssl rand -hex 32)" >> .env.prod
   ```

   Rode **uma vez só**. Se rodou duas vezes, abra o `nano .env.prod` e apague uma das linhas `VOTO_APP_SECRET`.

Confira só os nomes, sem mostrar valores:

```bash
cut -d= -f1 .env.prod | grep -E '^(VOTO_DATABASE_URL|VOTO_APP_SECRET|COMPOSE_PROFILES|APP_PAPEL)$'
```

**Esperado:** `VOTO_DATABASE_URL`, `VOTO_APP_SECRET` e `COMPOSE_PROFILES`, uma vez cada, e nenhum `APP_PAPEL`.

## 6. Conferir que o compose enxerga o Voto

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod config --services
```

**Esperado:** a lista tem `voto` (além de `php`, `worker`, `scheduler`, `nginx`, `db` e `blog_builder`). Sem o
`COMPOSE_PROFILES=voto`, o `voto` não aparece.

Confira também que o banco do Voto chegou com o usuário certo (mostra só uma contagem, nunca a senha):

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod config voto | grep -c 'DATABASE_URL: pgsql://voto_app:'
```

**Esperado:** `1`.

## 7. Subir o container do Voto

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod build voto
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps voto
```

O `--no-deps` é de propósito: sobe só o Voto e não recria o `php` nem o `db` (o `.env.prod` acabou de mudar, e sem
ele o compose poderia recriá-los no meio do dia). O build reaproveita as camadas da imagem do `php`.

Espere uns 30 segundos e confira:

```bash
docker inspect -f '{{.State.Health.Status}}' jusprime_voto_prod
docker exec -w /var/www/app jusprime_voto_prod php -d memory_limit=256M bin/console dbal:run-sql "SELECT current_user"
```

**Esperado:** `healthy` e, na consulta, `voto_app`.

## 8. Ligar o endereço no nginx (só depois do certificado do passo 3)

```bash
cp scripts/vps/vhosts/voto.conf.https-pendente nginx/conf.d/voto.conf
docker exec jusprime_nginx_prod nginx -t
```

- Se o `nginx -t` disser `test is successful`, recarregue:

  ```bash
  docker exec jusprime_nginx_prod nginx -s reload
  ```

- Se der erro, **apague o arquivo na hora** e não recarregue (o nginx no ar continua com a configuração antiga):

  ```bash
  rm nginx/conf.d/voto.conf
  ```

O `nginx/conf.d/voto.conf` fica fora do git, e o deploy não o apaga (ele só confere arquivos versionados).

## 9. Conferir o endereço

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://voto.bluejus.com.br/saude-do-voto
```

- **503** ("Voto em preparação"): é o esperado enquanto a chave **Voto** estiver desligada no Painel do Master. No
  endereço do Voto essa página não tem o botão "Voltar ao Expediente" (o Expediente não abre lá).
- **200**, depois que o Master ligar a chave **Voto**. Nesse caso `curl -sS https://voto.bluejus.com.br/saude-do-voto`
  mostra `ok`.

Duas conferências do isolamento:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://voto.bluejus.com.br/login
curl -sS -o /dev/null -w '%{http_code}\n' https://bluejus.com.br/saude-do-voto
```

**Esperado:** `404` nas duas. O endereço do Voto não abre o BlueJus, e o BlueJus não responde pelo Voto.

## 10. O que muda nos deploys seguintes

- Com `COMPOSE_PROFILES=voto` no `.env.prod`, todo deploy também reconstrói e recria o container do Voto, junto
  com o do BlueJus.
- **O rollback automático do deploy só cobre `php`, `worker` e `scheduler`.** Se um deploy falhar e voltar, o
  Voto fica com a imagem da versão que falhou. Para alinhá-lo com a versão que voltou, depois da volta:

  ```bash
  docker compose -f docker-compose.prod.yml --env-file .env.prod build voto
  docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps voto
  ```

- O Voto não roda migrations (quem roda é o `php`). Ele só sobe depois do `php` saudável.

## 11. Como desligar

1. Tire a linha `COMPOSE_PROFILES=voto` do `.env.prod` (`nano .env.prod`).
2. Pare e apague o container:

   ```bash
   docker stop jusprime_voto_prod
   docker rm jusprime_voto_prod
   ```

3. Se quiser tirar o endereço também:

   ```bash
   rm nginx/conf.d/voto.conf
   docker exec jusprime_nginx_prod nginx -t
   docker exec jusprime_nginx_prod nginx -s reload
   ```

   Se deixar o arquivo, o `voto.bluejus.com.br` passa a responder 502 e o resto do nginx segue normal (o
   endereço do container é procurado a cada acesso, não na carga do nginx).

O usuário `voto_app` e a linha `VOTO_DATABASE_URL` podem ficar; sem o container, ninguém os usa.

## 12. Se algo der errado

| O que aparece | O que quer dizer |
|---|---|
| Container reiniciando, e `docker logs jusprime_voto_prod` diz "falta VOTO_DATABASE_URL" | O item 1 do passo 5 não entrou (ou a linha está com outro nome). Corrija e repita o passo 7 |
| Container reiniciando, e `docker logs jusprime_voto_prod` diz "falta VOTO_APP_SECRET" | O item 4 do passo 5 não entrou. Rode o `printf` dele e repita o passo 7 |
| `502` no endereço | O container do Voto está fora ou ainda não ficou saudável. Veja `docker logs jusprime_voto_prod` |
| `400` "Untrusted Host" | O pedido chegou com um endereço que não é `voto.bluejus.com.br`. O endereço aceito está fixo no serviço `voto` do `docker-compose.prod.yml`, não no `.env.prod` |
| `404` em `/saude-do-voto` | O container respondeu como BlueJus: o `APP_PAPEL` não chegou. Confira o serviço `voto` no `docker-compose.prod.yml` da VPS |
| `500` e, no log, `permission denied for table` | O `voto_app` tentou ler uma tabela do BlueJus além da `recurso_estado` (pedir correção no código; não dê permissão à mão) |
| `429` | Limite por IP do nginx (10 por segundo, rajada de 20, 20 conexões). Números provisórios até o teste de carga |
| `nginx -t` falhou no passo 8 | Quase sempre é o certificado ausente. Apague o `nginx/conf.d/voto.conf` e volte ao passo 3 |

## 13. Restaurar backup num servidor de banco novo

O papel `voto_acesso` é do servidor de banco inteiro, não do banco: o `pg_dump` leva as permissões dele, mas não o
cria. Num restauro no mesmo servidor nada muda. Num servidor de banco **novo**, crie o papel **antes** do restauro
(igual ao `jusprime_leitura`), senão as permissões do schema `voto` falham:

```sql
CREATE ROLE voto_acesso NOLOGIN;
```

O `voto_app` também precisa ser criado de novo nesse caso (passo 4). Ver também `docs/runbooks/restaurar-backup.md`.
