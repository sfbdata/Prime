#!/bin/bash
# Restaura o estado do user E2E após testes:
#   1. Reativa user_tenant.is_active = true
#   2. Restaura user_profiles (nome_completo, status, foto_url) do baseline
#
# Requer: jq  →  sudo apt install jq
# Use após falhas de teste ou após rodar a suite completa (perfil.spec.js altera esses dados).
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASELINE_FILE="$SCRIPT_DIR/.e2e-baseline.json"

# --- Verificação de dependência ---
if ! command -v jq &>/dev/null; then
    echo "ERRO: jq não instalado. Instale com: sudo apt install jq" >&2
    exit 1
fi

echo "1. Restaurando user_tenant.is_active..."
docker exec jusprime_db_dev psql -U symfony -d saas -c \
    "UPDATE user_tenant SET is_active = true
     WHERE user_id = (SELECT id FROM \"user\" WHERE email = 'e2e@jusprime.local');"
echo "   OK."

if [ ! -f "$BASELINE_FILE" ]; then
    echo "AVISO: $BASELINE_FILE não encontrado."
    echo "Execute capture-e2e-baseline.sh antes dos testes para registrar o estado inicial."
    echo "Vínculo restaurado, mas perfil NÃO restaurado."
    exit 0
fi

echo "2. Restaurando user_profiles do baseline..."

NOME=$(jq -r '.nome_completo // ""' "$BASELINE_FILE")
STATUS=$(jq -r '.status // ""' "$BASELINE_FILE")
FOTO=$(jq -r '.foto_url // ""' "$BASELINE_FILE")

docker exec -i jusprime_db_dev psql -U symfony -d saas <<SQL
UPDATE user_profiles
SET nome_completo = \$\$${NOME}\$\$,
    status        = \$\$${STATUS}\$\$,
    foto_url      = \$\$${FOTO}\$\$
WHERE user_id = (SELECT id FROM "user" WHERE email = 'e2e@jusprime.local');
SQL

echo "   OK."
echo "Restauração concluída."
