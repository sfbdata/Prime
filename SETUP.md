# No WSL
cat > SETUP.md << 'EOF'
# Setup manual da VPS

## Certificado SSL
```bash
certbot certonly --standalone -d grupojusprime.tech -d www.grupojusprime.tech --email seu@email.com --agree-tos
```

## Cron de renovação
```bash
crontab -e
# Adicionar:
0 3 * * * certbot renew --quiet && docker exec jusprime_nginx_prod nginx -s reload
```

## Arquivo .env.prod
Criar em `/opt/jusprime/.env.prod` com base em `.env.prod.example`
EOF