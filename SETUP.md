# Setup Manual da VPS — JusPrime

Documento de referência para recriar o ambiente do zero em caso de formatação ou migração de servidor.

## Informações do Servidor

- **Provedor:** Hostinger VPS
- **OS:** Ubuntu 24.04
- **IP:** 72.60.146.89
- **Projeto:** `/opt/jusprime`
- **Repositório:** `https://github.com/sfbdata/Prime.git`
- **Domínio:** `grupojusprime.tech` (registrado na Hostinger)

---

## 1. Acesso SSH
```bash
ssh root@72.60.146.89
```

---

## 2. Dependências do servidor
```bash
apt update && apt upgrade -y
apt install -y git curl ufw docker.io

# Docker Compose plugin
apt install -y docker-compose-plugin

# Certbot
apt install -y certbot

# Habilitar Docker no boot
systemctl enable docker
systemctl start docker
```

---

## 3. Firewall
```bash
ufw allow 22
ufw allow 80
ufw allow 443
ufw enable
```

---

## 4. Clonar o repositório
```bash
mkdir -p /opt/jusprime
cd /opt/jusprime
git clone https://github.com/sfbdata/Prime.git .
```

---

## 5. Criar o arquivo .env.prod
```bash
cp .env.prod.example .env.prod
nano .env.prod
```

Preencher todas as variáveis obrigatórias:
- `APP_SECRET`
- `DATABASE_URL`
- `POSTGRES_USER`
- `POSTGRES_PASSWORD`
- `POSTGRES_DB`

---

## 6. DNS — Hostinger

No painel da Hostinger, em **Domínios → grupojusprime.tech → DNS**:

| Tipo | Nome | Valor | TTL |
|------|------|-------|-----|
| A | @ | 72.60.146.89 | 300 |
| A | www | 72.60.146.89 | 300 |

Aguardar propagação (normalmente 5–30 minutos).

Verificar:
```bash
dig grupojusprime.tech +short
# deve retornar 72.60.146.89
```

---

## 7. Certificado SSL — Let's Encrypt

> ⚠️ Só executar após o DNS estar propagado.
> O certbot precisa verificar o domínio pela internet.
```bash
# Parar qualquer processo usando a porta 80 antes
docker compose -f /opt/jusprime/docker-compose.prod.yml down 2>/dev/null || true

certbot certonly --standalone \
  -d grupojusprime.tech \
  -d www.grupojusprime.tech \
  --email jusprime.samuel@gmail.com \
  --agree-tos \
  --no-eff-email \
  --non-interactive
```

Verificar:
```bash
certbot certificates
# Deve mostrar cert válido em /etc/letsencrypt/live/grupojusprime.tech/
```

---

## 8. Cron de renovação automática do certificado
```bash
crontab -e
```

Adicionar no final:
```
0 3 * * * certbot renew --quiet && docker exec jusprime_nginx_prod nginx -s reload
```

---

## 9. Deploy
```bash
cd /opt/jusprime
chmod +x scripts/deploy-prod-tls.sh
./scripts/deploy-prod-tls.sh
```

---

## 10. Verificações finais
```bash
# Containers rodando
docker ps

# Portas abertas
ss -tlnp | grep -E '80|443'

# Logs do Nginx
docker logs jusprime_nginx_prod

# Logs do PHP
docker logs jusprime_php_prod

# Testar HTTPS
curl -I https://grupojusprime.tech
```

---

## Containers

| Container | Função |
|---|---|
| `jusprime_php_prod` | PHP 8.2 FPM — Symfony 6 |
| `jusprime_nginx_prod` | Nginx reverse proxy + SSL |
| `jusprime_db_prod` | PostgreSQL 15 |

---

## Volumes Docker (dados persistentes)

| Volume | Conteúdo |
|---|---|
| `db_data_prod` | Dados do banco PostgreSQL |
| `uploads_prod` | Arquivos enviados pelos usuários |
| `static_prod` | Assets estáticos da aplicação |

> ⚠️ Em caso de migração, fazer backup dos volumes antes de destruir o servidor.

---

## Backup dos volumes (antes de migrar)
```bash
# Banco de dados
docker exec jusprime_db_prod pg_dump -U $POSTGRES_USER $POSTGRES_DB > backup_$(date +%Y%m%d).sql

# Uploads
docker run --rm -v uploads_prod:/data -v $(pwd):/backup alpine \
  tar czf /backup/uploads_backup_$(date +%Y%m%d).tar.gz -C /data .
```