#!/usr/bin/env bash
set -euo pipefail

# Roda sempre a partir da raiz do repositório, independente de onde foi chamado.
# Os caminhos relativos abaixo (.env.prod, docker-compose.prod.yml, nginx/maintenance)
# precisam casar com o `./nginx/maintenance` do compose, senão a flag de manutenção
# seria criada num caminho que o nginx não enxerga.
# Resolvido ANTES do cd: depois dele, `dirname "$0"` de uma chamada por caminho
# relativo aponta para o lugar errado e o `source` da lib morre.
DIR_SCRIPT="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR_SCRIPT/.."

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

# Lidas e CONFERIDAS aqui, antes de derrubar nada. Antes eram lidas só depois do modo
# manutenção: um .env.prod sem POSTGRES_USER matava o script (set -u) com o site já fora
# do ar. Ver scripts/lib/composer-auth.sh para a mesma tese aplicada à credencial.
source <(grep -E '^(POSTGRES_USER|POSTGRES_DB)=' .env.prod) || true
for var in POSTGRES_USER POSTGRES_DB; do
  if [[ -z "${!var:-}" ]]; then
    echo "❌ .env.prod não define $var — o deploy pararia no meio, com o site fora do ar."
    echo "   NADA foi alterado — o site continua no ar."
    exit 1
  fi
done

# Credencial do composer para o build. Validada ANTES de qualquer coisa: credencial
# inválida costumava passar batida aqui, e o deploy só quebrava depois de já ter
# derrubado o site. Detalhes do desenho em scripts/lib/composer-auth.sh.
# shellcheck source=lib/composer-auth.sh
source "$DIR_SCRIPT/lib/composer-auth.sh"
if ! validar_composer_auth ".composer-auth.json"; then
  echo "❌ Deploy abortado. O site NÃO saiu do ar."
  exit 1
fi

# Raiz dos certificados por variável: em produção é o caminho real; no teste
# (scripts/testar-deploy-guardas.sh) aponta para um sandbox, e é o que permite
# exercitar ESTE script — que é o que a produção de fato roda.
LETSENCRYPT_DIR="${LETSENCRYPT_DIR:-/etc/letsencrypt}"

if [[ ! -f "$LETSENCRYPT_DIR/live/bluejus.com.br/fullchain.pem" || \
      ! -f "$LETSENCRYPT_DIR/live/bluejus.com.br/privkey.pem" ]]; then
  echo "❌ Certificados Let's Encrypt não encontrados em $LETSENCRYPT_DIR/live/bluejus.com.br/"
  echo "   Execute: certbot certonly --standalone -d bluejus.com.br -d www.bluejus.com.br"
  exit 1
fi

if [[ ! -f "$LETSENCRYPT_DIR/live/grupojusprime.tech/fullchain.pem" || \
      ! -f "$LETSENCRYPT_DIR/live/grupojusprime.tech/privkey.pem" ]]; then
  echo "❌ Certificados Let's Encrypt não encontrados em $LETSENCRYPT_DIR/live/grupojusprime.tech/"
  echo "   O nginx referencia esse cert (proxy do sistema co-hospedado)."
  echo "   Sem ele, o recreate do nginx derruba TAMBÉM o bluejus."
  echo "   Gere/restaure o cert antes de deployar."
  exit 1
fi

# ─── Atualiza código ───────────────────────────────────────────────────────────
echo "📦 Atualizando código do repositório..."
git pull

# ─── Constrói a imagem ANTES de derrubar nada ──────────────────────────────────
# A ORDEM É O CONSERTO. Antes, a manutenção era ligada primeiro e o build vinha depois:
# qualquer build que falhasse (pane do GitHub, token ruim, warmup estourando memória)
# deixava o site fora do ar sem nada para pôr no lugar. Em 17/08/2026 foram ~40 minutos
# de manutenção em builds que nunca chegaram a produzir imagem.
# Enquanto este passo roda, o site segue no ar servindo a versão ANTERIOR.
echo "🏗️  Construindo a imagem nova (o site continua no ar)..."
if ! $COMPOSE_CMD $COMPOSE_FILE $ENV_FILE build; then
  echo "❌ Build falhou. Nenhum container foi tocado e o site continua no ar na versão anterior."
  exit 1
fi

# ─── Entra em modo manutenção ──────────────────────────────────────────────────
# Só agora, com a imagem pronta na mão. A janela de manutenção passa a ser só a troca
# de container + migrations. Em caso de falha DAQUI PARA BAIXO a flag fica ligada de
# propósito (melhor a página de manutenção do que um app meio-migrado).
echo "🛠️  Ativando modo manutenção..."
mkdir -p nginx/maintenance
touch nginx/maintenance/maintenance.on

# ─── Recria apenas o php (nginx/db seguem no ar) ─────────────────────────────────
# Sem `down` e sem `--build` (a imagem já está pronta): só recria containers cuja
# imagem mudou — o php. nginx e db ficam intactos, então não há "conexão recusada" e
# o app co-hospedado (grupojusprime.tech) não sofre blip.
echo "🐳 Subindo containers (recria só o php)..."
$COMPOSE_CMD $COMPOSE_FILE $ENV_FILE up -d --remove-orphans

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
# Remove SOMENTE imagens dangling. NÃO toca volumes (db/uploads) nem imagens em uso.
echo "🧹 Limpando imagens dangling..."
docker image prune -f || true

# ⚠️ AQUI MORAVA O `docker builder prune -f` CEGO, E ELE ERA A CAUSA DE O DEPLOY
# REBAIXAR AS 122 DEPENDÊNCIAS TODA VEZ.
# Medido em 18/08/2026: o prune sem filtro zera 100% do cache de build (1,719 GB → 0 B),
# inclusive o cache mount do composer, e ter a imagem no store NÃO protege nada. Com o
# cache intacto o passo do composer sai como CACHED (build inteiro em ~2 s); depois do
# prune ele refaz tudo, apt-get incluído (~235 s) e rebaixando os 122 pacotes do GitHub.
# Era isso que deixava todo deploy refém de o GitHub estar de pé.
#
# A poda continua existindo — o disco da VPS é apertado —, mas com TETO em vez de tudo:
# o cache do deploy que acabou de rodar sobrevive para o próximo.
echo "🧹 Podando cache de build com teto (mantém o cache do deploy atual)..."
# 4 GB: uma linhagem de build completa mediu 1,72 GB, então o teto guarda a atual com
# folga para a próxima. Fica pequeno de propósito — o disco da VPS é apertado (95 GB,
# 68,6% usados em 18/08). Ajustável por variável de ambiente se o número mudar.
TETO_CACHE_BUILD_BYTES=${TETO_CACHE_BUILD_BYTES:-$((4 * 1024 * 1024 * 1024))}   # 4 GB
if docker builder prune --help 2>&1 | grep -q -- '--max-used-space'; then
  docker builder prune -f --max-used-space "$TETO_CACHE_BUILD_BYTES" || true
else
  # Docker antigo, sem teto por tamanho. Poda por idade — que limita ACÚMULO NO TEMPO,
  # não disco: uma rajada de deploys no mesmo dia cabe inteira dentro da janela. Por
  # isso a janela é curta (48h) e o uso real é medido e mostrado logo abaixo, em vez de
  # fingir que há teto.
  echo "   ⚠️  docker sem --max-used-space: podando por IDADE (48h), sem teto por tamanho."
  echo "      Considere atualizar o docker da VPS para a poda com teto."
  docker builder prune -f --filter until=48h || true
fi

uso_cache="$(docker builder du 2>/dev/null | awk -F'\t' '/^Total:/ {print $NF}')"
echo "   cache de build agora: ${uso_cache:-desconhecido} (teto pedido: $((TETO_CACHE_BUILD_BYTES / 1024 / 1024 / 1024)) GB)"
echo "   espaço livre no disco:"; df -h / | tail -1 | sed 's/^/      /' 

echo ""
echo "🚀 Deploy TLS concluído com sucesso."
echo "   🌐 https://bluejus.com.br"
echo "   🌐 https://bluejus.com.br"