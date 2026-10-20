# Deploy e ambientes

> **Domínio de produção do JusPrime/BlueJus: `https://bluejus.com.br`** (app na raiz `/`).
> O `grupojusprime.tech` usado nos exemplos abaixo (certbot, trusted hosts, go-live-check)
> é **infra compartilhada** — o mesmo container/VPS também hospeda **outra** aplicação
> (gestão de condomínio, em `grupojusprime.tech/app/`). Para diagnosticar/validar a prod
> do JusPrime, use **`bluejus.com.br`**, não `grupojusprime.tech`. Confira os nomes de
> domínio nos comandos abaixo contra a config real da VPS antes de usar.

## Desenvolvimento

```bash
docker-compose up -d --build
```

- Usa bind mount do código para iteração rápida.
- Porta web: `http://localhost:8080`.

## Produção

1. Crie o arquivo de ambiente:

```bash
cp .env.prod.example .env.prod
```

2. Preencha os valores reais em `.env.prod`.

Campos obrigatórios:
- `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB`, `DATABASE_URL`
- `APP_SECRET`
- `MAILER_DSN`
- `DATAJUD_API_KEY`
- `DEFAULT_URI` (URL base pública, ex: `https://grupojusprime.tech`)
- `SYMFONY_TRUSTED_PROXIES=127.0.0.1` (exatamente assim. O nginx fala com o PHP por FastCGI e já entrega o IP real
  em `REMOTE_ADDR`. **Não use `REMOTE_ADDR` nem `private_ranges`**: em FastCGI o `REMOTE_ADDR` é o próprio cliente, e
  confiar nele deixa qualquer um forjar o IP no `X-Forwarded-For` e furar os limitadores por IP.)
- `SYMFONY_TRUSTED_HOSTS` (regex do host, ex: `^grupojusprime\\.tech$`)

3. Execute o deploy:

```bash
chmod +x scripts/deploy-prod.sh
./scripts/deploy-prod.sh
```

Esse script:
- derruba containers anteriores (`down --remove-orphans`);
- faz build da imagem `prod` e sobe a stack;
- aguarda o PostgreSQL ficar pronto;
- executa as migrations.

> A stack de produção (`docker-compose.prod.yml`) já expõe as portas `80` e `443` e monta `/etc/letsencrypt` para TLS — não há arquivo de override separado.

## Produção com TLS (HTTPS via Let's Encrypt)

O script `deploy-prod-tls.sh` realiza o deploy completo com validação de certificados:

Pré-requisitos:
- DNS do domínio (`grupojusprime.tech`) apontando para o servidor;
- portas `80` e `443` abertas no firewall;
- `certbot` instalado no host;
- certificados já emitidos em `/etc/letsencrypt/live/grupojusprime.tech/`.

### Emitir certificado (primeira vez)

```bash
sudo certbot certonly --standalone \
  -d grupojusprime.tech \
  -d www.grupojusprime.tech \
  --email admin@grupojusprime.tech \
  --agree-tos \
  --non-interactive
```

> **Atenção:** use `--standalone` apenas antes de subir os containers. Com containers em execução, use `--webroot` conforme descrito na seção [Let's Encrypt (renovação)](#lets-encrypt-renovação).

### Executar deploy com TLS

```bash
chmod +x scripts/deploy-prod-tls.sh
./scripts/deploy-prod-tls.sh
```

Esse script:
- valida a presença dos certificados em `/etc/letsencrypt/live/grupojusprime.tech/`;
- faz `git pull`;
- derruba e sobe a stack com build;
- aguarda o banco de dados;
- executa migrations;
- verifica a config do Nginx;
- exibe as portas ativas.

Uploads em produção:
- arquivos em `public/uploads` são persistidos no volume Docker `uploads_prod`, compartilhado entre PHP e Nginx.

## Let's Encrypt (emissão e renovação via webroot)

A validação ACME usa o método **webroot**: o nginx serve o desafio HTTP-01 a partir
de `./certbot/www` (montado em `/var/www/certbot`), e os dois server blocks `:80`
expõem `location ^~ /.well-known/acme-challenge/`. Não é preciso parar o nginx —
zero downtime nas renovações.

> **Pré-requisito de infra:** o nginx precisa já estar servindo o acme-challenge.
> Alterar `nginx/conf.d/nginx.prod.conf` já basta — desde 19/08/2026 o compose monta a
> **pasta** `./nginx/conf.d` (e não mais o arquivo solto), então o container enxerga o
> arquivo novo, e o `deploy-prod*.sh` recarrega o nginx no fim. Só mudança no
> `docker-compose.prod.yml` exige **recreate**
> (`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d nginx`).
> Valide com `curl http://<dominio>/.well-known/acme-challenge/teste` (deve dar 200,
> não 301) **antes** de qualquer emissão real.
>
> ⚠️ **Por que a pasta:** bind-mount de arquivo único amarra o **inode**, e `git pull`
> grava arquivo novo em vez de editar no lugar. O container ficava preso no inode antigo
> **sem erro e sem log** — produção rodou de 27/06 a 19/08/2026 com config velha, servindo
> CSS/JS com cache `immutable` de 30 dias que já tinha sido corrigido em 17/07. `reload`
> não resolvia (relia o arquivo errado); só recriar o container resolvia.

### Emissão manual (`letsencrypt.sh issue`)

O `scripts/letsencrypt.sh` é a ferramenta de **emissão manual** (um domínio por vez,
apex). Para o domínio com `www`, prefira `certbot certonly --webroot` repetindo os `-d`
(ver abaixo). O script recarrega o nginx ao final (lê os certs direto de `/etc/letsencrypt`).

```bash
export LETSENCRYPT_DOMAIN=grupojusprime.tech
export LETSENCRYPT_EMAIL=admin@grupojusprime.tech
chmod +x scripts/letsencrypt.sh
./scripts/letsencrypt.sh issue
```

Emissão/reemissão com apex + www (rescreve o renewal config para webroot):

```bash
sudo certbot certonly --webroot -w /opt/jusprime/certbot/www \
  -d grupojusprime.tech -d www.grupojusprime.tech
```

### Renovação automática (cron com `certbot renew`)

A renovação recorrente **não** usa o `letsencrypt.sh` — usa `certbot renew` puro, que
renova todos os certs configurados e recarrega o nginx via `--deploy-hook`:

```bash
# 03h UTC = 00h de Brasília
0 3 * * * certbot renew --quiet --deploy-hook "docker exec jusprime_nginx_prod nginx -s reload"
```

> Os renewal configs em `/etc/letsencrypt/renewal/*.conf` precisam estar em
> `authenticator = webroot` (reemita com `certbot certonly --webroot` para que o
> certbot reescreva o `.conf`). Valide com `sudo certbot renew --dry-run`.

## Backup e Restauração

### Configuração inicial (na VPS, apenas uma vez)

```bash
# Cria o diretório de backups
sudo mkdir -p /var/backups/jusprime
sudo chown $USER:$USER /var/backups/jusprime

# Garante que o script é executável
chmod +x scripts/backup.sh scripts/restore.sh
```

### Executar backup manual

```bash
./scripts/backup.sh
```

O script:
- Faz dump comprimido do PostgreSQL via container Docker
- Copia os arquivos do volume `uploads_prod`
- Gera um `.tar.gz` em `/var/backups/jusprime/`
- Aplica rotação automática (padrão: 7 backups)

### Agendamento automático (crontab na VPS)

```bash
crontab -e
```

Adicione a linha (executa todo dia às 02:00):

```
# 02h UTC = 23h de Brasília (do dia anterior)
0 2 * * * /caminho/para/jusprime/scripts/backup.sh >> /var/log/jusprime-backup.log 2>&1
```

> Substitua `/caminho/para/jusprime` pelo caminho real do projeto na VPS.

### Comandos do console no container de produção

O container `jusprime_php_prod` começa em `/var/www` (estágio de produção do `Dockerfile`), e o
`bin/console` mora em `/var/www/app`. Por isso toda chamada do console nas crons abaixo leva
`-w /var/www/app`; sem ele, o `docker exec` responde "Could not open input file: bin/console".

**Horários em UTC.** O relógio da VPS está em UTC e o cron dela não garante o `CRON_TZ`, então as
linhas abaixo já vêm em UTC, com a hora de Brasília num comentário logo acima (Brasília é UTC-3, sem
horário de verão).

### Purga de dados expirados (crontab na VPS)

Job diário que apaga cadastros pendentes expirados (senha_hash + PII) e faz o hard delete
definitivo de escritórios em quarentena vencida (soft delete há mais de 365 dias — RN09).
Roda **depois** do backup das 02:00, para que o dump da noite ainda contenha o escritório
(janela de recuperação). `--force` é obrigatório (cron não tem TTY para a confirmação).

```
# 03h UTC = 00h de Brasília, uma hora depois do backup
0 3 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:purgar-dados-expirados --force >> /var/log/jusprime-purga.log 2>&1
```

> **Antes de agendar:** rode uma vez em simulação para conferir o que seria apagado —
> `docker exec -w /var/www/app jusprime_php_prod php bin/console app:purgar-dados-expirados --dry-run`.
>
> A carência de quarentena (padrão 365 dias) é configurável sem deploy via
> `TENANT_CARENCIA_PURGA_DIAS` no `.env.prod`.

### Resumo semanal ao cliente (crontab na VPS)

Toda segunda-feira, às 8h de Brasília, manda aos clientes com o "Resumo semanal" ligado as movimentações da semana anterior (spec `docs/specs/push-envio-automatico.md` §3; simule antes com `--dry-run`; configurar é do dono):

```
# segunda, 11h UTC = 8h de Brasília
0 11 * * 1 docker exec -w /var/www/app jusprime_php_prod php bin/console app:push:resumo-semanal-cliente >> /var/log/jusprime-resumo-semanal-cliente.log 2>&1
```

### Encargos de cobrança (sem cron)

Não há cron de encargos. Os juros, a multa, a correção e os honorários das obrigações são calculados
ao vivo, na leitura; o antigo `app:cobranca:atualizar-encargos` foi removido (commit a5583f7f, "F4:
remover o cron de encargos") e não existe mais no console. Se o crontab da VPS ainda tiver essa linha,
apague-a.

### Relatório mensal de pastas sem processo (crontab na VPS)

Envia à Controladoria (e aos primários) o relatório de pastas sem processo vinculado, e a cada
responsável as pastas dele que pedem atenção (spec `docs/specs/relatorio-processos-controladoria.md`,
D5). O cron roda **todo dia às 10h05 de Brasília**; quem decide se é dia de envio é o próprio comando:
só na **primeira segunda-feira do mês**, a partir das 10h, e **uma vez por mês por escritório** (o
histórico `relatorio_sem_processo_envio` trava a repetição). Nos outros dias ele passa e não envia nada.

```
# todo dia, 13h05 UTC = 10h05 de Brasília
5 13 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:pastas:relatorio-sem-processo >> /var/log/jusprime-relatorio-sem-processo.log 2>&1
```

> **Antes de agendar:** rode uma vez em simulação: `docker exec -w /var/www/app jusprime_php_prod php bin/console
> app:pastas:relatorio-sem-processo --dry-run` (conta os avisos e não grava nada). Para um escritório
> só: `--tenant=1`.
>
> Configurar o cron é do dono (D5).

### Relatório mensal de pastas paradas (crontab na VPS)

Avisa cada responsável das pastas dele que estão sem movimentação há mais de 30 dias (para dizer se
vão receber movimentação ou ser arquivadas), e a Controladoria e os primários da lista inteira (spec
`docs/specs/pastas-paradas.md`, "Envio automático"). O cron roda **todo dia às 10h15 de Brasília**;
quem decide se é dia de envio é o próprio comando: só na **primeira segunda-feira do mês**, a partir
das 10h, e **uma vez por mês por escritório** (a gravação em `relatorio_pastas_paradas_envio` vem antes
dos avisos e trava a repetição). Nos outros dias ele passa e não envia nada.

```
# todo dia, 13h15 UTC = 10h15 de Brasília
15 13 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:pastas:relatorio-pastas-paradas >> /var/log/jusprime-relatorio-pastas-paradas.log 2>&1
```

> **Antes de agendar:** rode uma vez em simulação: `docker exec -w /var/www/app jusprime_php_prod php bin/console
> app:pastas:relatorio-pastas-paradas --dry-run` (não grava nada). A simulação só conta as pastas
> paradas e os avisos no dia do envio (primeira segunda-feira do mês, a partir das 10h); em outro dia,
> só mostra o motivo de não enviar. Para um escritório só: `--tenant=1`.
>
> Configurar o cron é do dono.

### Personalizar configuração

Variáveis de ambiente que sobrescrevem os padrões do script:

| Variável        | Padrão                   | Descrição                         |
|-----------------|--------------------------|-----------------------------------|
| `BACKUP_DIR`    | `/var/backups/jusprime`  | Onde salvar os backups            |
| `KEEP_BACKUPS`  | `7`                      | Quantos backups manter            |

Exemplo:
```bash
BACKUP_DIR=/mnt/backup-externo KEEP_BACKUPS=14 ./scripts/backup.sh
```

### Restaurar um backup

```bash
./scripts/restore.sh /var/backups/jusprime/jusprime_20240101_020000.tar.gz
```

> **Atenção:** a restauração é destrutiva. O script pede confirmação explícita antes de prosseguir.

---

## Comandos úteis (produção)

```bash
docker-compose -f docker-compose.prod.yml --env-file .env.prod ps
docker-compose -f docker-compose.prod.yml --env-file .env.prod logs -f
docker-compose -f docker-compose.prod.yml --env-file .env.prod down
```

## Checklist automatizado pós-deploy

Após o deploy, rode um smoke check automatizado:

```bash
chmod +x scripts/go-live-check.sh
./scripts/go-live-check.sh grupojusprime.tech
```

Também é possível usar variável de ambiente:

```bash
LETSENCRYPT_DOMAIN=grupojusprime.tech ./scripts/go-live-check.sh
```

O script valida:
- variáveis críticas do `.env.prod`;
- serviços `php`, `nginx`, `db` em execução;
- saúde do PostgreSQL;
- configuração do Nginx e presença/validade dos certificados em `/etc/letsencrypt/live/<dominio>/`;
- DNS, redirecionamento HTTP -> HTTPS e resposta do endpoint HTTPS;
- status de migrations;
- status do firewall (ufw).

## Checklist de release (pré, durante e pós-deploy)

### Pré-deploy

- [ ] Branch da release está limpa (sem mudanças locais pendentes).
- [ ] Fluxos críticos validados (login, cadastro, telas principais).
- [ ] Mudanças de banco com migration criada e revisada.
- [ ] Variáveis de produção conferidas em `.env.prod`:
	- `DEFAULT_URI`
	- `SYMFONY_TRUSTED_PROXIES`
	- `SYMFONY_TRUSTED_HOSTS`
- [ ] Build da aplicação concluído sem erro.
- [ ] Backup recente do banco confirmado.
- [ ] Se o deploy leva mudança de produto: versão lançada pelo workflow Release e conferida contra o master (`docs/versionamento.md`, seção 5). Só CI, testes ou documentação não geram versão.
- [ ] Plano de rollback definido (imagem/tag anterior + comando de retorno — `docs/versionamento.md`, seção 7).
- [ ] Janela de deploy alinhada (evitar horário de pico).

### Durante o deploy

- [ ] Executar script de deploy:
	- sem TLS: `./scripts/deploy-prod.sh`
	- com TLS: `./scripts/deploy-prod-tls.sh`
- [ ] Confirmar serviços em execução:
	```bash
	docker-compose -f docker-compose.prod.yml --env-file .env.prod ps
	```
- [ ] Verificar migrations aplicadas sem erro.
- [ ] Acompanhar logs iniciais:
	```bash
	docker-compose -f docker-compose.prod.yml --env-file .env.prod logs -f
	```

### Pós-deploy (go-live)

- [ ] Rodar smoke check: `./scripts/go-live-check.sh grupojusprime.tech`
- [ ] Validar domínio público (HTTP/HTTPS, redirecionamento, rota principal e login).
- [ ] Testar upload e persistência em `public/uploads`.
- [ ] Monitorar logs e erros por 15-30 minutos após publicação.
- [ ] Registrar versão publicada (tag/commit, data/hora e responsável).
