#!/usr/bin/env bash
set -euo pipefail

# Roda sempre a partir da raiz do repositório (paths relativos precisam casar com
# o `./nginx/maintenance` do compose).
cd "$(dirname "$0")/.."

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

# Entra em modo manutenção: o nginx continua no ar servindo a página amigável (503)
# enquanto o php é reconstruído. Em caso de falha, a flag fica ligada de propósito.
echo "Ativando modo manutenção..."
mkdir -p nginx/maintenance
touch nginx/maintenance/maintenance.on

# Sem `down`: recria só o php (código novo). nginx/db ficam no ar — sem "conexão
# recusada" e sem derrubar o app co-hospedado. --remove-orphans limpa containers
# fora do compose sem precisar derrubar os serviços ativos.
$COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod up -d --build --remove-orphans

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

# Aguarda o php-fpm abrir a porta 9000 (healthcheck = app pronto após warmup+migrations).
echo "Aguardando PHP-FPM ficar pronto..."
for i in $(seq 1 90); do
  status="$(docker inspect -f '{{.State.Health.Status}}' jusprime_php_prod 2>/dev/null || echo starting)"
  if [[ "$status" == "healthy" ]]; then
    break
  fi
  if [[ "$i" -eq 90 ]]; then
    echo "PHP não ficou pronto a tempo (180s). Mantendo modo manutenção ativo (docker logs jusprime_php_prod)."
    exit 1
  fi
  sleep 2
done

$COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod exec -T php php /var/www/app/bin/console doctrine:migrations:migrate --no-interaction

# Sai do modo manutenção.
echo "Desativando modo manutenção..."
rm -f nginx/maintenance/maintenance.on

echo "Deploy concluído com sucesso."