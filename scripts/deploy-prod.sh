#!/usr/bin/env bash
set -euo pipefail

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

$COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod down --remove-orphans
$COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod up -d --build

echo "Aguardando banco de dados ficar pronto..."
for i in {1..30}; do
  if $COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod exec -T db pg_isready -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" >/dev/null 2>&1; then
    break
  fi
  if [[ "$i" -eq 30 ]]; then
    echo "Banco de dados não ficou pronto a tempo."
    exit 1
  fi
  sleep 2
done

$COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod exec -T php php /var/www/app/bin/console doctrine:migrations:migrate --no-interaction

echo "Deploy concluído com sucesso."