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

# Credencial do composer para o build. Validada ANTES de qualquer coisa: credencial
# inválida costumava passar batida aqui, e o deploy só quebrava depois de já ter
# derrubado o site. Detalhes do desenho em scripts/lib/composer-auth.sh.
# shellcheck source=lib/composer-auth.sh
source "$(dirname "$0")/lib/composer-auth.sh"
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

# `--env-file` alimenta o compose, NÃO o shell deste script. Sem esta linha o
# ${POSTGRES_USER} abaixo estoura em "unbound variable" (set -u) — e estouraria já
# com o modo manutenção ligado. O deploy-prod-tls.sh sempre teve esta linha; este
# não tinha. Encontrado por scripts/testar-deploy-guardas.sh.
source <(grep -E '^(POSTGRES_USER|POSTGRES_DB)=' .env.prod)

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