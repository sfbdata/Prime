# Deploy e ambientes

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

## Let's Encrypt (renovação)

O script `letsencrypt.sh` usa o método webroot (com containers em execução):

Pré-requisitos:
- Stack de produção já em execução;
- `certbot` instalado no host;
- variáveis `LETSENCRYPT_DOMAIN` e `LETSENCRYPT_EMAIL` definidas.

1. Exporte as variáveis:

```bash
export LETSENCRYPT_DOMAIN=grupojusprime.tech
export LETSENCRYPT_EMAIL=admin@grupojusprime.tech
```

2. Emita o certificado (webroot, com containers rodando):

```bash
chmod +x scripts/letsencrypt.sh
./scripts/letsencrypt.sh issue
```

3. Renove quando necessário:

```bash
./scripts/letsencrypt.sh renew
```

O script copia os certificados para `certs/` e recarrega o Nginx automaticamente.

Sugestão de cron (diário, 03:15):

```bash
15 3 * * * cd /caminho/do/projeto && LETSENCRYPT_DOMAIN=grupojusprime.tech ./scripts/letsencrypt.sh renew >> /var/log/jusprime-letsencrypt.log 2>&1
```

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
- configuração do Nginx e presença/validade dos certificados em `certs/`;
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
