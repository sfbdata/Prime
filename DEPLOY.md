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

Campos obrigatórios adicionais em produção:
- `DEFAULT_URI` (URL base pública, ex: `https://app.seudominio.com`)
- `SYMFONY_TRUSTED_PROXIES` (ex: `private_ranges`)
- `SYMFONY_TRUSTED_HOSTS` (regex do host permitido, ex: `^app\\.seudominio\\.com$`)

3. Execute o deploy:

```bash
chmod +x scripts/deploy-prod.sh
./scripts/deploy-prod.sh
```

Isso fará:
- build da imagem `prod` do PHP;
- subida da stack de produção;
- espera ativa do PostgreSQL ficar pronto;
- execução de migrations.

## Produção com TLS (HTTPS)

1. Coloque os certificados em:

- `certs/fullchain.pem`
- `certs/privkey.pem`

2. Execute:

```bash
chmod +x scripts/deploy-prod-tls.sh
./scripts/deploy-prod-tls.sh
```

Esse fluxo aplica hardening de transporte:
- redirecionamento `80 -> 443`;
- TLS 1.2/1.3;
- HSTS e headers de segurança.

Uploads em produção:
- uploads em `public/uploads` são persistidos em volume Docker dedicado compartilhado entre PHP e Nginx.

## Let’s Encrypt (produção real, sem downtime)

Pré-requisitos no host:
- DNS do domínio apontando para o servidor;
- portas `80` e `443` abertas;
- `certbot` instalado no host.

1. Exporte variáveis:

```bash
export LETSENCRYPT_DOMAIN=app.seudominio.com
export LETSENCRYPT_EMAIL=admin@seudominio.com
```

2. Suba o stack TLS (se ainda não estiver em execução):

```bash
./scripts/deploy-prod-tls.sh
```

3. Emita o certificado:

```bash
chmod +x scripts/letsencrypt.sh
./scripts/letsencrypt.sh issue
```

4. Renove quando necessário:

```bash
./scripts/letsencrypt.sh renew
```

Sugestão de cron (diário, 03:15):

```bash
15 3 * * * cd /caminho/do/projeto && LETSENCRYPT_DOMAIN=app.seudominio.com ./scripts/letsencrypt.sh renew >> /var/log/jusprime-letsencrypt.log 2>&1
```

## Comandos úteis (produção)

```bash
docker-compose -f docker-compose.prod.yml --env-file .env.prod ps
docker-compose -f docker-compose.prod.yml --env-file .env.prod logs -f
docker-compose -f docker-compose.prod.yml --env-file .env.prod down
```

Com TLS:

```bash
docker-compose -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod ps
docker-compose -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod logs -f
docker-compose -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod down
```

## Checklist automatizado pós-deploy

Após o deploy, rode um smoke check automatizado:

```bash
chmod +x scripts/go-live-check.sh
./scripts/go-live-check.sh app.seudominio.com
```

Também é possível usar variável de ambiente:

```bash
LETSENCRYPT_DOMAIN=app.seudominio.com ./scripts/go-live-check.sh
```

O script valida:
- variáveis críticas do `.env.prod`;
- serviços `php`, `nginx`, `db` em execução;
- saúde do PostgreSQL;
- configuração do Nginx e presença/validade básica dos certificados;
- DNS, redirecionamento HTTP -> HTTPS e resposta do endpoint HTTPS;
- status de migrations.

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
	- sem TLS: `docker-compose -f docker-compose.prod.yml --env-file .env.prod ps`
	- com TLS: `docker-compose -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod ps`
- [ ] Verificar migrations aplicadas sem erro.
- [ ] Acompanhar logs iniciais:
	- sem TLS: `docker-compose -f docker-compose.prod.yml --env-file .env.prod logs -f`
	- com TLS: `docker-compose -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod logs -f`

### Pós-deploy (go-live)

- [ ] Rodar smoke check: `./scripts/go-live-check.sh app.seudominio.com`
- [ ] Validar domínio público (HTTP/HTTPS, redirecionamento, rota principal e login).
- [ ] Testar upload e persistência em `public/uploads`.
- [ ] Monitorar logs e erros por 15-30 minutos após publicação.
- [ ] Registrar versão publicada (tag/commit, data/hora e responsável).