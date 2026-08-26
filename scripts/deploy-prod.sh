#!/usr/bin/env bash
set -euo pipefail

# Roda sempre a partir da raiz do repositório (paths relativos precisam casar com
# o `./nginx/maintenance` do compose).
# Resolvido ANTES do cd: depois dele, `dirname "$0"` de uma chamada por caminho
# relativo aponta para o lugar errado e o `source` da lib morre.
DIR_SCRIPT="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR_SCRIPT/.."

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

# As variáveis são lidas e CONFERIDAS AQUI, não lá embaixo. `--env-file` alimenta o
# compose, não este shell; sem isso o ${POSTGRES_USER} do pg_isready estoura em
# "unbound variable" (set -u) — e estourava JÁ COM O MODO MANUTENÇÃO LIGADO, que é
# exatamente o que esta frente existe para impedir. Conferir antes de derrubar nada.
source <(grep -E '^(POSTGRES_USER|POSTGRES_DB)=' .env.prod) || true
for var in POSTGRES_USER POSTGRES_DB; do
  if [[ -z "${!var:-}" ]]; then
    echo "Arquivo .env.prod não define $var — o deploy pararia no meio, com o site fora do ar."
    echo "NADA foi alterado — o site continua no ar."
    exit 1
  fi
done

# Credencial do composer para o build. Validada ANTES de qualquer coisa: credencial
# inválida costumava passar batida aqui, e o deploy só quebrava depois de já ter
# derrubado o site. Detalhes do desenho em scripts/lib/composer-auth.sh.
# shellcheck source=lib/composer-auth.sh
source "$DIR_SCRIPT/lib/composer-auth.sh"
if ! validar_composer_auth ".composer-auth.json"; then
  echo "Deploy abortado. O site NÃO saiu do ar."
  exit 1
fi

# O BUILD VEM ANTES DO MODO MANUTENÇÃO — e é essa ordem que importa.
# Antes era o contrário: ligava a manutenção e só então buildava, então qualquer build
# que falhasse (pane do GitHub, token ruim, warmup estourando memória) deixava o site
# fora do ar sem nada para pôr no lugar. Em 17/08/2026 isso custou ~40 minutos de
# manutenção em builds que nunca chegaram a produzir imagem.
# Enquanto este passo roda, o site segue no ar servindo a versão ANTERIOR.
echo "Construindo a imagem nova (o site continua no ar)..."
if ! $COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod build; then
  echo "Build falhou. Nenhum container foi tocado e o site continua no ar na versão anterior."
  exit 1
fi

# Só agora, com a imagem pronta na mão, o site sai do ar. A janela de manutenção
# passa a ser só a troca de container + migrations. Em caso de falha DAQUI PARA
# BAIXO a flag fica ligada de propósito (melhor manutenção do que app meio-migrado).
echo "Ativando modo manutenção..."
mkdir -p nginx/maintenance
touch nginx/maintenance/maintenance.on

# Sem `down`: recria só o php (código novo). nginx/db ficam no ar — sem "conexão
# recusada" e sem derrubar o app co-hospedado. --remove-orphans limpa containers
# fora do compose sem precisar derrubar os serviços ativos.
$COMPOSE_CMD -f docker-compose.prod.yml --env-file .env.prod up -d --remove-orphans

# ─── Faz o nginx reler a config ────────────────────────────────────────────────
# O container do nginx quase nunca é recriado (a imagem dele não muda), então sem este
# passo ele segue com a config carregada no último start. Em 19/08/2026 isso deixou a
# produção 8 semanas servindo uma config de 27/06: CSS/JS ainda com `immutable`/30 dias,
# sendo que o arquivo tinha `no-cache` desde 17/07. A config agora é montada por PASTA
# (o mount de arquivo único prendia o inode e o container nem via o arquivo novo); o
# reload é o outro metade do conserto — é ele que faz o nginx passar a usar o que vê.
# Tolerante de propósito: config inválida NÃO derruba o site (o nginx continua com a
# anterior), mas o aviso é barulhento — silêncio aqui foi o que escondeu o problema.
echo "🔁 Recarregando a config do nginx..."
if docker exec jusprime_nginx_prod nginx -t >/dev/null 2>&1; then
  docker exec jusprime_nginx_prod nginx -s reload >/dev/null 2>&1 \
    && echo "   ✅ config recarregada" \
    || echo "   ⚠️  o reload falhou — o site segue com a config anterior"
else
  echo "   ⚠️  ATENÇÃO: 'nginx -t' REPROVOU a config — reload NÃO executado."
  echo "      O site continua no ar com a config anterior. Para ver o erro:"
  echo "      docker exec jusprime_nginx_prod nginx -t"
fi

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