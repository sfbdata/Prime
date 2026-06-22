#!/usr/bin/env bash
set -euo pipefail

# Roda sempre a partir da raiz do repositório, independente de onde foi chamado.
# Os caminhos relativos abaixo (.env.prod, docker-compose.prod.yml, nginx/maintenance)
# precisam casar com o `./nginx/maintenance` do compose, senão a flag de manutenção
# seria criada num caminho que o nginx não enxerga.
cd "$(dirname "$0")/.."

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

if [[ ! -f "/etc/letsencrypt/live/bluejus.com.br/fullchain.pem" || \
      ! -f "/etc/letsencrypt/live/bluejus.com.br/privkey.pem" ]]; then
  echo "❌ Certificados Let's Encrypt não encontrados em /etc/letsencrypt/live/bluejus.com.br/"
  echo "   Execute: certbot certonly --standalone -d bluejus.com.br -d www.bluejus.com.br"
  exit 1
fi

if [[ ! -f "/etc/letsencrypt/live/grupojusprime.tech/fullchain.pem" || \
      ! -f "/etc/letsencrypt/live/grupojusprime.tech/privkey.pem" ]]; then
  echo "❌ Certificados Let's Encrypt não encontrados em /etc/letsencrypt/live/grupojusprime.tech/"
  echo "   O nginx referencia esse cert (proxy do sistema co-hospedado)."
  echo "   Sem ele, o recreate do nginx derruba TAMBÉM o bluejus."
  echo "   Gere/restaure o cert antes de deployar."
  exit 1
fi

# ─── Atualiza código ───────────────────────────────────────────────────────────
echo "📦 Atualizando código do repositório..."
git pull

# ─── Entra em modo manutenção ──────────────────────────────────────────────────
# O nginx continua no ar e passa a servir a página de manutenção amigável (503)
# enquanto o php é reconstruído. Em caso de falha abaixo, a flag fica ligada de
# propósito (melhor a página de manutenção do que um app meio-migrado).
echo "🛠️  Ativando modo manutenção..."
mkdir -p nginx/maintenance
touch nginx/maintenance/maintenance.on

# ─── Recria apenas o php (nginx/db seguem no ar) ─────────────────────────────────
# Sem `down`: o `up -d --build` só recria containers cujo build/imagem mudou — o php
# (código novo). nginx e db ficam intactos, então não há "conexão recusada" e o app
# co-hospedado (grupojusprime.tech) não sofre blip.
echo "🐳 Reconstruindo e subindo containers (recria só o php)..."
$COMPOSE_CMD $COMPOSE_FILE $ENV_FILE up -d --build --remove-orphans

# ─── Reconecta o nginx à rede do sistema co-hospedado (condomínio) ───
# Em deploys normais o nginx NÃO é recriado (config inalterada), então este passo só
# confirma que já está conectado. No primeiro deploy após mudança no compose, o nginx
# é recriado e perde a conexão com a rede do condomínio — reconectar para o proxy_pass
# de grupojusprime.tech resolver condominio_app. Tolerante a falha: se a rede/condomínio
# não existir, o nginx segue servindo bluejus normalmente (grupojusprime cai em 502 até
# o condomínio subir).
echo "🔗 Reconectando nginx à rede do condomínio (se existir)..."
docker network connect condominio_condominio_net jusprime_nginx_prod 2>/dev/null \
  && echo "   ✅ conectado" \
  || echo "   ⚠️  rede condominio_condominio_net indisponível ou já conectado — seguindo"

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

# ─── Aguarda PHP-FPM ficar pronto ───────────────────────────────────────────────
# O healthcheck do container php fica `healthy` só quando a porta 9000 abre, o que
# ocorre após o entrypoint terminar cache:warmup + migrations. Esse é o sinal de que
# é seguro sair do modo manutenção.
echo "⏳ Aguardando PHP-FPM ficar pronto..."
for i in $(seq 1 90); do
  status="$(docker inspect -f '{{.State.Health.Status}}' jusprime_php_prod 2>/dev/null || echo starting)"
  if [[ "$status" == "healthy" ]]; then
    echo "✅ PHP pronto."
    break
  fi
  if [[ "$i" -eq 90 ]]; then
    echo "❌ PHP não ficou pronto a tempo (180s). Mantendo modo manutenção ativo."
    echo "   Investigue com: docker logs jusprime_php_prod"
    exit 1
  fi
  sleep 2
done

# ─── Migrations ───────────────────────────────────────────────────────────────
# Idempotente: o entrypoint do php já roda as migrations; aqui apenas confirmamos
# (ainda dentro do modo manutenção, para o usuário nunca ver um app meio-migrado).
echo "🗄️  Executando migrations..."
$COMPOSE_CMD $COMPOSE_FILE $ENV_FILE exec -T php \
  php /var/www/app/bin/console doctrine:migrations:migrate \
  --no-interaction --allow-no-migration

# ─── Sai do modo manutenção ─────────────────────────────────────────────────────
echo "✅ Desativando modo manutenção..."
rm -f nginx/maintenance/maintenance.on

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

# ─── Limpeza de lixo do Docker ────────────────────────────────────────────────
# Remove SOMENTE imagens dangling e build cache. NÃO toca volumes (db/uploads)
# nem imagens em uso. Evita acúmulo a cada deploy com --build.
echo "🧹 Limpando imagens dangling e build cache..."
docker image prune -f || true
docker builder prune -f || true

echo ""
echo "🚀 Deploy TLS concluído com sucesso."
echo "   🌐 https://bluejus.com.br"
echo "   🌐 https://bluejus.com.br"