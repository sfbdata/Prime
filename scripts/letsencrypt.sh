#!/usr/bin/env bash
set -euo pipefail

if ! command -v certbot >/dev/null 2>&1; then
  echo "certbot não encontrado. Instale no host antes de continuar."
  exit 1
fi

if command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD="docker-compose"
elif docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD="docker compose"
else
  echo "Docker Compose não encontrado (nem docker-compose nem docker compose)."
  exit 1
fi

if [[ ! -f ".env.prod" ]]; then
  echo "Arquivo .env.prod não encontrado. Crie com base em .env.prod.example"
  exit 1
fi

ACTION="${1:-issue}"
DOMAIN="${LETSENCRYPT_DOMAIN:-}"
EMAIL="${LETSENCRYPT_EMAIL:-}"

if [[ -z "$DOMAIN" ]]; then
  echo "Defina LETSENCRYPT_DOMAIN (ex: export LETSENCRYPT_DOMAIN=app.seudominio.com)"
  exit 1
fi

mkdir -p certbot/www

# O nginx de produção lê os certificados direto de /etc/letsencrypt (montado :ro),
# então basta recarregar — não há cópia para certs/.
reload_nginx() {
  docker exec jusprime_nginx_prod nginx -s reload
}

if [[ "$ACTION" == "issue" ]]; then
  if [[ -z "$EMAIL" ]]; then
    echo "Defina LETSENCRYPT_EMAIL (ex: export LETSENCRYPT_EMAIL=admin@seudominio.com)"
    exit 1
  fi

  $COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod up -d

  sudo certbot certonly \
    --webroot \
    -w "$(pwd)/certbot/www" \
    -d "$DOMAIN" \
    --email "$EMAIL" \
    --agree-tos \
    --no-eff-email \
    --non-interactive

  reload_nginx
  echo "Certificado emitido e aplicado com sucesso."
  exit 0
fi

if [[ "$ACTION" == "renew" ]]; then
  sudo certbot renew --webroot -w "$(pwd)/certbot/www"
  reload_nginx
  echo "Renovação concluída e Nginx recarregado."
  exit 0
fi

echo "Ação inválida. Use: ./scripts/letsencrypt.sh issue|renew"
exit 1