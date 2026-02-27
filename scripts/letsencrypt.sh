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

mkdir -p certbot/www certs

copy_and_reload() {
  sudo cp "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" certs/fullchain.pem
  sudo cp "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" certs/privkey.pem
  sudo chown "$(id -u):$(id -g)" certs/fullchain.pem certs/privkey.pem
  chmod 644 certs/fullchain.pem
  chmod 600 certs/privkey.pem
  $COMPOSE_CMD -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod exec -T nginx nginx -s reload
}

if [[ "$ACTION" == "issue" ]]; then
  if [[ -z "$EMAIL" ]]; then
    echo "Defina LETSENCRYPT_EMAIL (ex: export LETSENCRYPT_EMAIL=admin@seudominio.com)"
    exit 1
  fi

  $COMPOSE_CMD -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod up -d

  sudo certbot certonly \
    --webroot \
    -w "$(pwd)/certbot/www" \
    -d "$DOMAIN" \
    --email "$EMAIL" \
    --agree-tos \
    --no-eff-email \
    --non-interactive

  copy_and_reload
  echo "Certificado emitido e aplicado com sucesso."
  exit 0
fi

if [[ "$ACTION" == "renew" ]]; then
  sudo certbot renew --webroot -w "$(pwd)/certbot/www"
  copy_and_reload
  echo "Renovação concluída e Nginx recarregado."
  exit 0
fi

echo "Ação inválida. Use: ./scripts/letsencrypt.sh issue|renew"
exit 1