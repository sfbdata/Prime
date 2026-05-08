#!/bin/bash
# Captura o estado inicial do user E2E (user_profiles) antes de rodar a suite.
# Salva em .e2e-baseline.json (gitignored).
# Execute antes de cada rodada completa dos testes.
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASELINE_FILE="$SCRIPT_DIR/.e2e-baseline.json"

JSON=$(docker exec jusprime_db_dev psql -U symfony -d saas -t -A -c \
    "SELECT row_to_json(r) FROM (
        SELECT up.nome_completo, up.status, up.foto_url
        FROM user_profiles up
        JOIN \"user\" u ON u.id = up.user_id
        WHERE u.email = 'e2e@jusprime.local'
    ) r;")

if [ -z "$JSON" ]; then
    echo "ERRO: E2E user (e2e@jusprime.local) não encontrado no banco." >&2
    echo "Rode a migration primeiro: docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:migrations:migrate --no-interaction'" >&2
    exit 1
fi

echo "$JSON" > "$BASELINE_FILE"
echo "Baseline salvo em: $BASELINE_FILE"
cat "$BASELINE_FILE"
