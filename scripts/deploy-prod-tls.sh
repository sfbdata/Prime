#!/usr/bin/env bash
set -euo pipefail

# ─── Detecta comando docker compose ───────────────────────────────────────────
if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD="docker-compose"
else
  echo "❌ Docker Compose não encontrado."
  exit 1
fi

COMPOSE_FILE="-f docker-compose.prod.yml"
ENV_FILE="--env-file .env.prod"

# ─── Valida pré-requisitos ─────────────────────────────────────────────────────
if [[ ! -f ".env.prod" ]]; then
  echo "❌ Arquivo .env.prod não encontrado."
  exit 1
fi

if [[ ! -f "/etc/letsencrypt/live/grupojusprime.tech/fullchain.pem" || \
      ! -f "/etc/letsencrypt/live/grupojusprime.tech/privkey.pem" ]]; then
  echo "❌ Certificados Let's Encrypt não encontrados em /etc/letsencrypt/live/grupojusprime.tech/"
  echo "   Execute: certbot certonly --standalone -d grupojusprime.tech -d www.grupojusprime.tech"
  exit 1
fi

# ─── Atualiza código ───────────────────────────────────────────────────────────
echo "📦 Atualizando código do repositório..."
git pull

# ─── Sobe os containers ────────────────────────────────────────────────────────
echo "🐳 Subindo containers..."
$COMPOSE_CMD $COMPOSE_FILE $ENV_FILE down --remove-orphans
$COMPOSE_CMD $COMPOSE_FILE $ENV_FILE up -d --build

# ─── Aguarda banco de dados ────────────────────────────────────────────────────
source <(grep -E '^(POSTGRES_USER|POSTGRES_DB)=' .env.prod)

echo "⏳ Aguardando banco de dados ficar pronto..."
for i in {1..30}; do
  if $COMPOSE_CMD $COMPOSE_FILE $ENV_FILE exec -T db \
      pg_isready -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" >/dev/null 2>&1; then
    echo "✅ Banco pronto."
    break
  fi
  if [[ "$i" -eq 30 ]]; then
    echo "❌ Banco de dados não ficou pronto a tempo."
    exit 1
  fi
  sleep 2
done

# ─── Migrations ───────────────────────────────────────────────────────────────
echo "🗄️  Executando migrations..."
$COMPOSE_CMD $COMPOSE_FILE $ENV_FILE exec -T php \
  php /var/www/app/bin/console doctrine:migrations:migrate \
  --no-interaction --allow-no-migration

# ─── Verifica Nginx ───────────────────────────────────────────────────────────
echo "🔍 Verificando Nginx..."
if $COMPOSE_CMD $COMPOSE_FILE $ENV_FILE exec -T nginx nginx -t >/dev/null 2>&1; then
  echo "✅ Nginx config OK."
else
  echo "❌ Erro na configuração do Nginx. Verifique com:"
  echo "   docker exec jusprime_nginx_prod nginx -t"
  exit 1
fi

# ─── Verifica portas ──────────────────────────────────────────────────────────
echo "🔌 Portas ativas:"
ss -tlnp | grep -E ':80|:443' || echo "⚠️  Nenhuma porta 80/443 detectada."

echo ""
echo "🚀 Deploy TLS concluído com sucesso."
echo "   🌐 https://grupojusprime.tech"