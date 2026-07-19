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
- `SYMFONY_TRUSTED_PROXIES` (ex: `private_ranges`)
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
> Depois de alterar `nginx.prod.conf`/`docker-compose.prod.yml`, faça **recreate** do
> container (`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d nginx`)
> e valide com `curl http://<dominio>/.well-known/acme-challenge/teste` (deve dar 200,
> não 301) **antes** de qualquer emissão real.

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
0 2 * * * /caminho/para/jusprime/scripts/backup.sh >> /var/log/jusprime-backup.log 2>&1
```

> Substitua `/caminho/para/jusprime` pelo caminho real do projeto na VPS.

### Purga de dados expirados (crontab na VPS)

Job diário que apaga cadastros pendentes expirados (senha_hash + PII) e faz o hard delete
definitivo de escritórios em quarentena vencida (soft delete há mais de 365 dias — RN09).
Roda **depois** do backup das 02:00, para que o dump da noite ainda contenha o escritório
(janela de recuperação). `--force` é obrigatório (cron não tem TTY para a confirmação).

```
0 3 * * * docker exec jusprime_php_prod php bin/console app:purgar-dados-expirados --force >> /var/log/jusprime-purga.log 2>&1
```

> **Antes de agendar:** rode uma vez em simulação para conferir o que seria apagado —
> `docker exec jusprime_php_prod php bin/console app:purgar-dados-expirados --dry-run`.
>
> A carência de quarentena (padrão 365 dias) é configurável sem deploy via
> `TENANT_CARENCIA_PURGA_DIAS` no `.env.prod`.

### Atualização de encargos de cobrança (crontab na VPS)

Job diário que faz os encargos das obrigações **crescerem no tempo**: recalcula juros, multa,
correção e honorários para a data de hoje e grava nas colunas da obrigação. Os juros de mora
mudam todo dia, mas o `valorExigivel()` é materializado (não derivado) — sem este cron o valor
congela no dia em que a obrigação foi cadastrada.

Roda **depois** do backup das 02:00 e da purga das 03:00, para que o dump da noite tenha o
estado anterior ao recálculo (janela de conferência) e para não disputar CPU com os dois.

```
30 3 * * * docker exec jusprime_php_prod php bin/console app:cobranca:atualizar-encargos >> /var/log/jusprime-encargos.log 2>&1
```

> **Antes de agendar:** rode uma vez em simulação e confira o delta relatado —
> `docker exec jusprime_php_prod php bin/console app:cobranca:atualizar-encargos --dry-run`.
> O `--dry-run` calcula tudo e mostra o relatório sem gravar nada. Para amostrar um escritório
> só: `--tenant=1 --limit=50`.
>
> **O que ele toca:** apenas obrigações **não congeladas** (`encargos_congelados_em IS NULL`) de
> casos **não encerrados**. Obrigação editada à mão, importada da contabilidade ou ligada a um
> acordo vigente é **pulada** — o cron nunca desfaz decisão de gente nem infla dívida já
> renegociada.
>
> **Exit code 1 significa que alguma obrigação FALHOU** no recálculo (o comando segue a rodada e
> alarma no fim). Leia `/var/log/jusprime-encargos.log`: a seção "Obrigações que falharam" traz o
> id e o motivo — tipicamente uma taxa configurada em regime composto alta demais para o atraso
> acumulado. As demais obrigações foram atualizadas normalmente.

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
- [ ] Plano de rollback definido (imagem/tag anterior + comando de retorno).
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
